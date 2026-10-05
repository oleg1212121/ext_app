<?php

use App\Classes\Enrichment\EnglishPhrasalVerbEnricher;
use App\Classes\Enrichment\EnricherRegistry;
use App\Classes\Enrichment\RussianStressEnricher;
use App\Classes\EntityTextHasher;
use App\Classes\SentenceEnrichmentService;
use App\Jobs\EnrichEntitySentences;
use App\Jobs\FinalizeEntityDerivations;
use App\Models\Entity;
use App\Models\EntitySentence;
use App\Models\EntityWord;
use App\Models\Form;
use App\Models\Language;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\Transcription;
use App\Models\TranscriptionType;
use App\Models\User;
use App\Models\Word;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

if (! function_exists('approvedUser')) {
    function approvedUser(): User
    {
        return User::factory()->create(['is_approved' => true]);
    }
}

// Guard: tests that reach the python service never touch the wire — the
// fake client is bound for every test here, and anything that still tries
// an HTTP call fails the run.
beforeEach(function () {
    Http::preventStrayRequests();
    fakePython();
});

function enrichableEntity(string $code, array $sentences): Entity
{
    $entity = createEntity($code);

    foreach ($sentences as $index => $content) {
        EntitySentence::create(['entity_id' => $entity->id, 'content' => $content, 'order' => ($index + 1) * 1024]);
    }

    return $entity->refresh();
}

it('enriches a chunk without bumping sentence timestamps or entity staleness markers', function () {
    $entity = enrichableEntity('ru', ['Она произносит это красиво.']);
    $entity->update(['text_hash' => 'hash-before', 'sentences_updated_at' => now()->subDay()]);
    $before = EntitySentence::query()->where('entity_id', $entity->id)->first();
    $sentenceUpdatedAt = $before->updated_at;
    $entityUpdatedAt = $entity->refresh()->sentences_updated_at;

    // Guard: the quiet-write invariant is the point of this test (ADR 0052) —
    // bumping updated_at would make enrichment mark the entity stale forever.
    expect($sentenceUpdatedAt)->not->toBeNull();

    $service = app(SentenceEnrichmentService::class);
    $result = $service->enrichChunk($entity, collect([$before]));
    expect($result['written'])->toBe(1)
        // The declared python versions ride the chunk result (ADR 0067).
        ->and($result['versions'])->toBe(['ru_stress' => 1]);

    $after = $before->refresh();
    expect($after->stressed_content)->toBe('Она произносит это красиво.́')
        ->and($after->phrasal_verbs)->toBeNull()
        // Quiet writes: no updated_at bump, no sentences_updated_at bump, no
        // text_hash change — content itself is never touched.
        ->and($after->updated_at->equalTo($sentenceUpdatedAt))->toBeTrue()
        ->and($entity->refresh()->sentences_updated_at->equalTo($entityUpdatedAt))->toBeTrue()
        ->and($entity->refresh()->text_hash)->toBe('hash-before');
});

it('maps enrichers to languages declaratively', function () {
    $registry = new EnricherRegistry;

    expect(collect($registry->forLanguage('ru'))->map->key()->all())->toBe(['ru_stress'])
        ->and(collect($registry->forLanguage('en'))->map->key()->all())->toBe(['en_stress', 'en_phrasal'])
        ->and($registry->forLanguage('fr'))->toBe([])
        ->and(collect($registry->languages())->sort()->values()->all())->toBe(['en', 'ru']);
});

it('runs the whole entity through the job and stamps every enricher', function () {
    $entity = enrichableEntity('en', ['One.', 'Two.', 'Three.']);
    EntitySentence::create(['entity_id' => $entity->id, 'content' => '', 'order' => 999999]);

    Bus::fake();
    (new EnrichEntitySentences($entity->id))->handle();

    expect(array_keys($entity->refresh()->enrichment_stamps ?? []))->toBe(['en_stress', 'en_phrasal'])
        // The empty sentence keeps null columns (python rejects empty text)
        // but still counts as processed.
        ->and(EntitySentence::query()->where('entity_id', $entity->id)->whereNull('stressed_content')->count())->toBe(1)
        ->and(EntitySentence::query()->where('entity_id', $entity->id)->count())->toBe(4);
});

