<?php

use App\Classes\AIModelResolver;
use App\Classes\WordAiEnrichmentService;
use App\Exceptions\AiProviderException;
use App\Jobs\FetchWordAiEnrichment;
use App\Models\Definition;
use App\Models\Etymology;
use App\Models\Example;
use App\Models\Form;
use App\Models\Language;
use App\Models\PromptTemplate;
use App\Models\Transcription;
use App\Models\TranscriptionType;
use App\Models\User;
use App\Models\Word;
use App\Models\WordAiFetch;
use App\Models\WordTranslation;
use App\Support\PromptTemplates;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

const AI_ENRICH_MODEL_MOCK = ['id' => 7, 'key' => 'openrouter:google/gemini-3-flash-preview', 'label' => 'Gemini Flash'];

/**
 * A sparse EN word (no content at all), the asking user's native language
 * Russian, and one EXISTING ru word («банк») the AI's translation candidate
 * can link to — plus a candidate no ru row carries, which must stage into
 * the source word's translations JSON instead of creating anything.
 * The asker is an approved admin (word-popup-overhaul ticket 06): the
 * endpoint answers 403 to everyone else.
 */
function createEnrichFixture(): array
{
    createWordClasses();
    $enWord = createWord('en', 'bank', 'noun');
    $ruWord = createWord('ru', 'банк', 'noun');
    TranscriptionType::query()->create([
        'language_id' => Language::query()->where('code', 'en')->value('id'),
        'slug' => 'ipa',
        'title' => 'IPA',
    ]);
    $user = adminRuEnricher();

    return ['word' => $enWord, 'ruWord' => $ruWord, 'user' => $user];
}

/**
 * The approved-admin asker with the Russian native language — the fixture's
 * acting user (helper name differs from AiWordEnrichAdoptTest's on purpose:
 * parallel workers may load both files into one process).
 */
function adminRuEnricher(): User
{
    createLanguages();

    $user = User::factory()->admin()->create();
    $user->settings()->updateOrCreate([], [
        'native_language_id' => Language::query()->where('code', 'ru')->value('id'),
    ]);

    return $user;
}

function enrichReply(): string
{
    // Fenced + prose-wrapped on purpose: the job must take the lenient path
    // end to end.
    return "Sure! Here is the JSON:\n```json\n".json_encode([
        'translations' => ['банк', 'берег', 'прибанок'],
        'definitions' => ['A financial institution.', 'The land alongside a river.'],
        'transcriptions' => ['bæŋk'],
        'examples' => ['He robbed the bank.', 'We had a picnic on the bank.'],
        'etymologies' => ['From Italian banca.'],
    ])."\n```\nHope that helps!";
}

/**
 * The mock's parseModelString answer: the real resolver's 'provider:model'
 * split (first colon, OpenRouter ids with '/' intact) as a plain closure —
 * every mock whose model resolution is reachable needs it, the controller
 * splits the ledger's provider/model through this API.
 */
function enrichModelSplit(): Closure
{
    return function (string $key): array {
        $colon = strpos($key, ':');

        return [
            'provider' => $colon === false ? $key : substr($key, 0, $colon),
            'model' => $colon === false ? '' : substr($key, $colon + 1),
        ];
    };
}

/**
 * The DISPATCH-time mock (endpoint tests, Queue::fake): the click still
 * resolves the model + key cheaply — against the SESSION user (withNoArgs)
 * — and must never reach the provider: the ask lives in the queued job now
 * (word-popup-overhaul ticket 06).
 */
function mockResolverForEnrichDispatch(): MockInterface
{
    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('resolveExplanationModel')->withNoArgs()->andReturn(AI_ENRICH_MODEL_MOCK);
    $mock->shouldReceive('parseModelString')->andReturnUsing(enrichModelSplit());
    $mock->shouldReceive('ask')->never();
    app()->instance(AIModelResolver::class, $mock);

    return $mock;
}

/**
 * The JOB-time mock: the queued job resolves the model against the CARRIED
 * user (the explicit-user seam — never the authenticated session, a worker
 * has none) and runs the provider ask. $capturedUser pins the seam; in a
 * job test nothing is authenticated, so the ask only succeeds because the
 * job passed its own user through.
 */
