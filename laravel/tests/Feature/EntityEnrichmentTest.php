<?php

use App\Classes\EntityTextHasher;
use App\Classes\SentenceEnrichmentService;
use App\Jobs\EnrichEntitySentences;
use App\Jobs\FinalizeEntityDerivations;
use App\Models\Entity;
use App\Models\EntitySentence;
use App\Models\EntityWord;
use App\Models\Form;
use App\Models\Language;
use App\Models\Transcription;
use App\Models\TranscriptionType;
use App\Models\User;
use App\Models\Word;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

if (! function_exists('approvedUser')) {
    function approvedUser(): User
    {
        return User::factory()->create(['is_approved' => true]);
    }
}

// Guard: tests that reach the python service register their own fake — a bare
// Http::fake() here would shadow them (stub callbacks resolve
// first-registered-wins), so stray-request prevention is the guard instead.
beforeEach(fn () => Http::preventStrayRequests());

function enrichableEntity(string $code, array $sentences): Entity
{
    $entity = createEntity($code);

    foreach ($sentences as $index => $content) {
        EntitySentence::create(['entity_id' => $entity->id, 'content' => $content, 'order' => ($index + 1) * 1024]);
    }

    return $entity->refresh();
}

/**
 * Fake /enrich echoing each sentence back with a fixed stressed variant.
 */
function fakeEnrichResponse(): void
{
    Http::fake(function (Request $request) {
        $results = collect($request->data()['sentences'] ?? [])
            ->map(fn (array $sentence): array => [
                'id' => $sentence['id'],
                'stressed' => $sentence['text'].'́',
                'phrasal_verbs' => $request->data()['language'] === 'en' ? [] : null,
                'intonation' => ['nuclear' => null, 'terminal' => 'fall'],
            ])
            ->all();

        return Http::response(['results' => $results]);
    });
}

it('enriches a chunk without bumping sentence timestamps or entity staleness markers', function () {
    fakeEnrichResponse();

    $entity = enrichableEntity('ru', ['Она произносит это красиво.']);
    $entity->update(['text_hash' => 'hash-before', 'sentences_updated_at' => now()->subDay()]);
    $before = EntitySentence::query()->where('entity_id', $entity->id)->first();
    $sentenceUpdatedAt = $before->updated_at;
    $entityUpdatedAt = $entity->refresh()->sentences_updated_at;

    // Guard: the quiet-write invariant is the point of this test (ADR 0052) —
    // bumping updated_at would make enrichment mark the entity stale forever.
    expect($sentenceUpdatedAt)->not->toBeNull();

    $service = SentenceEnrichmentService::create();
    expect($service->enrichChunk($entity, collect([$before])))->toBe(1);

    $after = $before->refresh();
    expect($after->stressed_content)->toBe('Она произносит это красиво.́')
        ->and($after->phrasal_verbs)->toBeNull()
        ->and($after->intonation)->toBe(['nuclear' => null, 'terminal' => 'fall'])
        // Quiet writes: no updated_at bump, no sentences_updated_at bump, no
        // text_hash change — content itself is never touched.
        ->and($after->updated_at->equalTo($sentenceUpdatedAt))->toBeTrue()
        ->and($entity->refresh()->sentences_updated_at->equalTo($entityUpdatedAt))->toBeTrue()
        ->and($entity->refresh()->text_hash)->toBe('hash-before');
});

it('runs the whole entity through the job and stamps enriched_at', function () {
    fakeEnrichResponse();

    $entity = enrichableEntity('en', ['One.', 'Two.', 'Three.']);
    EntitySentence::create(['entity_id' => $entity->id, 'content' => '', 'order' => 999999]);

    Bus::fake();
    (new EnrichEntitySentences($entity->id))->handle();

    expect($entity->refresh()->enriched_at)->not->toBeNull()
        // The empty sentence keeps null columns (python rejects empty text)
        // but still counts as processed.
        ->and(EntitySentence::query()->where('entity_id', $entity->id)->whereNull('stressed_content')->count())->toBe(1)
        ->and(EntitySentence::query()->where('entity_id', $entity->id)->count())->toBe(4);
});

it('marks entities the pipeline cannot enrich as done instead of leaving them stale', function () {
    $french = Language::query()->create(['code' => 'fr', 'name' => 'French', 'is_enabled' => true, 'sort_order' => 5]);
    $work = createWork(['original_language_id' => $french->id]);
    $frenchEntity = Entity::query()->create(['work_id' => $work->id, 'language_id' => $french->id, 'name' => 'FR']);
    EntitySentence::create(['entity_id' => $frenchEntity->id, 'content' => 'Bonjour.', 'order' => 1024]);

    $empty = enrichableEntity('en', []);
    $enrichable = enrichableEntity('ru', ['Привет.']);

    Bus::fake();
    $this->artisan('entities:enrich')->assertSuccessful();

    expect($frenchEntity->refresh()->enriched_at)->not->toBeNull()
        ->and($empty->refresh()->enriched_at)->not->toBeNull()
        ->and($enrichable->refresh()->enriched_at)->toBeNull();

    Bus::assertDispatched(EnrichEntitySentences::class, fn (EnrichEntitySentences $job) => $job->entityId === $enrichable->id);
});