it('never dispatches or stamps entities the pipeline cannot enrich', function () {
    $french = Language::query()->create(['code' => 'fr', 'name' => 'French', 'is_enabled' => true, 'sort_order' => 5]);
    $work = createWork(['original_language_id' => $french->id]);
    $frenchEntity = Entity::query()->create(['work_id' => $work->id, 'language_id' => $french->id, 'name' => 'FR']);
    EntitySentence::create(['entity_id' => $frenchEntity->id, 'content' => 'Bonjour.', 'order' => 1024]);

    $empty = enrichableEntity('en', []);
    $enrichable = enrichableEntity('ru', ['Привет.']);

    Bus::fake();
    $this->artisan('entities:enrich')->assertSuccessful();

    // French has no enrichers: never stale, never dispatched, never stamped.
    // The empty entity IS dispatchable — its job runs once and stamps it done.
    expect($frenchEntity->refresh()->enrichment_stamps)->toBeNull()
        ->and($empty->refresh()->enrichment_stamps)->toBeNull()
        ->and($enrichable->refresh()->enrichment_stamps)->toBeNull();

    Bus::assertDispatched(EnrichEntitySentences::class, fn (EnrichEntitySentences $job) => $job->entityId === $enrichable->id);
    Bus::assertDispatched(EnrichEntitySentences::class, fn (EnrichEntitySentences $job) => $job->entityId === $empty->id);
    Bus::assertNotDispatched(EnrichEntitySentences::class, fn (EnrichEntitySentences $job) => $job->entityId === $frenchEntity->id);
});

it('marks a non-enrichable language done when its job runs', function () {
    $french = Language::query()->create(['code' => 'fr', 'name' => 'French', 'is_enabled' => true, 'sort_order' => 5]);
    $work = createWork(['original_language_id' => $french->id]);
    $frenchEntity = Entity::query()->create(['work_id' => $work->id, 'language_id' => $french->id, 'name' => 'FR']);
    EntitySentence::create(['entity_id' => $frenchEntity->id, 'content' => 'Bonjour.', 'order' => 1024]);

    Bus::fake();
    (new EnrichEntitySentences($frenchEntity->id))->handle();

    expect($frenchEntity->refresh()->enrichment_stamps)->toBe([])
        ->and(app(SentenceEnrichmentService::class)->isStale($frenchEntity))->toBeFalse();
});

it('skips freshly enriched entities in the sweep', function () {
    $fresh = enrichableEntity('ru', ['Привет.']);
    // A stamp written at the current versions, parity-checked (ADR 0067).
    $fresh->update(['enrichment_stamps' => ['ru_stress' => [
        'v' => 1, 'pv' => (new RussianStressEnricher)->pythonVersion(), 'at' => now()->toISOString(),
    ]]]);

    Bus::fake();
    $this->artisan('entities:enrich')->assertSuccessful();

    Bus::assertNotDispatched(EnrichEntitySentences::class);
});

it('re-enriches an entity whose stamps predate the python version parity', function () {
    // The {v, at} stamps written before ADR 0067 carry no python version:
    // exactly the one-time corpus re-run the parity change accepted.
    $entity = enrichableEntity('ru', ['Привет.']);
    $entity->update(['enrichment_stamps' => ['ru_stress' => now()->toISOString()]]);

    $service = app(SentenceEnrichmentService::class);
    expect(collect($service->staleEnrichers($entity->refresh()))->map->key()->all())->toBe(['ru_stress']);

    (new EnrichEntitySentences($entity->id, 0, ['ru_stress']))->handle();

    expect($entity->refresh()->enrichment_stamps['ru_stress']['pv'])->toBe(1)
        ->and($service->isStale($entity->refresh()))->toBeFalse();
});

it('re-enriches an entity whose sentences changed after enrichment', function () {
    $entity = enrichableEntity('ru', ['Привет.']);
    $entity->update(['enrichment_stamps' => ['ru_stress' => now()->subDay()->toISOString()]]);

    $service = app(SentenceEnrichmentService::class);
    expect($service->isStale($entity->refresh()))->toBeTrue();

    // A content edit after enrichment flips staleness (the touch bumps
    // sentences_updated_at past the stamp).
    EntitySentence::query()->where('entity_id', $entity->id)->first()->update(['content' => 'Приветик.']);
    expect($service->isStale($entity->refresh()))->toBeTrue();
});