function mockResolverForEnrichJob(?string $reply = null, ?string &$captured = null, ?User &$capturedUser = null): MockInterface
{
    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('resolveExplanationModel')->andReturnUsing(function (?User $user = null) use (&$capturedUser) {
        $capturedUser = $user;

        return AI_ENRICH_MODEL_MOCK;
    });
    $mock->shouldReceive('parseModelString')->andReturnUsing(enrichModelSplit());
    $mock->shouldReceive('ask')->andReturnUsing(function ($model, $instruction, $question, $user = null) use ($reply, &$captured) {
        $captured = ['model' => $model, 'instruction' => $instruction, 'question' => $question];

        return $reply ?? enrichReply();
    });
    app()->instance(AIModelResolver::class, $mock);

    return $mock;
}

/**
 * The ledger row exactly as the dispatching controller leaves it: pending,
 * attempts bumped, asked_at fresh, the resolved model's two halves stored.
 * The job finalizes THIS row.
 */
function seedPendingEnrichLedger(int $wordId, int $attempts = 1): WordAiFetch
{
    return WordAiFetch::query()->create([
        'word_id' => $wordId,
        'status' => WordAiFetch::STATUS_PENDING,
        'provider' => 'openrouter',
        'model' => 'google/gemini-3-flash-preview',
        'attempts' => $attempts,
        'asked_at' => now(),
    ]);
}

it('queues the ask: an admin POST answers the minimal {queued: true} payload, never touches the provider, records the pending ledger, and dispatches the job carrying the asking user', function () {
    $fixture = createEnrichFixture();
    Queue::fake();
    mockResolverForEnrichDispatch();

    $response = $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['word_id' => $fixture['word']->id]);

    // The click answers immediately: no AI payload swap-in anymore — the
    // response IS the minimal queued marker.
    $response->assertOk();
    expect($response->json())->toBe(['queued' => true]);

    // No AI call in the HTTP request (the dispatch mock's ask->never pin)
    // and nothing merged yet.
    expect(Definition::query()->where('word_id', $fixture['word']->id)->count())->toBe(0);

    // The dispatch-time ledger gate's row: pending, one attempt, the
    // resolved model's two halves.
    $ledger = WordAiFetch::query()->where('word_id', $fixture['word']->id)->firstOrFail();
    expect($ledger->status)->toBe('pending');
    expect($ledger->attempts)->toBe(1);
    expect($ledger->provider)->toBe('openrouter');
    expect($ledger->model)->toBe('google/gemini-3-flash-preview');
    expect($ledger->asked_at)->not->toBeNull();
    expect($ledger->succeeded_at)->toBeNull();

    // The job carries the asking user's id and the word's id — scalar ids,
    // SerializesModels-friendly (the FetchWordTranslations precedent).
    Queue::assertPushed(FetchWordAiEnrichment::class, fn (FetchWordAiEnrichment $job) => $job->wordId === $fixture['word']->id
        && $job->userId === $fixture['user']->id);
});

it('runs the queued job: content rows merged with ai provenance, link-only translations, succeeded ledger, word touched, ai_asked true on the next open', function () {
    $fixture = createEnrichFixture();
    Http::fake();
    seedPendingEnrichLedger($fixture['word']->id);

    $captured = null;
    $capturedUser = null;
    mockResolverForEnrichJob(null, $captured, $capturedUser);

    // Nothing is authenticated here: the job must run the ask against the
    // user it CARRIES (the explicit-user seam), never an auth session.
    (new FetchWordAiEnrichment($fixture['word']->id, $fixture['user']->id))->handle(app(AIModelResolver::class), app(WordAiEnrichmentService::class));

    expect($capturedUser?->is($fixture['user']))->toBeTrue();

    // Content rows all carry the ai provenance marker.
    expect(Definition::query()->where('word_id', $fixture['word']->id)->where('source', 'ai')->count())->toBe(2);
    expect(Transcription::query()->where('word_id', $fixture['word']->id)->where('source', 'ai')->count())->toBe(1);
    expect(Example::query()->where('word_id', $fixture['word']->id)->where('source', 'ai')->count())->toBe(2);

    // Link-only translations: the existing ru row links, the unmatched
    // candidate stages into the JSON — and ZERO word rows are created.
    expect(WordTranslation::isLinked($fixture['word']->id, $fixture['ruWord']->id))->toBeTrue();
    $word = $fixture['word']->refresh();
    expect($word->translations)->toContain('прибанок');
    expect(Word::query()->count())->toBe(2);

    // The ledger finalizes the ask on success (succeeded is not a lock —
    // the toolbar's re-ask icon rides on it).
    $ledger = WordAiFetch::query()->where('word_id', $fixture['word']->id)->firstOrFail();
    expect($ledger->status)->toBe('succeeded');
    expect($ledger->attempts)->toBe(1);
    expect($ledger->asked_at)->not->toBeNull();
    expect($ledger->succeeded_at)->not->toBeNull();
    expect($ledger->error)->toBeNull();

    // The next open's payload: ai_asked true (the re-ask icon), the
    // enriched content riding inside the entries, definitions as
    // {text, labels} objects. The word was touched, so ticket 02's lazy
    // completeness recalculated over the new content (weighted v2): links
    // (3) + definitions on a major POS (2) + transcriptions + examples +
    // etymologies (1 each) = 8, no frequency rank, no labels.
    $show = $this->actingAs($fixture['user'])
        ->getJson('/words/'.$fixture['word']->id)->json('data');
    expect($show['id'])->toBe($fixture['word']->id);
    expect($show['ai_asked'])->toBeTrue();
    expect($show['entries'][0]['definitions'])->toContain(['text' => 'A financial institution.', 'labels' => []]);
    expect($show['entries'][0]['transcriptions'][0]['value'])->toBe('bæŋk');
    expect($show['entries'][0]['staged_translations'])->toBe(['берег', 'прибанок']);
    expect($show['source'])->toBeNull();
    $word = $fixture['word']->fresh();
    expect($word->completeness)->toBe(8);
    expect($word->completeness_at)->not->toBeNull();

    // The prompt went through the enrichment template with the native
    // language substituted; the question names the word.
    expect($captured['instruction'])->toContain('Russian');
    expect($captured['instruction'])->toContain('bank');
    expect($captured['question'])->toContain('bank');
    expect($captured['model'])->toBe('openrouter:google/gemini-3-flash-preview');
});

