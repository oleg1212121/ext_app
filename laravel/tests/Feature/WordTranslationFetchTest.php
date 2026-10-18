<?php

use App\Classes\WordTranslationFetchService;
use App\Classes\WordTranslations\WordTranslationResolver;
use App\Jobs\FetchWordTranslations;
use App\Models\Language;
use App\Models\User;
use App\Models\Word;
use App\Models\WordTranslation;
use App\Models\WordTranslationFetch;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// enableTranslationProviders(), nativeRuUser() and ruLanguageId() live in
// tests/Pest.php — parallel workers don't load this file for other tests.

it('queues a translation fetch when the popup word has no translations', function () {
    Queue::fake();
    enableTranslationProviders();
    $user = nativeRuUser();
    $word = createWord('en', 'cat', 'noun');

    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $word->id]))
        ->assertOk();

    Queue::assertPushed(FetchWordTranslations::class, fn (FetchWordTranslations $job) => $job->wordId === $word->id
        && $job->targetLanguageId === ruLanguageId());

    expect(WordTranslationFetch::query()->where('word_id', $word->id)->where('status', 'pending')->exists())->toBeTrue();
});

it('does not queue a fetch when the word already has a translation', function () {
    Queue::fake();
    enableTranslationProviders();
    $user = nativeRuUser();

    $word = createWord('en', 'cat', 'noun');
    WordTranslation::link($word->id, createWord('ru', 'кот', 'noun')->id);

    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $word->id]))
        ->assertOk();

    Queue::assertNotPushed(FetchWordTranslations::class);
});

it('does not queue a fetch into the word own language', function () {
    Queue::fake();
    enableTranslationProviders();

    $user = User::factory()->create();
    $user->settings()->updateOrCreate([], [
        'native_language_id' => Language::query()->where('code', 'en')->value('id'),
    ]);

    $word = createWord('en', 'cat', 'noun');

    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $word->id]))
        ->assertOk();

    Queue::assertNotPushed(FetchWordTranslations::class);
});

it('does not queue a fetch when no provider is configured', function () {
    Queue::fake();
    $user = nativeRuUser();
    $word = createWord('en', 'cat', 'noun');

    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $word->id]))
        ->assertOk();

    Queue::assertNotPushed(FetchWordTranslations::class);
});

it('never re-checks an excluded word', function () {
    Queue::fake();
    enableTranslationProviders();
    $user = nativeRuUser();
    $word = createWord('en', 'cat', 'noun');

    WordTranslationFetch::query()->create([
        'word_id' => $word->id,
        'target_language_id' => ruLanguageId(),
        'status' => WordTranslationFetch::STATUS_EMPTY,
    ]);

    $this->actingAs($user)
        ->getJson(route('words.show', ['word' => $word->id]))
        ->assertOk();

    Queue::assertNotPushed(FetchWordTranslations::class);
});

it('links matching candidates to existing target words and creates no rows', function () {
    enableTranslationProviders();

    Http::fake(['yandex.test/*' => Http::response([
        'def' => [[
            'pos' => 'noun',
            'text' => 'cat',
            'tr' => [
                ['text' => "кот\u{0301}"],
                ['text' => "ко\u{0301}шка"],
                ['text' => 'see also, cat'], // sentence punctuation — unusable
            ],
        ]],
    ])]);

    $word = createWord('en', 'cat', 'noun');
    $kot = createWord('ru', 'кот', 'noun');
    $koshka = createWord('ru', 'кошка', 'noun');

    (new FetchWordTranslations($word->id, ruLanguageId()))->handle(app(WordTranslationResolver::class));

    // The no-creation invariant: the job links the target language's
    // EXISTING words — it never adds one, whatever the provider answers.
    expect(Word::query()->where('language_id', ruLanguageId())->count())->toBe(2)
        ->and(WordTranslation::isLinked($word->id, $kot->id))->toBeTrue()
        ->and(WordTranslation::isLinked($word->id, $koshka->id))->toBeTrue();

    $record = WordTranslationFetch::query()->sole();

    expect($record->status)->toBe(WordTranslationFetch::STATUS_SUCCEEDED)
        ->and($record->provider)->toBe('yandex')
        ->and($record->attempts)->toBe(1)
        ->and($record->last_attempted_at)->not->toBeNull()
        ->and($record->linked_count)->toBe(2)
        ->and($record->staged_count)->toBe(0);
});