it('re-enriches only the enricher missing a stamp (retro-processing)', function () {
    // State of an entity enriched before en_phrasal existed: only the stress
    // enricher carries a stamp (at the current versions, ADR 0067), and its
    // sentence already has stress marks.
    $entity = enrichableEntity('en', ['She gave up smoking.']);
    $sentence = EntitySentence::query()->where('entity_id', $entity->id)->first();
    $sentence->forceFill(['stressed_content' => 'pre-existing marks'])->saveQuietly();
    $entity->update(['enrichment_stamps' => ['en_stress' => [
        'v' => 1, 'pv' => 1, 'at' => now()->toISOString(),
    ]]]);

    $service = app(SentenceEnrichmentService::class);
    $stale = $service->staleEnrichers($entity->refresh());
    expect(collect($stale)->map->key()->all())->toBe(['en_phrasal']);

    $stampsBefore = $entity->refresh()->enrichment_stamps;

    // The job (the sweep's beginEnrichers path) runs only the stale
    // enricher, then stamps exactly it.
    (new EnrichEntitySentences($entity->id, 0, ['en_phrasal']))->handle();

    // The phrasal-only run wrote its hits and stamped only itself; the fresh
    // stress marks and their stamp are untouched.
    expect($sentence->refresh()->phrasal_verbs)->toBe([])
        ->and($sentence->stressed_content)->toBe('pre-existing marks')
        ->and($entity->refresh()->enrichment_stamps['en_phrasal'] ?? null)->not->toBeNull()
        ->and($entity->enrichment_stamps['en_stress'] ?? null)->toBe($stampsBefore['en_stress']);
});

it('never sends phrasal data for a russian entity', function () {
    $fake = fakePython()->enrichingNothing();

    $entity = enrichableEntity('ru', ['Привет.']);

    app(SentenceEnrichmentService::class)->enrichChunk(
        $entity,
        EntitySentence::query()->where('entity_id', $entity->id)->get(),
    );

    expect($fake->enrichPayloads[0]['enrichers'])->toBe(['ru_stress'])
        ->and($fake->enrichPayloads[0])->not->toHaveKey('phrasal_lexicon');
});

it('skips the stress hints when only the phrasal enricher runs', function () {
    $fake = fakePython()->enrichingNothing();

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

    $registry = new EnricherRegistry;
    $service = app(SentenceEnrichmentService::class);
    $service->enrichChunk(
        $entity,
        EntitySentence::query()->where('entity_id', $entity->id)->get(),
        [$registry->forKey('en_phrasal')],
    );

    $token = collect($fake->enrichPayloads[0]['sentences'][0]['tokens'] ?? [])->firstWhere('surface', 'Dictionary');
    expect($fake->enrichPayloads[0]['enrichers'])->toBe(['en_phrasal'])
        ->and($fake->enrichPayloads[0]['phrasal_lexicon'])->toBeArray()
        // Hint fields ship per contributing enricher (ADR 0067): with only
        // the phrasal enricher active, the IPA hint is not on the wire at all.
        ->and($token)->not->toHaveKey('ipa');
});

it('dispatches enrichment at the end of the upload pipeline', function () {
    Bus::fake();

    $entity = enrichableEntity('en', ['One.']);
    $entity->update(['signature' => json_encode([1.0]), 'status' => 'completed']);

    (new FinalizeEntityDerivations($entity->id, 'texts/test.txt'))->handle(
        app(EntityTextHasher::class),
    );

    Bus::assertDispatched(EnrichEntitySentences::class, fn (EnrichEntitySentences $job) => (int) $job->entityId === $entity->id);
});

it('sends dictionary hints with the enrichment request', function () {
    $fake = fakePython()->enrichingNothing();

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

    app(SentenceEnrichmentService::class)->enrichChunk(
        $entity->refresh(),
        EntitySentence::query()->where('entity_id', $entity->id)->get(),
    );

    $gave = collect($fake->enrichPayloads[0]['sentences'][0]['tokens'] ?? [])->firstWhere('surface', 'gave');
    expect($fake->enrichPayloads[0]['language'])->toBe('en')
        ->and($fake->enrichPayloads[0]['phrasal_lexicon'])->toContain('give up')
        ->and($gave)->not->toBeNull()
        ->and($gave['cls'])->toBe('verb')
        ->and($gave['lemma'])->toBe('give');
});