it('re-asks through the queue: the dispatch passes the succeeded gate, the job replaces the previous AI rows, imported rows stay', function () {
    Http::fake();
    Queue::fake();
    $fixture = createEnrichFixture();
    // The first ask's succeeded ledger and its content: one AI-attributed
    // definition (the re-ask must clear it) beside one imported one (the
    // clear must never touch it).
    WordAiFetch::query()->create([
        'word_id' => $fixture['word']->id,
        'status' => WordAiFetch::STATUS_SUCCEEDED,
        'attempts' => 1,
        'asked_at' => now()->subHour(),
        'succeeded_at' => now()->subHour(),
    ]);
    $firstAskDefinition = Definition::query()->create([
        'word_id' => $fixture['word']->id,
        'definition' => 'A financial institution.',
        'source' => 'ai',
    ]);
    Definition::query()->create(['word_id' => $fixture['word']->id, 'definition' => 'An imported gloss.']);

    $captured = null;
    mockResolverForEnrichJob(null, $captured);

    $response = $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['word_id' => $fixture['word']->id]);

    // A succeeded row is no longer a lock (ADR 0082 amendment): the ask
    // dispatches, and the SAME ledger row advances at dispatch time.
    $response->assertOk();
    expect($response->json('queued'))->toBeTrue();
    Queue::assertPushed(FetchWordAiEnrichment::class, fn (FetchWordAiEnrichment $job) => $job->wordId === $fixture['word']->id);
    expect(WordAiFetch::query()->where('word_id', $fixture['word']->id)->value('attempts'))->toBe(2);

    // The queued job's fresh reply replaces the previous AI rows — the
    // imported one survives untouched.
    (new FetchWordAiEnrichment($fixture['word']->id, $fixture['user']->id))->handle(app(AIModelResolver::class), app(WordAiEnrichmentService::class));
    expect($captured['question'])->toContain('bank');

    $ledger = WordAiFetch::query()->where('word_id', $fixture['word']->id)->sole();
    expect($ledger->status)->toBe('succeeded');
    expect($ledger->attempts)->toBe(2);
    expect($ledger->succeeded_at)->not->toBeNull();
    expect($ledger->error)->toBeNull();

    // The first ask's AI row is gone (a new row carries the same text);
    // the imported row is exactly the seeded one.
    expect(Definition::query()->find($firstAskDefinition->id))->toBeNull();
    $definitions = Definition::query()->where('word_id', $fixture['word']->id)->orderBy('id')->get();
    expect($definitions)->toHaveCount(3);
    expect($definitions[0]->definition)->toBe('An imported gloss.')
        ->and($definitions[0]->source)->toBeNull();
    expect($definitions->slice(1)->values()->pluck('definition')->all())
        ->toEqual(['A financial institution.', 'The land alongside a river.']);
    expect($definitions->slice(1)->pluck('source')->all())->each->toBe('ai');
});