it('strips stress marks at the job layer even when a provider skips the strip', function () {
    // A mock, not the real chain: the yandex/google providers strip already —
    // the job must not depend on a future provider keeping that promise.
    $resolver = mock(WordTranslationResolver::class);
    $resolver->shouldReceive('fetch')->once()->andReturn([
        'provider' => 'future',
        'candidates' => [
            ['text' => "ко\u{0301}т", 'pos' => 'noun'], // stressed, unstored
            ['text' => "\u{0301}", 'pos' => 'noun'], // marks only — nothing survives the strip
        ],
    ]);
    $this->app->instance(WordTranslationResolver::class, $resolver);

    $word = createWord('en', 'cat', 'noun');
    $kot = createWord('ru', 'кот', 'noun');

    (new FetchWordTranslations($word->id, ruLanguageId()))->handle(app(WordTranslationResolver::class));

    expect(Word::query()->where('language_id', ruLanguageId())->count())->toBe(1)
        ->and(WordTranslation::isLinked($word->id, $kot->id))->toBeTrue()
        ->and($word->refresh()->translations)->toBeNull()
        ->and(WordTranslationFetch::query()->sole()->linked_count)->toBe(1);
});

it('stages unmatched candidates in the source word translations JSON', function () {
    enableTranslationProviders();

    Http::fake(['yandex.test/*' => Http::response([
        'def' => [[
            'pos' => 'noun',
            'tr' => [
                ['text' => 'кот'], // matches the existing ru noun
                ['text' => 'собака'], // no ru row — stays staged
            ],
        ]],
    ])]);

    $word = createWord('en', 'cat', 'noun', ['translations' => ['хвостатый друг']]);
    $kot = createWord('ru', 'кот', 'noun');

    (new FetchWordTranslations($word->id, ruLanguageId()))->handle(app(WordTranslationResolver::class));

    expect(Word::query()->where('language_id', ruLanguageId())->count())->toBe(1)
        ->and(WordTranslation::isLinked($word->id, $kot->id))->toBeTrue()
        // The merge unions into the existing staged JSON, never clobbers it.
        ->and($word->refresh()->translations)->toBe(['хвостатый друг', 'собака'])
        ->and(WordTranslationFetch::query()->sole()->staged_count)->toBe(1);
});

it('links a multi-word candidate through the phrase class and keeps single-word pos matching', function () {
    enableTranslationProviders();

    Http::fake(['yandex.test/*' => Http::response([
        'def' => [[
            'pos' => 'verb',
            'tr' => [
                ['text' => 'уступать дорогу'], // multi-word: wants the phrase class, never the provider pos
                ['text' => 'давать'],
            ],
        ]],
    ])]);

    $word = createWord('en', 'give way', 'verb');
    $phrase = createWord('ru', 'уступать дорогу', 'phrase');
    $davat = createWord('ru', 'давать', 'verb');

    (new FetchWordTranslations($word->id, ruLanguageId()))->handle(app(WordTranslationResolver::class));

    expect(Word::query()->where('language_id', ruLanguageId())->count())->toBe(2)
        ->and(WordTranslation::isLinked($word->id, $phrase->id))->toBeTrue()
        ->and(WordTranslation::isLinked($word->id, $davat->id))->toBeTrue()
        ->and(WordTranslationFetch::query()->sole()->linked_count)->toBe(2);
});