it('sends verb-lemma candidates for leads buried under another class', function () {
    $fake = fakePython()->enrichingNothing();

    $entity = enrichableEntity('en', ['She came forward.']);

    // The stained-glass noun outranks the verb (CLASS_PRIORITY: noun > verb),
    // so the entity link classifies "came" as a noun — yet "came forward" is
    // a phrasal verb (ADR 0058).
    $came = createWord('en', 'came', 'noun');
    EntityWord::query()->create([
        'entity_id' => $entity->id,
        'l_word' => 'came',
        'token' => 'came',
        'count' => 1,
        'word_id' => $came->id,
    ]);

    // The dictionary's verb pages for the surface: the imported form-of page
    // keeps the inflected headword itself (l_word "came", word "came" — the
    // shape the real data has, see "looked"), and the forms table reaches
    // the base verb "come". Both become candidates, in that order.
    $inflected = createWord('en', 'came', 'verb');
    $base = createWord('en', 'come', 'verb');
    Form::query()->create(['word_id' => $base->id, 'form' => 'came', 'l_word' => 'came']);

    app(SentenceEnrichmentService::class)->enrichChunk(
        $entity->refresh(),
        EntitySentence::query()->where('entity_id', $entity->id)->get(),
    );

    $cameToken = collect($fake->enrichPayloads[0]['sentences'][0]['tokens'] ?? [])->firstWhere('surface', 'came');
    expect($cameToken['cls'])->toBe('noun')
        ->and($cameToken['lemma'])->toBe('came')
        ->and($cameToken['verb_lemmas'])->toBe(['came', 'come'])
        // A surface with no verb pages ships none.
        ->and(collect($fake->enrichPayloads[0]['sentences'][0]['tokens'])->firstWhere('surface', 'forward')['verb_lemmas'])->toBeNull()
        // The inflected page and the noun page are distinct dictionary rows.
        ->and($inflected->id)->not->toBe($came->id);
});

it('ships stressed variants and phrasal hits to the reader page', function () {
    $user = approvedUser();

    $entity = enrichableEntity('ru', ['Она произносит это красиво.']);
    $sentence = EntitySentence::query()->where('entity_id', $entity->id)->first();
    EntitySentence::query()->whereKey($sentence->id)->toBase()->update([
        'stressed_content' => 'Она́ произно́сит э́то краси́во.',
        'phrasal_verbs' => json_encode([['verb' => 'произносит', 'particles' => [], 'start' => 4, 'end' => 14, 'phrase' => 'произносить']], JSON_UNESCAPED_UNICODE),
    ]);

    $response = $this->actingAs($user)->get("/reader/{$entity->id}")->assertOk();

    $props = $response->inertiaPage()['props'];
    expect($props['rows'][0]['a']['sentences'][0]['stressed'])->toBe('Она́ произно́сит э́то краси́во.')
        ->and($props['stressMarks'])->toBeFalse()
        // jsonb round-trips reorder keys; compare canonicalized.
        ->and($props['rows'][0]['a']['sentences'][0]['phrasal'])->toEqualCanonicalizing([['verb' => 'произносит', 'particles' => [], 'start' => 4, 'end' => 14, 'phrase' => 'произносить']])
        ->and($props['phrasalVerbs'])->toBeFalse();

    // Saved preferences ride along.
    $user->settings()->updateOrCreate(
        ['user_id' => $user->id],
        ['ui_settings' => ['reader' => ['stress_marks' => true, 'phrasal_verbs' => true]]],
    );
    // The factory pre-loads the settings relation; drop the stale copy so the
    // request resolves the just-updated row.
    $user->unsetRelation('settings');
    $props = $this->actingAs($user)->get("/reader/{$entity->id}")->assertOk()->inertiaPage()['props'];
    expect($props['stressMarks'])->toBeTrue()
        ->and($props['phrasalVerbs'])->toBeTrue();
});

