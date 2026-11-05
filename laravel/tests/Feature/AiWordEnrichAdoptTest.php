<?php

use App\Classes\AIModelResolver;
use App\Classes\WordAiEnrichmentService;
use App\Exceptions\AiProviderException;
use App\Jobs\FetchWordAiEnrichment;
use App\Models\Definition;
use App\Models\Form;
use App\Models\Language;
use App\Models\TranscriptionType;
use App\Models\User;
use App\Models\Word;
use App\Models\WordAiFetch;
use App\Support\WordLookupKey;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

const ADOPT_MODEL_MOCK = ['id' => 7, 'key' => 'openrouter:google/gemini-3-flash-preview', 'label' => 'Gemini Flash'];

/**
 * The adoption-path base: the asking user's native language Russian and one
 * EXISTING ru word («банк») the AI's translation candidate can link to — the
 * learning-language word under test is deliberately absent (the missing
 * popup's whole premise). The asker is an approved admin
 * (word-popup-overhaul ticket 06). Helper names differ from
 * AiWordEnrichEndpointTest's on purpose: parallel workers may load both
 * files into one process.
 */
function createAdoptFixture(): array
{
    createWordClasses();
    $ruWord = createWord('ru', 'банк', 'noun');
    TranscriptionType::query()->create([
        'language_id' => Language::query()->where('code', 'en')->value('id'),
        'slug' => 'ipa',
        'title' => 'IPA',
    ]);
    $user = adminRuAdopter();

    return ['ruWord' => $ruWord, 'user' => $user];
}

/**
 * The approved-admin asker with the Russian native language (helper name
 * differs from AiWordEnrichEndpointTest's on purpose).
 */
function adminRuAdopter(): User
{
    createLanguages();

    $user = User::factory()->admin()->create();
    $user->settings()->updateOrCreate([], [
        'native_language_id' => Language::query()->where('code', 'ru')->value('id'),
    ]);

    return $user;
}

function adoptReply(): string
{
    return json_encode([
        'translations' => ['банк', 'прибанок'],
        'definitions' => ['A financial institution.'],
        'transcriptions' => ['bæŋk'],
        'examples' => ['He robbed the bank.'],
        'etymologies' => ['From Italian banca.'],
    ]);
}

/**
 * The dispatch-time mock (Queue::fake): the click resolves the model + key
 * against the session user and must never reach the provider — the ask
 * lives in the queued job (word-popup-overhaul ticket 06).
 */
function mockResolverForAdoptDispatch(): MockInterface
{
    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('resolveExplanationModel')->withNoArgs()->andReturn(ADOPT_MODEL_MOCK);
    // The controller splits the ledger's provider/model through the real
    // resolver API — the mock mirrors parseModelString's split (first
    // colon).
    $mock->shouldReceive('parseModelString')->andReturnUsing(function (string $key): array {
        $colon = strpos($key, ':');

        return [
            'provider' => $colon === false ? $key : substr($key, 0, $colon),
            'model' => $colon === false ? '' : substr($key, $colon + 1),
        ];
    });
    $mock->shouldReceive('ask')->never();
    app()->instance(AIModelResolver::class, $mock);

    return $mock;
}

/**
 * The job-time mock: the queued job resolves the model against the CARRIED
 * user (the explicit-user seam) and runs the provider ask. One mock serves
 * dispatch + handle() in a combined flow test (the route caches its
 * controller, so a mid-test rebind would never reach it); $captured ends up
 * holding the LAST ask's question.
 */
function mockResolverForAdoptJob(?string $reply = null, ?string &$captured = null): MockInterface
{
    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('resolveExplanationModel')->andReturn(ADOPT_MODEL_MOCK);
    $mock->shouldReceive('parseModelString')->andReturnUsing(function (string $key): array {
        $colon = strpos($key, ':');

        return [
            'provider' => $colon === false ? $key : substr($key, 0, $colon),
            'model' => $colon === false ? '' : substr($key, $colon + 1),
        ];
    });
    $mock->shouldReceive('ask')->andReturnUsing(function ($model, $instruction, $question, $user = null) use ($reply, &$captured) {
        $captured = ['model' => $model, 'instruction' => $instruction, 'question' => $question];

        return $reply ?? adoptReply();
    });
    app()->instance(AIModelResolver::class, $mock);

    return $mock;
}