it('falls back to google and links the pos-less candidate by l_word alone', function () {
    enableTranslationProviders();

    Http::fake([
        'yandex.test/*' => Http::response(['def' => []]),
        'google.test/*' => Http::response(['data' => ['translations' => [['translatedText' => 'кот']]]]),
    ]);

    $word = createWord('en', 'cat', 'noun');
    $kot = createWord('ru', 'кот', 'verb'); // a class mismatch must not block the link

    (new FetchWordTranslations($word->id, ruLanguageId()))->handle(app(WordTranslationResolver::class));

    expect(Word::query()->where('language_id', ruLanguageId())->count())->toBe(1)
        ->and(WordTranslation::isLinked($word->id, $kot->id))->toBeTrue()
        ->and(WordTranslationFetch::query()->sole()->provider)->toBe('google');
});

it('is idempotent — a rerun creates nothing new', function () {
    enableTranslationProviders();

    Http::fake(['yandex.test/*' => Http::response([
        'def' => [['pos' => 'noun', 'tr' => [['text' => 'кот']]]],
    ])]);

    $word = createWord('en', 'cat', 'noun');
    createWord('ru', 'кот', 'noun');

    $job = new FetchWordTranslations($word->id, ruLanguageId());
    $job->handle(app(WordTranslationResolver::class));
    $job->handle(app(WordTranslationResolver::class));

    expect(Word::query()->where('language_id', ruLanguageId())->count())->toBe(1)
        ->and(WordTranslation::query()->count())->toBe(1)
        ->and(WordTranslationFetch::query()->count())->toBe(1);
});

it('re-stages without duplicating when a failed fetch is reprocessed', function () {
    enableTranslationProviders();

    Http::fake(['yandex.test/*' => Http::response([
        'def' => [['pos' => 'noun', 'tr' => [['text' => 'собака']]]],
    ])]);

    $word = createWord('en', 'cat', 'noun');

    WordTranslationFetch::query()->create([
        'word_id' => $word->id,
        'target_language_id' => ruLanguageId(),
        'status' => WordTranslationFetch::STATUS_FAILED,
        'attempts' => 1,
    ]);

    $job = new FetchWordTranslations($word->id, ruLanguageId());
    $job->handle(app(WordTranslationResolver::class));

    // failed is not a terminal state for the job — reset the row so the
    // second handle() really reprocesses instead of early-returning.
    WordTranslationFetch::query()->sole()->forceFill(['status' => WordTranslationFetch::STATUS_FAILED])->save();

    $job->handle(app(WordTranslationResolver::class));

    expect($word->refresh()->translations)->toBe(['собака'])
        ->and(WordTranslation::query()->count())->toBe(0)
        ->and(Word::query()->where('language_id', ruLanguageId())->count())->toBe(0)
        ->and(WordTranslationFetch::query()->sole()->staged_count)->toBe(1);
});

it('records the exclusion when every provider finds nothing', function () {
    config([
        'services.yandex_translate.key' => 'yandex-test-key',
        'services.yandex_translate.url' => 'https://yandex.test/translate/v2',
        'services.google_translate.key' => null,
    ]);

    Http::fake(['yandex.test/*' => Http::response(['def' => []])]);

    $word = createWord('en', 'cat', 'noun');
    $ru = Language::query()->where('code', 'ru')->firstOrFail();

    (new FetchWordTranslations($word->id, $ru->id))->handle(app(WordTranslationResolver::class));

    $record = WordTranslationFetch::query()->sole();

    expect($record->status)->toBe(WordTranslationFetch::STATUS_EMPTY)
        ->and(Word::query()->where('language_id', $ru->id)->count())->toBe(0)
        ->and($record->linked_count)->toBe(0)
        ->and($record->staged_count)->toBe(0);

    // The exclusion is permanent: no provider lookup is ever queued again.
    Queue::fake();

    expect(app(WordTranslationFetchService::class)->dispatchIfEligible($word, $ru))->toBeFalse();

    Queue::assertNotPushed(FetchWordTranslations::class);
});