it('skips freshly enriched entities in the sweep', function () {
    $fresh = enrichableEntity('ru', ['Привет.']);
    $fresh->update(['enriched_at' => now()]);

    Bus::fake();
    $this->artisan('entities:enrich')->assertSuccessful();

    Bus::assertNotDispatched(EnrichEntitySentences::class);
});

it('re-enriches an entity whose sentences changed after enrichment', function () {
    $entity = enrichableEntity('ru', ['Привет.']);
    $entity->update(['enriched_at' => now()->subDay()]);

    $service = SentenceEnrichmentService::create();
    expect($service->isStale($entity->refresh()))->toBeTrue();

    // A content edit after enrichment flips staleness (the touch bumps
    // sentences_updated_at past enriched_at).
    EntitySentence::query()->where('entity_id', $entity->id)->first()->update(['content' => 'Приветик.']);
    expect($service->isStale($entity->refresh()))->toBeTrue();
});

it('dispatches enrichment at the end of the upload pipeline', function () {
    Bus::fake();
    Http::fake();

    $entity = enrichableEntity('en', ['One.']);
    $entity->update(['signature' => json_encode([1.0]), 'status' => 'completed']);

    (new FinalizeEntityDerivations($entity->id, 'texts/test.txt'))->handle(
        app(EntityTextHasher::class),
    );

    Bus::assertDispatched(EnrichEntitySentences::class, fn (EnrichEntitySentences $job) => (int) $job->entityId === $entity->id);
});

it('sends dictionary hints with the enrichment request', function () {
    $captured = null;
    Http::fake(function (Request $request) use (&$captured) {
        $captured = $request->data();

        return Http::response(['results' => []]);
    });

    $entity = enrichableEntity('en', ['She gave up smoking.']);
    $give = createWord('en', 'give', 'verb');
    createWord('en', 'up', 'noun');
    Word::query()->create([
        'word' => 'give up',
        'l_word' => 'give up',
        'language_id' => $give->language_id,
        'word_class_id' => $give->word_class_id,
    ]);

    // "gave" links to "give" through the forms table + the entity word list.
    Form::query()->create([
        'word_id' => $give->id,
        'form' => 'gave',
        'l_word' => 'gave',
    ]);
    EntityWord::query()->create([
        'entity_id' => $entity->id,
        'l_word' => 'gave',
        'token' => 'gave',
        'count' => 1,
        'word_id' => $give->id,
    ]);

    SentenceEnrichmentService::create()->enrichChunk(
        $entity->refresh(),
        EntitySentence::query()->where('entity_id', $entity->id)->get(),
    );

    $gave = collect($captured['sentences'][0]['tokens'] ?? [])->firstWhere('surface', 'gave');
    expect($captured['language'])->toBe('en')
        ->and($captured['phrasal_lexicon'])->toContain('give up')
        ->and($gave)->not->toBeNull()
        ->and($gave['cls'])->toBe('verb')
        ->and($gave['lemma'])->toBe('give');
});

it('ships stressed variants and intonation to the reader page', function () {
    $user = approvedUser();

    $entity = enrichableEntity('ru', ['Она произносит это красиво.']);
    $sentence = EntitySentence::query()->where('entity_id', $entity->id)->first();
    EntitySentence::query()->whereKey($sentence->id)->toBase()->update([
        'stressed_content' => 'Она́ произно́сит э́то краси́во.',
        'intonation' => json_encode(['nuclear' => null, 'terminal' => 'fall']),
    ]);

    $response = $this->actingAs($user)->get("/reader/{$entity->id}")->assertOk();

    $props = $response->inertiaPage()['props'];
    expect($props['stressedRows'][0][0])->toBe('Она́ произно́сит э́то краси́во.')
        ->and($props['intonationRows'][0][0])->toBe(['fall'])
        ->and($props['stressMarks'])->toBeFalse();

    // Saved preference rides along.
    $user->settings()->updateOrCreate(
        ['user_id' => $user->id],
        ['ui_settings' => ['reader' => ['stress_marks' => true]]],
    );
    // The factory pre-loads the settings relation; drop the stale copy so the
    // request resolves the just-updated row.
    $user->unsetRelation('settings');
    $saved = $this->actingAs($user)->get("/reader/{$entity->id}")->assertOk()->inertiaPage()['props']['stressMarks'];
    expect($saved)->toBeTrue();
});

