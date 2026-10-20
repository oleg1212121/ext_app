<?php

use App\Classes\AIModelResolver;
use App\Exceptions\AiProviderException;
use App\Http\Controllers\ReadingAiController;
use App\Models\Definition;
use App\Models\Etymology;
use App\Models\Example;
use App\Models\Form;
use App\Models\Language;
use App\Models\PromptTemplate;
use App\Models\Transcription;
use App\Models\TranscriptionType;
use App\Models\Word;
use App\Models\WordAiFetch;
use App\Models\WordTranslation;
use App\Support\PromptTemplates;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;

const AI_ENRICH_MODEL_MOCK = ['id' => 7, 'key' => 'openrouter:google/gemini-3-flash-preview', 'label' => 'Gemini Flash'];

/**
 * A sparse EN word (no content at all), the acting user's native language
 * Russian, and one EXISTING ru word («банк») the AI's translation candidate
 * can link to — plus a candidate no ru row carries, which must stage into
 * the source word's translations JSON instead of creating anything.
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
    $user = nativeRuUser();

    return ['word' => $enWord, 'ruWord' => $ruWord, 'user' => $user];
}

function enrichReply(): string
{
    // Fenced + prose-wrapped on purpose: the endpoint must take the lenient
    // path end to end.
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
 * every mock whose ask is reachable needs it, the controller splits the
 * ledger's provider/model through this API.
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

function mockResolverForEnrich(?string $reply = null, ?string &$captured = null): MockInterface
{
    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('resolveExplanationModel')->andReturn(AI_ENRICH_MODEL_MOCK);
    $mock->shouldReceive('parseModelString')->andReturnUsing(enrichModelSplit());
    $mock->shouldReceive('ask')->andReturnUsing(function ($model, $instruction, $question) use ($reply, &$captured) {
        $captured = ['model' => $model, 'instruction' => $instruction, 'question' => $question];

        return $reply ?? enrichReply();
    });
    app()->instance(AIModelResolver::class, $mock);

    return $mock;
}

it('enriches a sparse word end to end with the faked AI: content rows, link-only translations, succeeded ledger, fresh payload', function () {
    $fixture = createEnrichFixture();
    $captured = null;
    mockResolverForEnrich(null, $captured);

    $response = $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['word_id' => $fixture['word']->id]);

    $response->assertOk();

    $payload = $response->json('data');
    // The popup swaps this payload in place of the initial fetch's, so the
    // shape must match GET /words/{word} exactly, and the enriched content
    // must already ride inside the entries.
    $show = $this->actingAs($fixture['user'])
        ->getJson('/words/'.$fixture['word']->id)->json('data');
    expect(array_keys($payload))->toEqual(array_keys($show));
    expect($payload['id'])->toBe($fixture['word']->id);
    expect($payload['ai_asked'])->toBeTrue();
    expect($payload['entries'][0]['definitions'])->toContain('A financial institution.');
    expect($payload['entries'][0]['transcriptions'][0]['value'])->toBe('bæŋk');

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

    // The ledger locks the word on success.
    $ledger = WordAiFetch::query()->where('word_id', $fixture['word']->id)->firstOrFail();
    expect($ledger->status)->toBe('succeeded');
    expect($ledger->attempts)->toBe(1);
    expect($ledger->provider)->toBe('openrouter');
    expect($ledger->model)->toBe('google/gemini-3-flash-preview');
    expect($ledger->asked_at)->not->toBeNull();
    expect($ledger->succeeded_at)->not->toBeNull();
    expect($ledger->error)->toBeNull();

    // The word was touched, so ticket 02's completeness recalculated over
    // the new content: links (2) + definitions + transcriptions + examples +
    // etymologies = 6, no frequency rank.
    expect($word->completeness)->toBe(6);
    expect($word->completeness_at)->not->toBeNull();

    // The prompt went through the enrichment template with the native
    // language substituted; the question names the word.
    expect($captured['instruction'])->toContain('Russian');
    expect($captured['instruction'])->toContain('bank');
    expect($captured['question'])->toContain('bank');
    expect($captured['model'])->toBe('openrouter:google/gemini-3-flash-preview');
});

it('rejects an already-asked word without touching the AI again', function () {
    $fixture = createEnrichFixture();
    WordAiFetch::query()->create([
        'word_id' => $fixture['word']->id,
        'status' => WordAiFetch::STATUS_SUCCEEDED,
        'attempts' => 1,
        'asked_at' => now(),
        'succeeded_at' => now(),
    ]);
    Definition::query()->create(['word_id' => $fixture['word']->id, 'definition' => 'A financial institution.']);

    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('resolveExplanationModel')->andReturn(AI_ENRICH_MODEL_MOCK);
    $mock->shouldReceive('ask')->never();
    app()->instance(AIModelResolver::class, $mock);

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['word_id' => $fixture['word']->id])
        ->assertStatus(409)
        ->assertJsonPath('error', 'AI enrichment has already been completed for this word.');

    expect(Definition::query()->where('word_id', $fixture['word']->id)->count())->toBe(1);
    expect(WordAiFetch::query()->where('word_id', $fixture['word']->id)->where('attempts', 1)->exists())->toBeTrue();
});

it('surfaces a missing provider key as 403 guidance and keeps the ask retryable', function () {
    $fixture = createEnrichFixture();

    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('resolveExplanationModel')->andReturn(AI_ENRICH_MODEL_MOCK);
    $mock->shouldReceive('parseModelString')->andReturnUsing(enrichModelSplit());
    $mock->shouldReceive('ask')->once()->andThrow(
        new AiProviderException('You have not added an API key for this provider.', 403),
    );
    app()->instance(AIModelResolver::class, $mock);

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['word_id' => $fixture['word']->id])
        ->assertForbidden()
        ->assertJsonPath('error', 'You have not added an API key for this provider.');

    // Failed, not locked — and nothing was persisted.
    $ledger = WordAiFetch::query()->where('word_id', $fixture['word']->id)->firstOrFail();
    expect($ledger->status)->toBe('failed');
    expect($ledger->attempts)->toBe(1);
    expect($ledger->error)->toBe('You have not added an API key for this provider.');
    expect($ledger->succeeded_at)->toBeNull();
    expect(Definition::query()->where('word_id', $fixture['word']->id)->count())->toBe(0);
});

it('retries a failed ask with attempts++ and locks on the first success', function () {
    $fixture = createEnrichFixture();

    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('resolveExplanationModel')->andReturn(AI_ENRICH_MODEL_MOCK);
    $mock->shouldReceive('parseModelString')->andReturnUsing(enrichModelSplit());
    $mock->shouldReceive('ask')->once()->andThrow(
        new AiProviderException('The AI service is busy. Please try again in a moment.', 429),
    );
    $mock->shouldReceive('ask')->once()->andReturn(enrichReply());
    app()->instance(AIModelResolver::class, $mock);

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['word_id' => $fixture['word']->id])
        ->assertTooManyRequests();

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['word_id' => $fixture['word']->id])
        ->assertOk();

    $ledger = WordAiFetch::query()->where('word_id', $fixture['word']->id)->firstOrFail();
    expect($ledger->status)->toBe('succeeded');
    expect($ledger->attempts)->toBe(2);
    expect($ledger->error)->toBeNull();

    // One retried attempt produced each row exactly once.
    expect(Definition::query()->where('word_id', $fixture['word']->id)->count())->toBe(2);
});

it('answers 400 guidance and records no attempt when no AI model is usable', function () {
    $fixture = createEnrichFixture();

    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('resolveExplanationModel')->once()->andReturnNull();
    $mock->shouldReceive('ask')->never();
    app()->instance(AIModelResolver::class, $mock);

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['word_id' => $fixture['word']->id])
        ->assertStatus(400)
        ->assertJsonPath('error', 'Choose an AI model in your profile settings.');

    expect(WordAiFetch::query()->where('word_id', $fixture['word']->id)->exists())->toBeFalse();
});

it('finalizes an unparseable reply as a retryable failure', function () {
    $fixture = createEnrichFixture();
    mockResolverForEnrich('I am sorry, I cannot help with that.');

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['word_id' => $fixture['word']->id])
        ->assertStatus(502)
        ->assertJsonPath('error', 'The AI reply could not be parsed as strict JSON.');

    $ledger = WordAiFetch::query()->where('word_id', $fixture['word']->id)->firstOrFail();
    expect($ledger->status)->toBe('failed');
    expect($ledger->attempts)->toBe(1);
    expect(Definition::query()->where('word_id', $fixture['word']->id)->count())->toBe(0);
});

it('locks the word when a parsed reply dedupes to nothing new — the ask itself succeeded', function () {
    $fixture = createEnrichFixture();
    Definition::query()->create(['word_id' => $fixture['word']->id, 'definition' => 'A financial institution.']);
    WordTranslation::link($fixture['word']->id, $fixture['ruWord']->id);

    $dupReply = json_encode([
        'translations' => ['банк'],
        'definitions' => ['A financial institution.'],
    ]);
    mockResolverForEnrich($dupReply);

    $response = $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['word_id' => $fixture['word']->id]);

    // An already-rich word must not stay re-askable forever (each pass
    // spends tokens): a successfully parsed reply finalizes the ledger
    // SUCCEEDED even when the merge lands nothing new — the user's decision
    // is one ask per word, locked by success.
    $response->assertOk();
    expect($response->json('data.ai_asked'))->toBeTrue();

    expect(Definition::query()->where('word_id', $fixture['word']->id)->count())->toBe(1);
    $ledger = WordAiFetch::query()->where('word_id', $fixture['word']->id)->firstOrFail();
    expect($ledger->status)->toBe('succeeded');
    expect($ledger->succeeded_at)->not->toBeNull();
    expect($ledger->error)->toBeNull();
});

it('takes over a stale pending row past the grace window and proceeds with the ask', function () {
    $fixture = createEnrichFixture();
    // A fatal/timeout between the pending save and the finalize strands a
    // pending row forever — pending is not a lock (spec decision 4: locked
    // by success only). This one is minutes past the grace window.
    WordAiFetch::query()->create([
        'word_id' => $fixture['word']->id,
        'status' => WordAiFetch::STATUS_PENDING,
        'provider' => 'openrouter',
        'model' => 'google/gemini-3-flash-preview',
        'attempts' => 1,
        'asked_at' => now()->subMinutes(ReadingAiController::PENDING_STALE_AFTER_MINUTES + 5),
    ]);

    $captured = null;
    mockResolverForEnrich(null, $captured);

    $response = $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['word_id' => $fixture['word']->id]);

    $response->assertOk();
    expect($response->json('data.ai_asked'))->toBeTrue();

    // The retry took the SAME row over: attempts advanced, the ask ran.
    $ledger = WordAiFetch::query()->where('word_id', $fixture['word']->id)->sole();
    expect($ledger->status)->toBe('succeeded');
    expect($ledger->attempts)->toBe(2);
    expect($ledger->succeeded_at)->not->toBeNull();
    expect(Definition::query()->where('word_id', $fixture['word']->id)->count())->toBe(2);
});

it('builds the enrich payload from the clicked surface: is_form and form_of survive the live swap', function () {
    createWordClasses();
    $go = createWord('en', 'go', 'verb');
    $wend = createWord('en', 'wend', 'verb');
    // The forms table maps «went» onto both verbs — the popup's family
    // resolves from the CLICKED surface, and the enrich response must agree
    // with the initial GET (it passed l_word before, so the
    // "«went» — form of «go»" line vanished on the live swap).
    Form::query()->create(['word_id' => $go->id, 'form' => 'went', 'l_word' => 'went']);
    Form::query()->create(['word_id' => $wend->id, 'form' => 'went', 'l_word' => 'went']);
    $user = nativeRuUser();

    $captured = null;
    mockResolverForEnrich(null, $captured);

    $response = $this->actingAs($user)
        ->postJson('/ai/word-enrich', ['word_id' => $go->id, 'surface' => 'Went']);

    $response->assertOk();
    // The surface normalizes like the word-show endpoint's (lowercase +
    // combining marks stripped) before it compares against the bound
    // headword's key: an inflected surface stays a form on the swap-in.
    expect($response->json('data.is_form'))->toBeTrue();
    expect($response->json('data.form_of'))->toBe(['wend']);
    expect($response->json('data.word'))->toBe('go');

    // No surface, no drift: the payload falls back to the word's own key.
    $bare = $this->actingAs($user)
        ->postJson('/ai/word-enrich', ['word_id' => createWord('en', 'jump', 'verb')->id]);
    $bare->assertOk();
    expect($bare->json('data.is_form'))->toBeFalse();
});

it('dedupes exact text: existing rows keep their imported provenance, only new rows carry source=ai', function () {
    $fixture = createEnrichFixture();
    Definition::query()->create(['word_id' => $fixture['word']->id, 'definition' => 'A financial institution.']);

    mockResolverForEnrich();

    $response = $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['word_id' => $fixture['word']->id]);

    $response->assertOk();

    $definitions = Definition::query()->where('word_id', $fixture['word']->id)->orderBy('id')->get();
    expect($definitions)->toHaveCount(2);
    expect($definitions[0]->source)->toBeNull();
    expect($definitions[1]->definition)->toBe('The land alongside a river.');
    expect($definitions[1]->source)->toBe('ai');
});

it('caps and truncates what it persists: 8 definitions, 4 of the rest, 500-char definitions', function () {
    $fixture = createEnrichFixture();

    $greedy = json_encode([
        'definitions' => array_map(fn (int $i): string => "Definition {$i}.", range(1, 15)),
        'examples' => array_map(fn (int $i): string => "Example {$i}.", range(1, 9)),
        // Distinct prefixes so the dedupe cannot collapse them before the
        // truncation is measured.
        'etymologies' => array_map(fn (int $i): string => "Root {$i}: ".str_repeat('e', 1200), range(1, 9)),
    ]);
    mockResolverForEnrich($greedy);

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['word_id' => $fixture['word']->id])
        ->assertOk();

    expect(Definition::query()->where('word_id', $fixture['word']->id)->count())->toBe(8);
    expect(Example::query()->where('word_id', $fixture['word']->id)->count())->toBe(4);
    // Every 1200-char etymology truncated to the etymologies column limit.
    expect(Etymology::query()->where('word_id', $fixture['word']->id)->count())->toBe(4);
    Etymology::query()->where('word_id', $fixture['word']->id)->get()
        ->each(fn (Etymology $etymology) => expect($etymology->etymology)->toHaveLength(1000));
});

it('assembles the instruction from the admin-edited word-enrichment template', function () {
    $fixture = createEnrichFixture();
    PromptTemplate::query()->updateOrCreate(
        ['key' => PromptTemplates::ENRICHMENT_KEY],
        ['text' => 'Enrich the :language word :word for a :native speaker with strict JSON.'],
    );

    $captured = null;
    mockResolverForEnrich(null, $captured);

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['word_id' => $fixture['word']->id])
        ->assertOk();

    expect($captured['instruction'])
        ->toBe('Enrich the English word bank for a Russian speaker with strict JSON.');
});

it('falls back to the monolingual code word-enrichment template when no admin row exists', function () {
    $fixture = createEnrichFixture();
    $captured = null;
    mockResolverForEnrich(null, $captured);

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['word_id' => $fixture['word']->id])
        ->assertOk();

    // The monolingual dictionary contract (ticket 08): strict JSON, with
    // definitions / etymology notes / examples in the word's OWN language
    // and IPA transcriptions — only the translations point at the native
    // language. Substituted pins: :language → English, :native → Russian.
    expect($captured['instruction'])->toContain('ONLY a JSON object');
    expect($captured['instruction'])->toContain('English definitions');
    expect($captured['instruction'])->toContain('English etymology notes');
    expect($captured['instruction'])->toContain('in IPA');
    expect($captured['instruction'])->toContain('Russian');
});

it('drops AI definitions in a foreign script at the merge — the import guard holds for AI content', function () {
    Http::fake();
    $fixture = createEnrichFixture();
    // The disobedient-model case the monolingual prompt exists for: an en
    // word answered with Cyrillic definitions. Only the own-script (Latin)
    // definition survives the merge; the Cyrillic etymology note lands —
    // etymologies stay unguarded, the import's deliberate parity.
    mockResolverForEnrich(json_encode([
        'translations' => ['банк'],
        'definitions' => ['домашнее животное семейства псовых', 'A financial institution.'],
        'etymologies' => ['От итальянского banca.'],
    ]));

    $response = $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['word_id' => $fixture['word']->id]);

    $response->assertOk();

    $definitions = Definition::query()->where('word_id', $fixture['word']->id)->orderBy('id')->get();
    expect($definitions)->toHaveCount(1);
    expect($definitions[0]->definition)->toBe('A financial institution.');
    expect($definitions[0]->source)->toBe('ai');
    expect(Etymology::query()
        ->where('word_id', $fixture['word']->id)
        ->where('etymology', 'От итальянского banca.')
        ->exists())->toBeTrue();
});

it('still locks the word SUCCEEDED when every AI definition dropped the script guard', function () {
    Http::fake();
    $fixture = createEnrichFixture();
    // All-Cyrillic definitions for the en word: the merge stores none of
    // them (the translations still link/stage), but the ask itself
    // succeeded — 200 and a succeeded ledger, never a 502.
    mockResolverForEnrich(json_encode([
        'translations' => ['банк', 'прибанок'],
        'definitions' => ['домашнее животное семейства псовых', 'иностранный банк'],
    ]));

    $response = $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['word_id' => $fixture['word']->id]);

    $response->assertOk();
    expect($response->json('data.ai_asked'))->toBeTrue();

    expect(Definition::query()->where('word_id', $fixture['word']->id)->count())->toBe(0);
    $ledger = WordAiFetch::query()->where('word_id', $fixture['word']->id)->firstOrFail();
    expect($ledger->status)->toBe('succeeded');
    expect($ledger->succeeded_at)->not->toBeNull();
    expect($ledger->error)->toBeNull();
});

it('validates the word id and requires authentication', function () {
    $fixture = createEnrichFixture();
    mockResolverForEnrich();

    $this->postJson('/ai/word-enrich', ['word_id' => $fixture['word']->id])->assertUnauthorized();

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', [])
        ->assertJsonValidationErrors(['word_id']);

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['word_id' => 999999])
        ->assertJsonValidationErrors(['word_id']);
});

it('rate-limits the word enrich endpoint after 20 requests per minute', function () {
    $fixture = createEnrichFixture();
    WordAiFetch::query()->create([
        'word_id' => $fixture['word']->id,
        'status' => WordAiFetch::STATUS_FAILED,
        'attempts' => 1,
    ]);

    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('resolveExplanationModel')->andReturn(AI_ENRICH_MODEL_MOCK);
    $mock->shouldReceive('parseModelString')->andReturnUsing(enrichModelSplit());
    // Every attempt fails with the provider's own 429 (finalized failed, so
    // the lock never engages) and the first 20 requests really do reach the
    // resolver; the 21st is blocked by the limiter before it.
    $mock->shouldReceive('ask')->andThrow(
        new AiProviderException('The AI service is busy. Please try again in a moment.', 429),
    );
    app()->instance(AIModelResolver::class, $mock);

    for ($i = 1; $i <= 20; $i++) {
        $this->actingAs($fixture['user'])
            ->postJson('/ai/word-enrich', ['word_id' => $fixture['word']->id])
            ->assertTooManyRequests();
    }

    $this->actingAs($fixture['user'])
        ->postJson('/ai/word-enrich', ['word_id' => $fixture['word']->id])
        ->assertTooManyRequests();
});