it('records the exclusion when every candidate is unusable', function () {
    config([
        'services.yandex_translate.key' => 'yandex-test-key',
        'services.yandex_translate.url' => 'https://yandex.test/translate/v2',
        'services.google_translate.key' => null,
    ]);

    Http::fake(['yandex.test/*' => Http::response([
        'def' => [['pos' => 'noun', 'tr' => [['text' => 'see also, cat']]]],
    ])]);

    $word = createWord('en', 'cat', 'noun');

    (new FetchWordTranslations($word->id, ruLanguageId()))->handle(app(WordTranslationResolver::class));

    expect(WordTranslationFetch::query()->sole()->status)->toBe(WordTranslationFetch::STATUS_EMPTY)
        ->and($word->refresh()->translations)->toBeNull()
        ->and(Word::query()->where('language_id', ruLanguageId())->count())->toBe(0);
});

it('does not re-dispatch a word whose candidates were all staged', function () {
    config([
        'services.yandex_translate.key' => 'yandex-test-key',
        'services.yandex_translate.url' => 'https://yandex.test/translate/v2',
        'services.google_translate.key' => null,
    ]);

    Http::fake(['yandex.test/*' => Http::response([
        'def' => [['pos' => 'noun', 'tr' => [['text' => 'собака']]]],
    ])]);

    $word = createWord('en', 'cat', 'noun');
    $ru = Language::query()->where('code', 'ru')->firstOrFail();

    (new FetchWordTranslations($word->id, $ru->id))->handle(app(WordTranslationResolver::class));

    expect(WordTranslationFetch::query()->sole()->status)->toBe(WordTranslationFetch::STATUS_SUCCEEDED);

    // Nothing linked, but the ledger's succeeded status still excludes the
    // pair — staged candidates wait for the link pass, not a refetch.
    Queue::fake();

    expect(app(WordTranslationFetchService::class)->dispatchIfEligible($word, $ru))->toBeFalse();

    Queue::assertNotPushed(FetchWordTranslations::class);
});

it('marks the record failed when providers fail and blocks re-dispatch during the cooldown', function () {
    config([
        'services.yandex_translate.key' => 'yandex-test-key',
        'services.yandex_translate.url' => 'https://yandex.test/translate/v2',
        'services.google_translate.key' => null,
    ]);

    Http::fake(['yandex.test/*' => Http::response(['message' => 'boom'], 500)]);

    $word = createWord('en', 'cat', 'noun');
    $ru = Language::query()->where('code', 'ru')->firstOrFail();

    expect(fn () => (new FetchWordTranslations($word->id, $ru->id))->handle(app(WordTranslationResolver::class)))->toThrow(RuntimeException::class);

    $record = WordTranslationFetch::query()->sole();

    expect($record->status)->toBe(WordTranslationFetch::STATUS_FAILED)
        ->and($record->attempts)->toBe(1);

    Queue::fake();

    expect(app(WordTranslationFetchService::class)->dispatchIfEligible($word, $ru))->toBeFalse();

    Queue::assertNotPushed(FetchWordTranslations::class);
});

it('re-dispatches a failed lookup after the cooldown', function () {
    enableTranslationProviders();

    $word = createWord('en', 'cat', 'noun');
    $ru = Language::query()->where('code', 'ru')->firstOrFail();

    WordTranslationFetch::query()->create([
        'word_id' => $word->id,
        'target_language_id' => $ru->id,
        'status' => WordTranslationFetch::STATUS_FAILED,
        'attempts' => 1,
        'last_attempted_at' => now()->subHours(25),
    ]);

    Queue::fake();

    expect(app(WordTranslationFetchService::class)->dispatchIfEligible($word, $ru))->toBeTrue();

    Queue::assertPushed(FetchWordTranslations::class);
});