it('adopts on ask: the dispatch mints one unknown-class word with the folded key and its lemma form, queues the job, and the job enriches it', function () {
    Http::fake();
    Queue::fake();
    $fixture = createAdoptFixture();
    $captured = null;
    mockResolverForAdoptJob(null, $captured);

    $response = $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['surface' => 'bomb-hit', 'language_code' => 'en']);

    // The click answers the minimal queued marker immediately — the mint
    // itself stayed synchronous.
    $response->assertOk();
    expect($response->json())->toBe(['queued' => true]);
    Queue::assertPushed(FetchWordAiEnrichment::class, fn (FetchWordAiEnrichment $job) => $job->userId === $fixture['user']->id);

    // Exactly one word minted, following the import creator hygiene: plain
    // display word from the raw surface, the WordLookupKey key, unknown
    // class, and the word carrying itself as its lemma form.
    $word = Word::query()->where('language_id', Language::query()->where('code', 'en')->value('id'))->sole();
    Queue::assertPushed(FetchWordAiEnrichment::class, fn (FetchWordAiEnrichment $job) => $job->wordId === $word->id);
    expect($word->word)->toBe('bomb-hit');
    expect($word->l_word)->toBe('bomb-hit');
    expect($word->wordClass->slug)->toBe('unknown');
    $forms = Form::query()->where('word_id', $word->id)->get();
    expect($forms)->toHaveCount(1);
    expect($forms[0]->form)->toBe('bomb-hit');
    expect($forms[0]->l_word)->toBe('bomb-hit');

    // The dispatch-time ledger gate's pending row keyed on the minted word.
    $ledger = WordAiFetch::query()->where('word_id', $word->id)->firstOrFail();
    expect($ledger->status)->toBe('pending');
    expect($ledger->attempts)->toBe(1);

    // The queued job enriches it: content rows carry the ai provenance and
    // the translations link against the EXISTING ru row only (link-only).
    (new FetchWordAiEnrichment($word->id, $fixture['user']->id))->handle(app(AIModelResolver::class), app(WordAiEnrichmentService::class));

    expect(Definition::query()->where('word_id', $word->id)->where('source', 'ai')->count())->toBe(1);
    expect($word->fresh()->translations)->toContain('прибанок');

    // The ledger finalizes the ADOPTED word's ask on success.
    $ledger = $ledger->fresh();
    expect($ledger->status)->toBe('succeeded');
    expect($ledger->attempts)->toBe(1);

    // ADR 0059 spirit: the class stays unknown — the reply's translations
    // never mint a part of speech.
    expect($word->fresh()->wordClass->slug)->toBe('unknown');

    // The prompt names the word, never the unknown class.
    expect($captured['question'])->toContain('bomb-hit');
    expect($captured['question'])->not->toContain('unknown');

    // The next open's payload: the minted word reads ai_asked (and the
    // source badge dedups to the single "ai" chip — the mint carries
    // source 'ai' AND the succeeded ledger; ADR 0084 + 0082).
    $show = $this->actingAs($fixture['user'])->getJson('/words/'.$word->id)->json('data');
    expect($show['id'])->toBe($word->id);
    expect($show['word'])->toBe('bomb-hit');
    expect($show['ai_asked'])->toBeTrue();
    expect($show['entries'][0]['definitions'])->toContain(['text' => 'A financial institution.', 'labels' => []]);
    expect($show['source'])->toBe('ai');
});