it('answers 403 to a non-admin before any ledger row or mint, and dispatches nothing', function () {
    Http::fake();
    Queue::fake();
    createWordClasses();
    $enWord = createWord('en', 'bank', 'noun');
    // A plain approved user — the reading user the icon is hidden from.
    $user = nativeRuUser();

    $response = $this->actingAs($user)
        ->postJson('/ai/word-enrich', ['word_id' => $enWord->id]);

    // The reading-surfaces JSON error shape (ADR 0069), hidden-icon parity:
    // the endpoint refuses what the UI never shows.
    $response->assertForbidden();
    expect($response->json('error'))->toBe('Only approved admins can ask the AI to enrich a word.');

    Queue::assertNothingPushed();
    expect(WordAiFetch::query()->where('word_id', $enWord->id)->exists())->toBeFalse();

    // (An admin whose approval lapsed never reaches this gate: the route's
    // `approved` middleware bounces them first — its own tests cover that.)
});

it('keeps ai_asked true after a failed re-ask job — the icon stays the re-ask variant', function () {
    Http::fake();
    $fixture = createEnrichFixture();
    WordAiFetch::query()->create([
        'word_id' => $fixture['word']->id,
        'status' => WordAiFetch::STATUS_SUCCEEDED,
        'attempts' => 1,
        'asked_at' => now()->subHour(),
        'succeeded_at' => now()->subHour(),
    ]);

    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('resolveExplanationModel')->andReturn(AI_ENRICH_MODEL_MOCK);
    $mock->shouldReceive('parseModelString')->andReturnUsing(enrichModelSplit());
    $mock->shouldReceive('ask')->once()->andThrow(
        new AiProviderException('The AI service is busy. Please try again in a moment.', 429),
    );
    app()->instance(AIModelResolver::class, $mock);

    (new FetchWordAiEnrichment($fixture['word']->id, $fixture['user']->id))->handle(app(AIModelResolver::class), app(WordAiEnrichmentService::class));

    // The re-ask finalized FAILED — but succeeded_at (the first success)
    // is kept, and ai_asked is judged on it: the word-show payload stays
    // ai_asked true, so the toolbar keeps the re-ask variant instead of
    // regressing to the first-time icon.
    $ledger = WordAiFetch::query()->where('word_id', $fixture['word']->id)->sole();
    expect($ledger->status)->toBe('failed');
    expect($ledger->attempts)->toBe(1);
    expect($ledger->succeeded_at)->not->toBeNull();
    expect($ledger->error)->toBe('The AI service is busy. Please try again in a moment.');

    $show = $this->actingAs($fixture['user'])
        ->getJson('/words/'.$fixture['word']->id)->json('data');
    expect($show['ai_asked'])->toBeTrue();
});

it('keeps the ask retryable after a provider failure: the job finalizes the ledger failed once and the next dispatch passes the failed gate', function () {
    Http::fake();
    Queue::fake();
    $fixture = createEnrichFixture();
    seedPendingEnrichLedger($fixture['word']->id);

    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('resolveExplanationModel')->andReturn(AI_ENRICH_MODEL_MOCK);
    $mock->shouldReceive('parseModelString')->andReturnUsing(enrichModelSplit());
    $mock->shouldReceive('ask')->once()->andThrow(
        new AiProviderException('The AI service is busy. Please try again in a moment.', 429),
    );
    $mock->shouldReceive('ask')->once()->andReturn(enrichReply());
    app()->instance(AIModelResolver::class, $mock);

    (new FetchWordAiEnrichment($fixture['word']->id, $fixture['user']->id))->handle(app(AIModelResolver::class), app(WordAiEnrichmentService::class));

    // Failed, not locked — the error rides the ledger row (the client-side
    // silence is the UI's business: nothing surfaces on the next open).
    $ledger = WordAiFetch::query()->where('word_id', $fixture['word']->id)->firstOrFail();
    expect($ledger->status)->toBe('failed');
    expect($ledger->attempts)->toBe(1);
    expect($ledger->succeeded_at)->toBeNull();
    expect(Definition::query()->where('word_id', $fixture['word']->id)->count())->toBe(0);

    // The user re-asks via the icon: the failed gate passes, the retry
    // dispatches, and the job finalizes succeeded on the first success —
    // each attempt produced its rows exactly once.
    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['word_id' => $fixture['word']->id])
        ->assertOk();
    Queue::assertPushed(FetchWordAiEnrichment::class, fn (FetchWordAiEnrichment $job) => $job->wordId === $fixture['word']->id);
    expect(WordAiFetch::query()->where('word_id', $fixture['word']->id)->value('attempts'))->toBe(2);

    (new FetchWordAiEnrichment($fixture['word']->id, $fixture['user']->id))->handle(app(AIModelResolver::class), app(WordAiEnrichmentService::class));

    $ledger = $ledger->fresh();
    expect($ledger->status)->toBe('succeeded');
    expect($ledger->attempts)->toBe(2);
    expect($ledger->error)->toBeNull();
    expect(Definition::query()->where('word_id', $fixture['word']->id)->count())->toBe(2);
});