it('ships sentence lists that skip empty junctions and stay sequential', function () {
    // Regression (in its current form): a row whose first junction was an
    // empty/illustration sentence once produced sparse keys that desynced
    // the readers' sentence indexes from the shipped lists. The row-object
    // payload (ADR 0060) keeps every sentence self-describing, so the
    // lists must simply be sequential PHP lists.
    $user = approvedUser();
    $work = createWork();
    $enEntity = createEntity('en', $work);
    $ruEntity = createEntity('ru', $work);

    $empty = EntitySentence::create(['entity_id' => $enEntity->id, 'content' => '', 'order' => 1]);
    $hello = EntitySentence::create(['entity_id' => $enEntity->id, 'content' => 'Hello there.', 'order' => 2]);
    $privet = EntitySentence::create(['entity_id' => $ruEntity->id, 'content' => 'Привет.', 'order' => 1]);
    $hello->forceFill(['stressed_content' => "He\u{0301}llo the\u{0301}re.", 'phrasal_verbs' => [['verb' => 'Hello', 'particles' => ['there'], 'start' => 0, 'end' => 12, 'phrase' => 'hello there']]])->saveQuietly();
    $privet->forceFill(['stressed_content' => 'При́вет.'])->saveQuietly();

    $entityMatch = createEntityMatch($enEntity, $ruEntity);
    $row = MeaningMatch::create(['entity_match_id' => $entityMatch->id, 'order' => 0, 'similarity' => 0.95, 'alignment_chunk' => 0]);
    foreach ([[$empty, 'a'], [$hello, 'a'], [$privet, 'b']] as [$sentence, $side]) {
        SentenceMeaningMatch::create(['entity_sentence_id' => $sentence->id, 'meaning_match_id' => $row->id, 'side' => $side]);
    }

    $props = $this->actingAs($user)->get("/reader/{$enEntity->id}")->assertOk()->inertiaPage()['props'];

    // The empty sentence is filtered; the a side carries exactly the hello
    // sentence with its annotations on the sentence object itself.
    $helloPayload = $props['rows'][0]['a']['sentences'];
    expect($props['rows'][0]['b']['sentences'][0]['text'])->toBe('Привет.')
        ->and(array_is_list($props['rows']))->toBeTrue()
        ->and(count($helloPayload))->toBe(1)
        ->and(array_is_list($helloPayload))->toBeTrue()
        ->and($helloPayload[0]['text'])->toBe('Hello there.')
        ->and($helloPayload[0]['stressed'])->toBe("He\u{0301}llo the\u{0301}re.")
        ->and($helloPayload[0]['phrasal'])->toEqualCanonicalizing([['verb' => 'Hello', 'particles' => ['there'], 'start' => 0, 'end' => 12, 'phrase' => 'hello there']]);
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
    $fake = fakePython()->enrichingNothing();

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

    app(SentenceEnrichmentService::class)->enrichChunk(
        $entity,
        EntitySentence::query()->where('entity_id', $entity->id)->get(),
    );

    $token = collect($fake->enrichPayloads[0]['sentences'][0]['tokens'] ?? [])->firstWhere('surface', 'Dictionary');
    expect($token['ipa'])->toBe(['/ˈdɪk.ʃə.nə.ɹi/'])
        ->and($token['cls'])->toBe('noun')
        ->and($token['lemma'])->toBe('dictionary');
});

it('prefers stress-bearing ipa variants when capping variants per word', function () {
    $fake = fakePython()->enrichingNothing();

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

    app(SentenceEnrichmentService::class)->enrichChunk(
        $entity,
        EntitySentence::query()->where('entity_id', $entity->id)->get(),
    );

    $token = collect($fake->enrichPayloads[0]['sentences'][0]['tokens'] ?? [])->firstWhere('surface', 'Dictionary');
    expect($token['ipa'])->toBe([
        '/ˈdɪk.ʃə.nə.ɹi/',
        '/dɪkʃənəɹi/',
        '/dɪkʃəneri/',
    ]);
});

it('sends per-part ipa for hyphenated compounds the dictionary lacks', function () {
    $fake = fakePython()->enrichingNothing();

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

    app(SentenceEnrichmentService::class)->enrichChunk(
        $entity,
        EntitySentence::query()->where('entity_id', $entity->id)->get(),
    );

    $token = collect($fake->enrichPayloads[0]['sentences'][0]['tokens'] ?? [])->firstWhere('surface', 'seven-sided');
    expect($token)->not->toHaveKey('ipa')
        ->and($token['parts'])->toBe([
            ['surface' => 'seven', 'ipa' => ['/ˈsɛvən/']],
            ['surface' => 'sided', 'ipa' => null],
        ]);
});