it('adopts a case/ё/stress surface variant onto the EXISTING row — never a duplicate word', function () {
    Http::fake();
    Queue::fake();
    $fixture = createAdoptFixture();
    // The dictionary row carries the folded ADR 0081 key (createWord's plain
    // lowercase is overridden the way the importers store it).
    $existing = createWord('ru', 'ёморок', 'noun', ['l_word' => WordLookupKey::normalize('ёморок')]);
    $homeland = createWord('ru', 'дом', 'noun');

    // One mock for both asks (the route caches its controller, so a mid-test
    // rebind would never reach it); $captured ends up holding the LAST ask's
    // question.
    $captured = null;
    mockResolverForAdoptJob(null, $captured);

    // Uppercase + ё-spelled surface (the reader clicked «Ёморок») resolves
    // the е-keyed row and enriches IT. The reply is monolingual (ticket 08):
    // the ru word's definitions come back in Cyrillic — a Latin-majority
    // one would drop at the merge (the import guard holds for AI).
    $reply = json_encode([
        'translations' => ['банк', 'прибанок'],
        'definitions' => ['нечистая сила, наваждение'],
        'transcriptions' => ['jɪmɐˈrok'],
        'examples' => ['Ёморок напугал всю деревню.'],
        'etymologies' => ['От итальянского banca.'],
    ]);
    mockResolverForAdoptJob($reply, $captured);

    $response = $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['surface' => 'Ёморок', 'language_code' => 'ru']);

    $response->assertOk();
    Queue::assertPushed(FetchWordAiEnrichment::class, fn (FetchWordAiEnrichment $job) => $job->wordId === $existing->id);
    expect(Word::query()->where('l_word', 'еморок')->count())->toBe(1);

    (new FetchWordAiEnrichment($existing->id, $fixture['user']->id))->handle(app(AIModelResolver::class), app(WordAiEnrichmentService::class));

    expect(Definition::query()->where('word_id', $existing->id)->count())->toBe(1);
    expect(WordAiFetch::query()->where('word_id', $existing->id)->value('status'))->toBe('succeeded');

    // A stressed surface (the reader's stress toggle: «до́м») keys the same
    // mark-free l_word and adopts onto the existing row — on a fresh word
    // with no ledger row, so the succeeded ask above never interferes.
    $stressed = $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['surface' => 'до́м', 'language_code' => 'ru']);

    $stressed->assertOk();
    Queue::assertPushedTimes(FetchWordAiEnrichment::class, 2);
    expect(Word::query()->where('l_word', 'дом')->count())->toBe(1);

    (new FetchWordAiEnrichment($homeland->id, $fixture['user']->id))->handle(app(AIModelResolver::class), app(WordAiEnrichmentService::class));
    expect($captured['question'])->toContain('дом');
});

it('mints with the full creator hygiene: ё stays in the display word, the key folds, and the lemma form carries its е-alias', function () {
    Queue::fake();
    $fixture = createAdoptFixture();
    mockResolverForAdoptDispatch();

    $response = $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['surface' => 'Ёлка', 'language_code' => 'ru']);

    $response->assertOk();

    $word = Word::query()->where('l_word', 'елка')->sole();
    // Display keeps the source spelling (ё and case); only the key folds.
    expect($word->word)->toBe('Ёлка');
    expect($word->wordClass->slug)->toBe('unknown');

    // Every word carries itself as a form — and a ё-lemma emits its
    // е-folded alias row, both keyed through the same pipeline.
    $forms = Form::query()->where('word_id', $word->id)->get();
    expect($forms->pluck('form')->all())->toEqual(['Ёлка', 'Елка']);
    expect($forms->pluck('l_word')->unique()->all())->toBe(['елка']);
});