it('surfaces a missing provider key inside the job as a failed ledger row, and a keyless user gets the choose-model guidance at dispatch', function () {
    Http::fake();
    Queue::fake();
    $fixture = createEnrichFixture();

    // At dispatch a keyless user resolves NO model (the provider gate
    // filters unkeyed providers out): the existing 400 guidance answers and
    // nothing is dispatched — no ledger row, no mint, no job.
    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('resolveExplanationModel')->once()->withNoArgs()->andReturnNull();
    $mock->shouldReceive('ask')->never();
    app()->instance(AIModelResolver::class, $mock);

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['word_id' => $fixture['word']->id])
        ->assertStatus(400)
        ->assertJsonPath('error', 'Choose an AI model in your profile settings.');
    Queue::assertNothingPushed();
    expect(WordAiFetch::query()->where('word_id', $fixture['word']->id)->exists())->toBeFalse();

    // The job-side 403 guidance (keys vanished between dispatch and run):
    // the ask's own error finalizes the ledger failed — silently, the user
    // just sees the icon retryable again.
    seedPendingEnrichLedger($fixture['word']->id);
    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('resolveExplanationModel')->andReturn(AI_ENRICH_MODEL_MOCK);
    $mock->shouldReceive('parseModelString')->andReturnUsing(enrichModelSplit());
    $mock->shouldReceive('ask')->once()->andThrow(
        new AiProviderException('You have not added an API key for this provider.', 403),
    );
    app()->instance(AIModelResolver::class, $mock);

    (new FetchWordAiEnrichment($fixture['word']->id, $fixture['user']->id))->handle(app(AIModelResolver::class), app(WordAiEnrichmentService::class));

    $ledger = WordAiFetch::query()->where('word_id', $fixture['word']->id)->firstOrFail();
    expect($ledger->status)->toBe('failed');
    expect($ledger->attempts)->toBe(1);
    expect($ledger->error)->toBe('You have not added an API key for this provider.');
    expect($ledger->succeeded_at)->toBeNull();
    expect(Definition::query()->where('word_id', $fixture['word']->id)->count())->toBe(0);
});

it('answers 400 guidance and records no attempt when no AI model is usable', function () {
    Queue::fake();
    $fixture = createEnrichFixture();

    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('resolveExplanationModel')->once()->withNoArgs()->andReturnNull();
    $mock->shouldReceive('ask')->never();
    app()->instance(AIModelResolver::class, $mock);

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['word_id' => $fixture['word']->id])
        ->assertStatus(400)
        ->assertJsonPath('error', 'Choose an AI model in your profile settings.');

    Queue::assertNothingPushed();
    expect(WordAiFetch::query()->where('word_id', $fixture['word']->id)->exists())->toBeFalse();
});

it('finalizes an unparseable reply as a retryable failure', function () {
    Http::fake();
    $fixture = createEnrichFixture();
    seedPendingEnrichLedger($fixture['word']->id);
    mockResolverForEnrichJob('I am sorry, I cannot help with that.');

    (new FetchWordAiEnrichment($fixture['word']->id, $fixture['user']->id))->handle(app(AIModelResolver::class), app(WordAiEnrichmentService::class));

    $ledger = WordAiFetch::query()->where('word_id', $fixture['word']->id)->firstOrFail();
    expect($ledger->status)->toBe('failed');
    expect($ledger->attempts)->toBe(1);
    expect($ledger->error)->toBe('The AI reply could not be parsed as strict JSON.');
    expect(Definition::query()->where('word_id', $fixture['word']->id)->count())->toBe(0);
});

it('finalizes SUCCEEDED when a parsed reply dedupes to nothing new — the ask itself succeeded', function () {
    Http::fake();
    $fixture = createEnrichFixture();
    seedPendingEnrichLedger($fixture['word']->id);
    Definition::query()->create(['word_id' => $fixture['word']->id, 'definition' => 'A financial institution.']);
    WordTranslation::link($fixture['word']->id, $fixture['ruWord']->id);

    $dupReply = json_encode([
        'translations' => ['банк'],
        'definitions' => ['A financial institution.'],
    ]);
    mockResolverForEnrichJob($dupReply);

    (new FetchWordAiEnrichment($fixture['word']->id, $fixture['user']->id))->handle(app(AIModelResolver::class), app(WordAiEnrichmentService::class));

    // The dedupe landing nothing new is still a finished ask: the ledger
    // finalizes SUCCEEDED — that succeeded_at is what the payload's
    // ai_asked (and the toolbar's re-ask variant) reports.
    expect(Definition::query()->where('word_id', $fixture['word']->id)->count())->toBe(1);
    $ledger = WordAiFetch::query()->where('word_id', $fixture['word']->id)->firstOrFail();
    expect($ledger->status)->toBe('succeeded');
    expect($ledger->succeeded_at)->not->toBeNull();
    expect($ledger->error)->toBeNull();
});

