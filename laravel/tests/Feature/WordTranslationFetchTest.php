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

it('creates target words, links translations and records success', function () {
    enableTranslationProviders();

    Http::fake(['yandex.test/*' => Http::response([
        'def' => [[
            'pos' => 'noun',
            'text' => 'cat',
            'tr' => [
                ['text' => "кот\u{0301}"],
                ['text' => 'ко́шка'],
                ['text' => 'see also, cat'], // sentence punctuation — unusable
            ],
        ]],
    ])]);

    $word = createWord('en', 'cat', 'noun');

    (new FetchWordTranslations($word->id, ruLanguageId()))->handle(app(WordTranslationResolver::class));

    $kot = Word::query()->where('language_id', ruLanguageId())->where('word', 'кот')->firstOrFail();
    $koshka = Word::query()->where('language_id', ruLanguageId())->where('l_word', 'кошка')->firstOrFail();

    expect($kot->l_word)->toBe('кот')
        ->and($kot->wordClass->slug)->toBe('noun')
        ->and($koshka->wordClass->slug)->toBe('noun')
        ->and(Word::query()->where('language_id', ruLanguageId())->count())->toBe(2)
        ->and(WordTranslation::isLinked($word->id, $kot->id))->toBeTrue()
        ->and(WordTranslation::isLinked($word->id, $koshka->id))->toBeTrue();

    $record = WordTranslationFetch::query()->sole();

    expect($record->status)->toBe(WordTranslationFetch::STATUS_SUCCEEDED)
        ->and($record->provider)->toBe('yandex')
        ->and($record->attempts)->toBe(1)
        ->and($record->last_attempted_at)->not->toBeNull();
});

it('is idempotent — a rerun creates nothing new', function () {
    enableTranslationProviders();

    Http::fake(['yandex.test/*' => Http::response([
        'def' => [['pos' => 'noun', 'tr' => [['text' => 'кот']]]],
    ])]);

    $word = createWord('en', 'cat', 'noun');

    $job = new FetchWordTranslations($word->id, ruLanguageId());
    $job->handle(app(WordTranslationResolver::class));
    $job->handle(app(WordTranslationResolver::class));

    expect(Word::query()->where('language_id', ruLanguageId())->count())->toBe(1)
        ->and(WordTranslation::query()->count())->toBe(1)
        ->and(WordTranslationFetch::query()->count())->toBe(1);
});

it('falls back to google and uses the source word class for pos-less candidates', function () {
    enableTranslationProviders();

    Http::fake([
        'yandex.test/*' => Http::response(['def' => []]),
        'google.test/*' => Http::response(['data' => ['translations' => [['translatedText' => 'кот']]]]),
    ]);

    $word = createWord('en', 'cat', 'noun');

    (new FetchWordTranslations($word->id, ruLanguageId()))->handle(app(WordTranslationResolver::class));

    $kot = Word::query()->where('language_id', ruLanguageId())->where('word', 'кот')->firstOrFail();

    expect($kot->wordClass->slug)->toBe('noun')
        ->and(WordTranslation::isLinked($word->id, $kot->id))->toBeTrue()
        ->and(WordTranslationFetch::query()->sole()->provider)->toBe('google');
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

    expect(WordTranslationFetch::query()->sole()->status)->toBe(WordTranslationFetch::STATUS_EMPTY)
        ->and(Word::query()->where('language_id', $ru->id)->count())->toBe(0);

    // The exclusion is permanent: no provider lookup is ever queued again.
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