it('mints one word only: a second ask on the same surface adopts the minted row and the job re-enriches it in place', function () {
    Http::fake();
    Queue::fake();
    $fixture = createAdoptFixture();
    mockResolverForAdoptJob();

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['surface' => 'cookie', 'language_code' => 'en'])
        ->assertOk();

    $word = Word::query()->where('l_word', 'cookie')->sole();
    (new FetchWordAiEnrichment($word->id, $fixture['user']->id))->handle(app(AIModelResolver::class), app(WordAiEnrichmentService::class));

    // The re-ask (ADR 0082 amendment): the succeeded ask is no longer a
    // lock — the surface resolves the minted row and the ask dispatches
    // again, replacing the previous AI rows. Still no second word, still
    // one definition (the reply's identical text re-created in place).
    $response = $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['surface' => 'cookie', 'language_code' => 'en']);

    $response->assertOk();
    Queue::assertPushedTimes(FetchWordAiEnrichment::class, 2);
    expect(Word::query()->where('l_word', 'cookie')->count())->toBe(1);

    (new FetchWordAiEnrichment($word->id, $fixture['user']->id))->handle(app(AIModelResolver::class), app(WordAiEnrichmentService::class));

    expect(Definition::query()->join('words', 'words.id', '=', 'definitions.word_id')
        ->where('words.l_word', 'cookie')->count())->toBe(1);
    $ledger = WordAiFetch::query()->where('word_id', $word->id)->sole();
    expect($ledger->attempts)->toBe(2);
    expect($ledger->status)->toBe('succeeded');
});

it('answers a concurrent double-ask with a clean retryable-later response, never a 500, and dispatches nothing for the loser', function () {
    Queue::fake();
    $fixture = createAdoptFixture();
    // The race's winner is mid-flight: its pending ledger row is visible.
    $word = createWord('ru', 'ёж', 'noun', ['l_word' => WordLookupKey::normalize('ёж')]);
    WordAiFetch::query()->create([
        'word_id' => $word->id,
        'status' => WordAiFetch::STATUS_PENDING,
        'provider' => 'openrouter',
        'model' => 'google/gemini-3-flash-preview',
        'attempts' => 1,
        'asked_at' => now(),
    ]);

    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('resolveExplanationModel')->andReturn(ADOPT_MODEL_MOCK);
    $mock->shouldReceive('parseModelString')->andReturnUsing(function (string $key): array {
        $colon = strpos($key, ':');

        return [
            'provider' => $colon === false ? $key : substr($key, 0, $colon),
            'model' => $colon === false ? '' : substr($key, $colon + 1),
        ];
    });
    $mock->shouldReceive('ask')->never();
    app()->instance(AIModelResolver::class, $mock);

    $response = $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['surface' => 'Ёж', 'language_code' => 'ru']);

    // Clean arbitration: a 409 naming the in-flight ask — retryable later,
    // the loser never touched the winner's attempt and never dispatched.
    $response->assertStatus(409);
    expect($response->json('error'))->toContain('in progress');
    Queue::assertNothingPushed();
    $ledger = WordAiFetch::query()->where('word_id', $word->id)->firstOrFail();
    expect($ledger->status)->toBe('pending');
    expect($ledger->attempts)->toBe(1);
    expect(Definition::query()->where('word_id', $word->id)->count())->toBe(0);

    // The same gate guards the known-word path.
    $known = $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['word_id' => $word->id]);
    $known->assertStatus(409);
    expect($known->json('error'))->toContain('in progress');
    Queue::assertNothingPushed();
});

it('answers 400 guidance and mints nothing when no AI model is usable', function () {
    Queue::fake();
    $fixture = createAdoptFixture();

    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('resolveExplanationModel')->once()->withNoArgs()->andReturnNull();
    $mock->shouldReceive('ask')->never();
    app()->instance(AIModelResolver::class, $mock);

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['surface' => 'bomb-hit', 'language_code' => 'en'])
        ->assertBadRequest()
        ->assertJsonPath('error', 'Choose an AI model in your profile settings.');

    // The mint happens only when an ask can really be dispatched.
    Queue::assertNothingPushed();
    expect(Word::query()->where('l_word', 'bomb-hit')->exists())->toBeFalse();
    expect(WordAiFetch::query()->count())->toBe(0);
});