it('takes over a stale pending row past the grace window, lets the retry dispatch, and the job finalizes succeeded', function () {
    Queue::fake();
    $fixture = createEnrichFixture();
    // A fatal/timeout between the pending save and the finalize strands a
    // pending row forever — pending is not a lock (spec decision 4: locked
    // by success only). This one is minutes past the grace window: a
    // crashed queue worker is exactly the failure mode the window nets.
    WordAiFetch::query()->create([
        'word_id' => $fixture['word']->id,
        'status' => WordAiFetch::STATUS_PENDING,
        'provider' => 'openrouter',
        'model' => 'google/gemini-3-flash-preview',
        'attempts' => 1,
        'asked_at' => now()->subMinutes(30),
    ]);

    $captured = null;
    mockResolverForEnrichJob(null, $captured);

    $response = $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['word_id' => $fixture['word']->id]);

    // The retry took the SAME row over: attempts advanced, the ask
    // dispatches.
    $response->assertOk();
    expect($response->json('queued'))->toBeTrue();
    Queue::assertPushed(FetchWordAiEnrichment::class, fn (FetchWordAiEnrichment $job) => $job->wordId === $fixture['word']->id);
    expect(WordAiFetch::query()->where('word_id', $fixture['word']->id)->value('attempts'))->toBe(2);

    (new FetchWordAiEnrichment($fixture['word']->id, $fixture['user']->id))->handle(app(AIModelResolver::class), app(WordAiEnrichmentService::class));

    $ledger = WordAiFetch::query()->where('word_id', $fixture['word']->id)->sole();
    expect($ledger->status)->toBe('succeeded');
    expect($ledger->attempts)->toBe(2);
    expect($ledger->succeeded_at)->not->toBeNull();
    expect(Definition::query()->where('word_id', $fixture['word']->id)->count())->toBe(2);
});

it('dedupes exact text: existing rows keep their imported provenance, only new rows carry source=ai', function () {
    Http::fake();
    $fixture = createEnrichFixture();
    seedPendingEnrichLedger($fixture['word']->id);
    Definition::query()->create(['word_id' => $fixture['word']->id, 'definition' => 'A financial institution.']);

    mockResolverForEnrichJob();

    (new FetchWordAiEnrichment($fixture['word']->id, $fixture['user']->id))->handle(app(AIModelResolver::class), app(WordAiEnrichmentService::class));

    $definitions = Definition::query()->where('word_id', $fixture['word']->id)->orderBy('id')->get();
    expect($definitions)->toHaveCount(2);
    expect($definitions[0]->source)->toBeNull();
    expect($definitions[1]->definition)->toBe('The land alongside a river.');
    expect($definitions[1]->source)->toBe('ai');
});

it('caps and truncates what it persists: 8 definitions, 4 of the rest, 1000-char etymologies', function () {
    Http::fake();
    $fixture = createEnrichFixture();
    seedPendingEnrichLedger($fixture['word']->id);

    $greedy = json_encode([
        'definitions' => array_map(fn (int $i): string => "Definition {$i}.", range(1, 15)),
        'examples' => array_map(fn (int $i): string => "Example {$i}.", range(1, 9)),
        // Distinct prefixes so the dedupe cannot collapse them before the
        // truncation is measured.
        'etymologies' => array_map(fn (int $i): string => "Root {$i}: ".str_repeat('e', 1200), range(1, 9)),
    ]);
    mockResolverForEnrichJob($greedy);

    (new FetchWordAiEnrichment($fixture['word']->id, $fixture['user']->id))->handle(app(AIModelResolver::class), app(WordAiEnrichmentService::class));

    expect(Definition::query()->where('word_id', $fixture['word']->id)->count())->toBe(8);
    expect(Example::query()->where('word_id', $fixture['word']->id)->count())->toBe(4);
    // Every 1200-char etymology truncated to the etymologies column limit.
    expect(Etymology::query()->where('word_id', $fixture['word']->id)->count())->toBe(4);
    Etymology::query()->where('word_id', $fixture['word']->id)->get()
        ->each(fn (Etymology $etymology) => expect($etymology->etymology)->toHaveLength(1000));
});