it('re-enriches an entity stamped by an older algorithm version', function () {
    $entity = enrichableEntity('en', ['She gave up smoking.']);
    $service = app(SentenceEnrichmentService::class);

    // v1-era stamps were bare ISO strings: stale for both the versioned
    // (v3) and the python-parity (pv) phrasal analysis, stale for the
    // python parity of the stress analysis too (ADR 0059, ADR 0067).
    $entity->update(['enrichment_stamps' => [
        'en_phrasal' => now()->toISOString(),
        'en_stress' => now()->toISOString(),
    ]]);
    expect(collect($service->staleEnrichers($entity->refresh()))->map->key()->all())->toBe(['en_stress', 'en_phrasal']);

    // A {v, at} stamp without pv still re-stales (the one-time parity
    // re-run), even at the current Laravel version.
    $version = (new EnglishPhrasalVerbEnricher)->version();
    $entity->update(['enrichment_stamps' => [
        'en_phrasal' => ['v' => $version, 'at' => now()->toISOString()],
        'en_stress' => ['v' => 1, 'at' => now()->toISOString()],
    ]]);
    expect(collect($service->staleEnrichers($entity->refresh()))->map->key()->all())->toBe(['en_stress', 'en_phrasal']);

    // Once re-stamped at the current versions on BOTH sides, nothing is
    // stale.
    $entity->update(['enrichment_stamps' => [
        'en_phrasal' => ['v' => $version, 'pv' => (new EnglishPhrasalVerbEnricher)->pythonVersion(), 'at' => now()->toISOString()],
        'en_stress' => ['v' => 1, 'pv' => 1, 'at' => now()->toISOString()],
    ]]);
    expect($service->isStale($entity->refresh()))->toBeFalse();

    // A python version older than declared re-stales (the sweep keeps
    // re-running until the two sides agree); a NEWER reported version is
    // fine — newer results than declared are strictly more advanced.
    $entity->update(['enrichment_stamps' => [
        'en_phrasal' => ['v' => $version, 'pv' => (new EnglishPhrasalVerbEnricher)->pythonVersion() - 1, 'at' => now()->toISOString()],
        'en_stress' => ['v' => 1, 'pv' => 1, 'at' => now()->toISOString()],
    ]]);
    expect(collect($service->staleEnrichers($entity->refresh()))->map->key()->all())->toBe(['en_phrasal']);

    $entity->update(['enrichment_stamps' => [
        'en_phrasal' => ['v' => $version, 'pv' => (new EnglishPhrasalVerbEnricher)->pythonVersion() + 1, 'at' => now()->toISOString()],
        'en_stress' => ['v' => 1, 'pv' => 1, 'at' => now()->toISOString()],
    ]]);
    expect($service->isStale($entity->refresh()))->toBeFalse();

    // markEnriched writes the fully versioned shape.
    $service->markEnriched($entity, [(new EnricherRegistry)->forKey('en_phrasal')]);
    $stamp = $entity->refresh()->enrichment_stamps['en_phrasal'];
    expect($stamp['v'])->toBe($version)
        ->and($stamp['pv'])->toBe((new EnglishPhrasalVerbEnricher)->pythonVersion())
        ->and($stamp['at'])->not->toBeNull();
});

it('gates the phrasal lexicon to the particle/preposition shape', function () {
    $entity = enrichableEntity('en', ['She gave up smoking.']);

    foreach (['give up', 'put up with', 'look forward to'] as $valid) {
        createWord('en', $valid, 'verb');
    }
    // The Wiktionary-import junk behind the reported false positives, plus
    // the idioms the parser can never confirm (ADR 0059).
    foreach (['do it', 'could have', 'be there', 'kick the bucket', 'take the bull by the horns'] as $junk) {
        createWord('en', $junk, 'verb');
    }

    $lexicon = (new EnglishPhrasalVerbEnricher)->requestExtras($entity->refresh())['phrasal_lexicon'];

    expect($lexicon)->toContain('give up')
        ->and($lexicon)->toContain('put up with')
        ->and($lexicon)->toContain('look forward to')
        ->and($lexicon)->not->toContain('do it')
        ->and($lexicon)->not->toContain('could have')
        ->and($lexicon)->not->toContain('be there')
        ->and($lexicon)->not->toContain('kick the bucket')
        ->and($lexicon)->not->toContain('take the bull by the horns');
});