it('keeps the minted word and a retryable failed ledger when the job fails — the retry dispatch passes the failed gate and enriches the same row', function () {
    Http::fake();
    Queue::fake();
    $fixture = createAdoptFixture();

    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('resolveExplanationModel')->andReturn(ADOPT_MODEL_MOCK);
    $mock->shouldReceive('parseModelString')->andReturnUsing(function (string $key): array {
        $colon = strpos($key, ':');

        return [
            'provider' => $colon === false ? $key : substr($key, 0, $colon),
            'model' => $colon === false ? '' : substr($key, $colon + 1),
        ];
    });
    $mock->shouldReceive('ask')->once()->andThrow(
        new AiProviderException('The AI service is busy. Please try again in a moment.', 429),
    );
    $mock->shouldReceive('ask')->once()->andReturn(adoptReply());
    app()->instance(AIModelResolver::class, $mock);

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['surface' => 'bomb-hit', 'language_code' => 'en'])
        ->assertOk();

    // The word stays minted (the dictionary path was the point); the ask
    // stays retryable.
    $word = Word::query()->where('l_word', 'bomb-hit')->sole();

    (new FetchWordAiEnrichment($word->id, $fixture['user']->id))->handle(app(AIModelResolver::class), app(WordAiEnrichmentService::class));

    $ledger = WordAiFetch::query()->where('word_id', $word->id)->firstOrFail();
    expect($ledger->status)->toBe('failed');
    expect($ledger->attempts)->toBe(1);
    expect($ledger->succeeded_at)->toBeNull();

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['surface' => 'bomb-hit', 'language_code' => 'en'])
        ->assertOk();

    // The retry resolved the SAME row — exactly one word, one ledger path.
    expect(Word::query()->where('l_word', 'bomb-hit')->count())->toBe(1);
    expect(WordAiFetch::query()->where('word_id', $word->id)->count())->toBe(1);
    expect(WordAiFetch::query()->where('word_id', $word->id)->value('attempts'))->toBe(2);

    (new FetchWordAiEnrichment($word->id, $fixture['user']->id))->handle(app(AIModelResolver::class), app(WordAiEnrichmentService::class));

    $ledger = $ledger->fresh();
    expect($ledger->status)->toBe('succeeded');
    expect($ledger->attempts)->toBe(2);
    expect(Definition::query()->where('word_id', $word->id)->count())->toBe(1);
});

it('answers 403 to a non-admin on the adoption path and mints nothing', function () {
    Queue::fake();
    Http::fake();
    createWordClasses();
    // A plain approved user — the reading user the missing card's icon is
    // hidden from.
    $user = nativeRuUser();

    $this->actingAs($user)
        ->postJson('/ai/word-enrich', ['surface' => 'bomb-hit', 'language_code' => 'en'])
        ->assertForbidden()
        ->assertJsonPath('error', 'Only approved admins can ask the AI to enrich a word.');

    Queue::assertNothingPushed();
    expect(Word::query()->where('l_word', 'bomb-hit')->exists())->toBeFalse();
    expect(WordAiFetch::query()->count())->toBe(0);
});

it('validates the adoption payload: unknown language, missing fields, empty surface, mixed modes', function () {
    Queue::fake();
    $fixture = createAdoptFixture();
    mockResolverForAdoptDispatch();

    $this->postJson('/ai/word-enrich', ['surface' => 'bomb-hit', 'language_code' => 'en'])
        ->assertUnauthorized();

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['surface' => 'bomb-hit', 'language_code' => 'zz'])
        ->assertJsonValidationErrors(['language_code']);

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['surface' => 'bomb-hit'])
        ->assertJsonValidationErrors(['language_code']);

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['language_code' => 'en'])
        ->assertJsonValidationErrors(['surface']);

    // A surface that is nothing but combining stress marks has no display
    // word: rejected at validation, not a junk row.
    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['surface' => '́', 'language_code' => 'ru'])
        ->assertJsonValidationErrors(['surface']);

    // The two modes are exclusive — the language code only rides on the
    // adoption path, so a word id beside it errors on both. The surface is
    // different: the known-word ask carries it too (harmless since the
    // queued flow answers {queued: true}, but still accepted).
    $word = createWord('en', 'bank', 'noun');
    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['word_id' => $word->id, 'language_code' => 'en'])
        ->assertJsonValidationErrors(['word_id', 'language_code']);

    Queue::assertNothingPushed();
    expect(Word::query()->where('l_word', 'bomb-hit')->exists())->toBeFalse();
});