it('assembles the instruction from the admin-edited word-enrichment template inside the job', function () {
    Http::fake();
    $fixture = createEnrichFixture();
    seedPendingEnrichLedger($fixture['word']->id);
    PromptTemplate::query()->updateOrCreate(
        ['key' => PromptTemplates::ENRICHMENT_KEY],
        ['text' => 'Enrich the :language word :word for a :native speaker with strict JSON.'],
    );

    $captured = null;
    mockResolverForEnrichJob(null, $captured);

    (new FetchWordAiEnrichment($fixture['word']->id, $fixture['user']->id))->handle(app(AIModelResolver::class), app(WordAiEnrichmentService::class));

    expect($captured['instruction'])
        ->toBe('Enrich the English word bank for a Russian speaker with strict JSON.');
});

it('falls back to the monolingual code word-enrichment template when no admin row exists', function () {
    Http::fake();
    $fixture = createEnrichFixture();
    seedPendingEnrichLedger($fixture['word']->id);
    $captured = null;
    mockResolverForEnrichJob(null, $captured);

    (new FetchWordAiEnrichment($fixture['word']->id, $fixture['user']->id))->handle(app(AIModelResolver::class), app(WordAiEnrichmentService::class));

    // The monolingual dictionary contract (ticket 08): strict JSON, with
    // definitions / etymology notes / examples in the word's OWN language
    // and IPA transcriptions — only the translations point at the native
    // language. Substituted pins: :language → English, :native → Russian.
    expect($captured['instruction'])->toContain('ONLY a JSON object');
    expect($captured['instruction'])->toContain('English definitions');
    expect($captured['instruction'])->toContain('English etymology notes');
    expect($captured['instruction'])->toContain('in IPA');
    expect($captured['instruction'])->toContain('Russian');

    // The usage-label object form (ticket 09): the prompt names the closed
    // set and tells the model to label only when clearly so.
    expect($captured['instruction'])->toContain('"text"');
    expect($captured['instruction'])->toContain('"labels"');
    expect($captured['instruction'])->toContain('obsolete, archaic, dated, colloquial, rare, dialectal');
    expect($captured['instruction'])->toContain('otherwise the entry stays a plain string');
});

it('drops AI definitions in a foreign script at the merge — the import guard holds for AI content', function () {
    Http::fake();
    $fixture = createEnrichFixture();
    seedPendingEnrichLedger($fixture['word']->id);
    // The disobedient-model case the monolingual prompt exists for: an en
    // word answered with Cyrillic definitions. Only the own-script (Latin)
    // definition survives the merge; the Cyrillic etymology note lands —
    // etymologies stay unguarded, the import's deliberate parity.
    mockResolverForEnrichJob(json_encode([
        'translations' => ['банк'],
        'definitions' => ['домашнее животное семейства псовых', 'A financial institution.'],
        'etymologies' => ['От итальянского banca.'],
    ]));

    (new FetchWordAiEnrichment($fixture['word']->id, $fixture['user']->id))->handle(app(AIModelResolver::class), app(WordAiEnrichmentService::class));

    $definitions = Definition::query()->where('word_id', $fixture['word']->id)->orderBy('id')->get();
    expect($definitions)->toHaveCount(1);
    expect($definitions[0]->definition)->toBe('A financial institution.');
    expect($definitions[0]->source)->toBe('ai');
    expect(Etymology::query()
        ->where('word_id', $fixture['word']->id)
        ->where('etymology', 'От итальянского banca.')
        ->exists())->toBeTrue();
});

it('still finalizes the word SUCCEEDED when every AI definition dropped the script guard', function () {
    Http::fake();
    $fixture = createEnrichFixture();
    seedPendingEnrichLedger($fixture['word']->id);
    // All-Cyrillic definitions for the en word: the merge stores none of
    // them (the translations still link/stage), but the ask itself
    // succeeded — a succeeded ledger, never a failed one.
    mockResolverForEnrichJob(json_encode([
        'translations' => ['банк', 'прибанок'],
        'definitions' => ['домашнее животное семейства псовых', 'иностранный банк'],
    ]));

    (new FetchWordAiEnrichment($fixture['word']->id, $fixture['user']->id))->handle(app(AIModelResolver::class), app(WordAiEnrichmentService::class));

    expect(Definition::query()->where('word_id', $fixture['word']->id)->count())->toBe(0);
    $ledger = WordAiFetch::query()->where('word_id', $fixture['word']->id)->firstOrFail();
    expect($ledger->status)->toBe('succeeded');
    expect($ledger->succeeded_at)->not->toBeNull();
    expect($ledger->error)->toBeNull();
});