it('strips stress marks from word popup surfaces', function () {
    $user = approvedUser();

    $word = createWord('ru', 'кот', 'noun');
    $word->update(['l_word' => 'кот']);

    $response = $this->actingAs($user)->getJson("/words/{$word->id}?surface=".urlencode('ко́т'))
        ->assertOk()
        ->json('data');

    // The stressed surface must compare equal to the mark-free l_word, so the
    // "form of" line stays hidden (ADR 0052).
    expect($response['is_form'])->toBeFalse();
});

it('enriches english ipa hints through transcriptions', function () {
    $captured = null;
    Http::fake(function (Request $request) use (&$captured) {
        $captured = $request->data();

        return Http::response(['results' => []]);
    });

    $entity = enrichableEntity('en', ['Dictionary.']);
    $dictionary = createWord('en', 'dictionary', 'noun');
    $type = TranscriptionType::query()->firstOrCreate(
        ['language_id' => $dictionary->language_id, 'slug' => 'ipa'],
        ['title' => 'IPA', 'description' => 'test'],
    );
    Transcription::query()->create([
        'word_id' => $dictionary->id,
        'transcription_type_id' => $type->id,
        'transcription' => '/ˈdɪk.ʃə.nə.ɹi/',
    ]);

    SentenceEnrichmentService::create()->enrichChunk(
        $entity,
        EntitySentence::query()->where('entity_id', $entity->id)->get(),
    );

    $token = collect($captured['sentences'][0]['tokens'] ?? [])->firstWhere('surface', 'Dictionary');
    expect($token['ipa'])->toBe(['/ˈdɪk.ʃə.nə.ɹi/'])
        ->and($token['cls'])->toBe('noun')
        ->and($token['lemma'])->toBe('dictionary');
});

it('prefers stress-bearing ipa variants when capping variants per word', function () {
    $captured = null;
    Http::fake(function (Request $request) use (&$captured) {
        $captured = $request->data();

        return Http::response(['results' => []]);
    });

    $entity = enrichableEntity('en', ['Dictionary.']);
    $dictionary = createWord('en', 'dictionary', 'noun');
    $type = TranscriptionType::query()->firstOrCreate(
        ['language_id' => $dictionary->language_id, 'slug' => 'ipa'],
        ['title' => 'IPA', 'description' => 'test'],
    );

    // Only the last-inserted variant carries the primary stress mark: the
    // stress-first ordering must surface it despite the 3-variant cap, with
    // the unstressed variants following in id order.
    foreach (['/dɪkʃənəɹi/', '/dɪkʃəneri/', '/dɪk.ʃə.nə.ɹi/', '/ˈdɪk.ʃə.nə.ɹi/'] as $transcription) {
        Transcription::query()->create([
            'word_id' => $dictionary->id,
            'transcription_type_id' => $type->id,
            'transcription' => $transcription,
        ]);
    }

    SentenceEnrichmentService::create()->enrichChunk(
        $entity,
        EntitySentence::query()->where('entity_id', $entity->id)->get(),
    );

    $token = collect($captured['sentences'][0]['tokens'] ?? [])->firstWhere('surface', 'Dictionary');
    expect($token['ipa'])->toBe([
        '/ˈdɪk.ʃə.nə.ɹi/',
        '/dɪkʃənəɹi/',
        '/dɪkʃəneri/',
    ]);
});

it('sends per-part ipa for hyphenated compounds the dictionary lacks', function () {
    $captured = null;
    Http::fake(function (Request $request) use (&$captured) {
        $captured = $request->data();

        return Http::response(['results' => []]);
    });

    $entity = enrichableEntity('en', ['A seven-sided die.']);
    $seven = createWord('en', 'seven', 'noun');
    $type = TranscriptionType::query()->firstOrCreate(
        ['language_id' => $seven->language_id, 'slug' => 'ipa'],
        ['title' => 'IPA', 'description' => 'test'],
    );
    Transcription::query()->create([
        'word_id' => $seven->id,
        'transcription_type_id' => $type->id,
        'transcription' => '/ˈsɛvən/',
    ]);

    SentenceEnrichmentService::create()->enrichChunk(
        $entity,
        EntitySentence::query()->where('entity_id', $entity->id)->get(),
    );

    $token = collect($captured['sentences'][0]['tokens'] ?? [])->firstWhere('surface', 'seven-sided');
    expect($token['ipa'])->toBeNull()
        ->and($token['parts'])->toBe([
            ['surface' => 'seven', 'ipa' => ['/ˈsɛvən/']],
            ['surface' => 'sided', 'ipa' => null],
        ]);
});