it('persists a labelled {text, labels} definition on the source=ai row, the payload shipping the labels', function () {
    Http::fake();
    $fixture = createEnrichFixture();
    seedPendingEnrichLedger($fixture['word']->id);
    // Mixed shapes: a plain-string entry (unlabelled) and an object entry
    // whose closed-set labels survive; the unknown slug drops.
    mockResolverForEnrichJob(json_encode([
        'translations' => ['банк'],
        'definitions' => [
            'A financial institution.',
            ['text' => 'The land alongside a river.', 'labels' => ['dated', 'no-such-slug']],
        ],
    ]));

    (new FetchWordAiEnrichment($fixture['word']->id, $fixture['user']->id))->handle(app(AIModelResolver::class), app(WordAiEnrichmentService::class));

    $definitions = Definition::query()->where('word_id', $fixture['word']->id)->orderBy('id')->get();
    expect($definitions)->toHaveCount(2);
    expect($definitions[0]->definition)->toBe('A financial institution.')
        ->and($definitions[0]->source)->toBe('ai')
        ->and($definitions[0]->labels)->toBeNull()
        ->and($definitions[1]->definition)->toBe('The land alongside a river.')
        ->and($definitions[1]->source)->toBe('ai')
        ->and($definitions[1]->labels)->toBe(['dated']);

    // The next open's payload ships {text, labels} objects — the stored
    // labels ride beside the text for the popup's chips
    // (popup-usage-labels ticket 01); a null column lands as [].
    $show = $this->actingAs($fixture['user'])
        ->getJson('/words/'.$fixture['word']->id)->json('data');
    expect($show['entries'][0]['definitions'])->toBe([
        ['text' => 'A financial institution.', 'labels' => []],
        ['text' => 'The land alongside a river.', 'labels' => ['dated']],
    ]);
});

it('drops a {text, labels} definition entirely when its text fails the script guard', function () {
    Http::fake();
    $fixture = createEnrichFixture();
    seedPendingEnrichLedger($fixture['word']->id);
    // The ticket-08 guard still reads the TEXT: a labelled foreign-script
    // entry drops labels and all, while the own-script labelled one lands.
    mockResolverForEnrichJob(json_encode([
        'definitions' => [
            ['text' => 'домашнее животное семейства псовых', 'labels' => ['colloquial']],
            ['text' => 'A financial institution.', 'labels' => ['archaic']],
        ],
    ]));

    (new FetchWordAiEnrichment($fixture['word']->id, $fixture['user']->id))->handle(app(AIModelResolver::class), app(WordAiEnrichmentService::class));

    $definitions = Definition::query()->where('word_id', $fixture['word']->id)->get();
    expect($definitions)->toHaveCount(1)
        ->and($definitions[0]->definition)->toBe('A financial institution.')
        ->and($definitions[0]->labels)->toBe(['archaic']);
});

it('validates the word id and requires authentication', function () {
    Queue::fake();
    $fixture = createEnrichFixture();
    mockResolverForEnrichDispatch();

    $this->postJson('/ai/word-enrich', ['word_id' => $fixture['word']->id])->assertUnauthorized();

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', [])
        ->assertJsonValidationErrors(['word_id']);

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['word_id' => 999999])
        ->assertJsonValidationErrors(['word_id']);

    Queue::assertNothingPushed();
});

it('rate-limits the word enrich endpoint after 20 requests per minute — the queued flow still burns its hits', function () {
    Queue::fake();
    $fixture = createEnrichFixture();
    mockResolverForEnrichDispatch();

    // Request 1 passes the ledger gate and dispatches (pending row left
    // behind); requests 2..20 run into the in-progress gate's clean 409 —
    // every one of them still burns a limiter hit, the throttle middleware
    // runs before the controller.
    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['word_id' => $fixture['word']->id])
        ->assertOk();

    for ($i = 2; $i <= 20; $i++) {
        $this->actingAs($fixture['user'])
            ->postJson('/ai/word-enrich', ['word_id' => $fixture['word']->id])
            ->assertStatus(409);
    }

    // Exactly one ask ever dispatched for the word — the 409s dispatched
    // nothing.
    Queue::assertPushedTimes(FetchWordAiEnrichment::class, 1);

    // Request 21: blocked by the limiter before the controller.
    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['word_id' => $fixture['word']->id])
        ->assertTooManyRequests();
});
