<?php

use App\Classes\Enrichment\BelarusianStressEnricher;
use App\Classes\Enrichment\EnglishPhrasalVerbEnricher;
use App\Classes\Enrichment\EnglishStressEnricher;
use App\Classes\Enrichment\EnricherRegistry;
use App\Classes\Enrichment\RussianStressEnricher;
use App\Classes\SentenceEnrichmentService;
use App\Jobs\EnrichEntitySentences;
use App\Models\Entity;
use App\Models\EntitySentence;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

// Guard: tests that reach the python service never touch the wire — the
// fake client is bound for every test here, and anything that still tries
// an HTTP call fails the run.
beforeEach(function () {
    Http::preventStrayRequests();
    fakePython();
});

/** A fresh stamp map for the given enricher keys, written "now". */
function currentStamps(array $keys): array
{
    $stamps = [];

    foreach ($keys as $key) {
        $stamps[$key] = [
            'v' => (new EnricherRegistry)->forKey($key)->version(),
            'pv' => (new EnricherRegistry)->forKey($key)->pythonVersion(),
            'at' => now()->toISOString(),
        ];
    }

    return $stamps;
}

/** Write a sentence's per-sentence stamps quietly (no updated_at bump). */
function stampSentence(EntitySentence $sentence, ?array $stamps): EntitySentence
{
    // The base builder is required: an Eloquent save maintains updated_at
    // even via saveQuietly (events are suppressed, timestamps are not), and
    // several tests here pin staleness against the sentence's own
    // updated_at — a bumped value would flip them on second boundaries.
    EntitySentence::query()
        ->whereKey($sentence->getKey())
        ->toBase()
        ->update(['enrichment_stamps' => $stamps === null ? null : json_encode($stamps)]);

    return $sentence->refresh();
}

function sentenceEntity(string $code, array $contents): Entity
{
    $entity = createEntity($code);

    foreach ($contents as $index => $content) {
        EntitySentence::create(['entity_id' => $entity->id, 'content' => $content, 'order' => ($index + 1) * 1024]);
    }

    return $entity->refresh();
}

it('reads a sentence stale enricher keys from its own stamp', function () {
    $registry = new EnricherRegistry;
    $ru = $registry->forLanguage('ru');
    $en = $registry->forLanguage('en');
    // A version bump on the Laravel side of the Russian analysis.
    $bumped = new class extends RussianStressEnricher
    {
        public function version(): int
        {
            return 2;
        }
    };

    $this->travel(1)->hours();
    $entity = sentenceEntity('ru', ['Первый.', 'Второй.', 'Третий.', 'Четвёртый.', 'Пятый.', 'Шестой.']);
    [$never, $fresh, $changed, $legacy, $missingPv, $behindVersions] = EntitySentence::query()
        ->where('entity_id', $entity->id)
        ->orderBy('id')
        ->get()
        ->all();

    // Never enriched: no stamp at all.
    expect($registry->staleKeysForSentence($never, $ru))->toBe(['ru_stress']);

    // Stamped at the current versions a moment after its last change: fresh.
    stampSentence($fresh, currentStamps(['ru_stress']));
    expect($registry->staleKeysForSentence($fresh, $ru))->toBe([]);

    // Content edited after enrichment: stale again — the sentence's own
    // updated_at moved past the stamp.
    stampSentence($changed, currentStamps(['ru_stress']));
    $this->travel(1)->hours();
    $changed->update(['content' => 'Изменённый.']);
    expect($registry->staleKeysForSentence($changed->refresh(), $ru))->toBe(['ru_stress']);

    // v1-era legacy stamps were bare ISO strings: stale (no python parity).
    stampSentence($legacy, null)->forceFill(['enrichment_stamps' => now()->toISOString()])->saveQuietly();
    expect($legacy->refresh()->enrichment_stamps)->toBeString()
        ->and($registry->staleKeysForSentence($legacy, $ru))->toBe(['ru_stress']);

    // A versioned stamp without the python version (pre-parity): stale.
    stampSentence($missingPv, ['ru_stress' => ['v' => 1, 'at' => now()->toISOString()]]);
    expect($registry->staleKeysForSentence($missingPv, $ru))->toBe(['ru_stress']);

    // Stamped below an enricher's declared version: stale; at or above: fresh.
    stampSentence($behindVersions, ['ru_stress' => ['v' => 1, 'pv' => 1, 'at' => now()->toISOString()]]);
    expect($registry->staleKeysForSentence($behindVersions, [$bumped]))->toBe(['ru_stress'])
        ->and($registry->staleKeysForSentence($behindVersions, $ru))->toBe([]);

    // Mixed per-key staleness: a sentence stamped for the stress analysis
    // only is stale for exactly the missing phrasal key (the retro shape).
    $enEntity = sentenceEntity('en', ['One.']);
    $enSentence = EntitySentence::query()->where('entity_id', $enEntity->id)->first();
    stampSentence($enSentence, currentStamps(['en_stress']));
    expect($registry->staleKeysForSentence($enSentence, $en))->toBe(['en_phrasal']);
});

it('selects exactly the php-stale sentences in id order', function () {
    $registry = new EnricherRegistry;

    $this->travel(1)->hours();
    $entity = sentenceEntity('ru', ['Первый.', 'Второй.', 'Третий.', 'Четвёртый.', 'Пятый.']);
    [$fresh, $never, $legacy, $changed, $behindPv] = EntitySentence::query()
        ->where('entity_id', $entity->id)
        ->orderBy('id')
        ->get()
        ->all();

    stampSentence($fresh, currentStamps(['ru_stress']));

    stampSentence($legacy, null)->forceFill(['enrichment_stamps' => now()->toISOString()])->saveQuietly();

    stampSentence($changed, currentStamps(['ru_stress']));
    $this->travel(1)->hours();
    $changed->update(['content' => 'Изменённый.']);

    stampSentence($behindPv, ['ru_stress' => ['v' => 1, 'pv' => 0, 'at' => now()->toISOString()]]);

    $staleIds = $registry->staleSentenceQuery($entity)->pluck('id')->all();
    $phpStaleIds = EntitySentence::query()
        ->where('entity_id', $entity->id)
        ->orderBy('id')
        ->get()
        ->filter(fn (EntitySentence $sentence) => $registry->staleKeysForSentence($sentence, $registry->forLanguage('ru')) !== [])
        ->pluck('id')
        ->all();

    expect($staleIds)->toBe([$never->id, $legacy->id, $changed->id, $behindPv->id])
        ->and($staleIds)->toBe($phpStaleIds);
});

it('staleKeysForMany agrees with the per-sentence predicate for every entity in the batch', function () {
    $registry = new EnricherRegistry;

    // Fully enriched (per-sentence stamps at the current versions), never
    // enriched, and legacy-stamped entities — the batch shapes the sweep
    // scans.
    $fresh = sentenceEntity('ru', ['Свежий.']);
    $freshSentence = EntitySentence::query()->where('entity_id', $fresh->id)->first();
    stampSentence($freshSentence, currentStamps(['ru_stress']));

    $never = sentenceEntity('ru', ['Никогда.']);
    $legacy = sentenceEntity('ru', ['Легаси.']);
    $legacySentence = EntitySentence::query()->where('entity_id', $legacy->id)->first();
    stampSentence($legacySentence, null)->forceFill(['enrichment_stamps' => now()->toISOString()])->saveQuietly();

    $batch = [$fresh->refresh(), $never->refresh(), $legacy->refresh()];
    $map = $registry->staleKeysForMany($batch);

    expect($map[$fresh->id])->toBe([])
        ->and($map[$never->id])->toBe(['ru_stress'])
        // The sentence's own legacy stamp decides — there is no entity-level
        // stamp to consult.
        ->and($map[$legacy->id])->toBe(['ru_stress']);

    // Agreement with the single-entity form and the per-sentence union.
    foreach ($batch as $entity) {
        $union = EntitySentence::query()
            ->where('entity_id', $entity->id)
            ->get()
            ->flatMap(fn (EntitySentence $sentence) => $registry->staleKeysForSentence($sentence, $registry->forLanguage($entity->language?->code ?? '')))
            ->unique()
            ->values()
            ->all();

        expect($map[$entity->id])->toBe($union)
            ->and($registry->staleKeysFor($entity))->toBe($union);
    }
});

it('persists only a sentence target enrichers columns inside a mixed chunk', function () {
    $fake = fakePython();

    $entity = sentenceEntity('en', ['She gave up smoking.']);
    $sentence = EntitySentence::query()->where('entity_id', $entity->id)->first();
    $sentence->forceFill(['stressed_content' => 'pre-existing marks'])->saveQuietly();

    $registry = new EnricherRegistry;
    $en = $registry->forLanguage('en');

    // The chunk's payload carries both analyses (python runs the union);
    // the sentence's target set is exactly the stale key, so only its
    // column is written.
    $service = app(SentenceEnrichmentService::class);
    $result = $service->enrichChunk($entity, collect([$sentence]), $en, [
        $sentence->id => [$registry->forKey('en_phrasal')],
    ]);

    expect($fake->enrichPayloads[0]['enrichers'])->toBe(['en_stress', 'en_phrasal'])
        ->and($result['written'])->toBe(1)
        ->and($sentence->refresh()->stressed_content)->toBe('pre-existing marks')
        ->and($sentence->phrasal_verbs)->toBe([]);
});

it('stamps sentences per their processed enrichers and keeps writes quiet', function () {
    $entity = sentenceEntity('en', ['One.', 'Two.']);
    [$first, $second] = EntitySentence::query()->where('entity_id', $entity->id)->orderBy('id')->get()->all();

    // The first sentence already carries a stress stamp from an earlier run:
    // the merge must keep it while adding the phrasal stamp.
    stampSentence($first, currentStamps(['en_stress']));
    $stressStampBefore = $first->refresh()->enrichment_stamps['en_stress'];
    // Captured after the setup writes: the assertions below pin the
    // service's merge — it must leave the sentence's updated_at exactly
    // as it finds it.
    $updatedAt = $first->updated_at;

    $registry = new EnricherRegistry;
    $service = app(SentenceEnrichmentService::class);
    $service->markSentencesEnriched(collect([$first, $second]), [
        $first->id => [$registry->forKey('en_phrasal')],
        $second->id => [$registry->forKey('en_stress'), $registry->forKey('en_phrasal')],
    ], ['en_stress' => 1, 'en_phrasal' => 3]);

    $firstStamp = $first->refresh()->enrichment_stamps;
    $secondStamp = $second->refresh()->enrichment_stamps;

    expect(array_keys($firstStamp))->toBe(['en_stress', 'en_phrasal'])
        // The pre-existing stress stamp survives the merge untouched.
        ->and($firstStamp['en_stress'])->toBe($stressStampBefore)
        ->and($firstStamp['en_phrasal']['v'])->toBe(3)
        ->and($firstStamp['en_phrasal']['pv'])->toBe(3)
        ->and($firstStamp['en_phrasal']['at'])->not->toBeNull()
        ->and(array_keys($secondStamp))->toBe(['en_stress', 'en_phrasal'])
        ->and($secondStamp['en_stress']['pv'])->toBe(1)
        // Quiet writes: stamping never bumps the sentence's updated_at —
        // the staleness compare stays valid.
        ->and($first->updated_at->equalTo($updatedAt))->toBeTrue();
});

it('falls back to declared python versions when the run reported none', function () {
    $entity = sentenceEntity('ru', ['Привет.']);
    $sentence = EntitySentence::query()->where('entity_id', $entity->id)->first();

    app(SentenceEnrichmentService::class)->markSentencesEnriched(
        collect([$sentence]),
        [$sentence->id => (new EnricherRegistry)->forLanguage('ru')],
    );

    $stamp = $sentence->refresh()->enrichment_stamps['ru_stress'];
    expect($stamp['v'])->toBe((new RussianStressEnricher)->version())
        ->and($stamp['pv'])->toBe((new RussianStressEnricher)->pythonVersion())
        ->and($stamp['at'])->not->toBeNull();
});

it('enriches exactly the edited sentence in one small payload', function () {
    $fake = fakePython();

    $entity = sentenceEntity('ru', ['Первый.', 'Второй.', 'Третий.']);

    // Full pass: every sentence stamped fresh.
    (new EnrichEntitySentences($entity->id))->handle();

    expect($fake->enrichPayloads)->toHaveCount(1)
        ->and($fake->enrichPayloads[0]['sentences'])->toHaveCount(3);

    [$first, $second, $third] = EntitySentence::query()
        ->where('entity_id', $entity->id)
        ->orderBy('id')
        ->get()
        ->all();
    $firstStress = $first->refresh()->stressed_content;
    $firstStampAt = $first->refresh()->enrichment_stamps['ru_stress']['at'];
    $thirdStampAt = $third->refresh()->enrichment_stamps['ru_stress']['at'];
    $secondStampAt = $second->refresh()->enrichment_stamps['ru_stress']['at'];

    // The edit: one sentence's content changes after enrichment.
    $this->travel(1)->hours();
    $second->update(['content' => 'Изменённый.']);

    // A full run is "all stale sentences": exactly the edited one.
    (new EnrichEntitySentences($entity->id))->handle();

    expect($fake->enrichPayloads)->toHaveCount(2)
        ->and($fake->enrichPayloads[1]['sentences'])->toHaveCount(1)
        ->and($fake->enrichPayloads[1]['sentences'][0]['id'])->toBe($second->id)
        ->and($fake->enrichPayloads[1]['sentences'][0]['text'])->toBe('Изменённый.')
        ->and($fake->enrichPayloads[1]['enrichers'])->toBe(['ru_stress']);

    // The edited sentence carries fresh annotations and a fresh stamp.
    expect($second->refresh()->stressed_content)->toBe('Изменённый.́')
        ->and($second->refresh()->enrichment_stamps['ru_stress']['at'])->not->toBe($secondStampAt);

    // Every other sentence: annotations and stamps byte-identical.
    expect($first->refresh()->stressed_content)->toBe($firstStress)
        ->and($first->refresh()->enrichment_stamps['ru_stress']['at'])->toBe($firstStampAt)
        ->and($third->refresh()->enrichment_stamps['ru_stress']['at'])->toBe($thirdStampAt);
});

it('runs a never-enriched entity chunk by chunk, stamping per chunk', function () {
    $fake = fakePython();

    $contents = [];

    foreach (range(1, 160) as $n) {
        $contents[] = "Предложение номер {$n}.";
    }

    $entity = sentenceEntity('ru', $contents);

    Bus::fake();
    (new EnrichEntitySentences($entity->id))->handle();

    // Run 1: two batches of 75, each stamped as it completes; the chain
    // re-dispatches with the cursor.
    expect($fake->enrichPayloads)->toHaveCount(2)
        ->and($fake->enrichPayloads[0]['sentences'])->toHaveCount(75)
        ->and($fake->enrichPayloads[1]['sentences'])->toHaveCount(75)
        ->and(EntitySentence::query()->where('entity_id', $entity->id)->whereNotNull('enrichment_stamps')->count())->toBe(150);

    $cursor = EntitySentence::query()->where('entity_id', $entity->id)->orderBy('id')->skip(149)->first()->id;
    Bus::assertDispatched(EnrichEntitySentences::class, fn (EnrichEntitySentences $job) => $job->cursor === $cursor);

    // Run 2 (the chain's continuation): the remaining 10, then completion —
    // per-sentence stamps everywhere.
    (new EnrichEntitySentences($entity->id, $cursor))->handle();

    expect($fake->enrichPayloads)->toHaveCount(3)
        ->and($fake->enrichPayloads[2]['sentences'])->toHaveCount(10)
        ->and(EntitySentence::query()->where('entity_id', $entity->id)->whereNotNull('enrichment_stamps')->count())->toBe(160);
});

it('does not stamp sentences when the chunk fails', function () {
    $fake = fakePython();
    $fake->enrichHandler = fn () => throw new RuntimeException('python down');

    $entity = sentenceEntity('ru', ['Привет.']);

    expect(fn () => (new EnrichEntitySentences($entity->id))->handle())->toThrow(RuntimeException::class);

    $sentence = EntitySentence::query()->where('entity_id', $entity->id)->first();

    // A failed chunk leaves annotations and stamps unwritten — the next
    // sweep pass re-picks the entity (still stale).
    expect($sentence->refresh()->stressed_content)->toBeNull()
        ->and($sentence->refresh()->enrichment_stamps)->toBeNull()
        ->and((new EnricherRegistry)->staleKeysFor($entity->refresh()))->toBe(['ru_stress']);

    // The retry succeeds and stamps.
    $fake->enrichHandler = null;
    (new EnrichEntitySentences($entity->id))->handle();

    expect($sentence->refresh()->enrichment_stamps['ru_stress'])->not->toBeNull();
});

it('re-runs exactly the stale analysis per sentence in a mixed chunk', function () {
    $fake = fakePython();
    $realRegistry = new EnricherRegistry;

    $entity = sentenceEntity('en', ['She gave up smoking.', 'She looked it up online.']);

    // Fresh full pass with the current manifest.
    (new EnrichEntitySentences($entity->id))->handle();

    [$first, $second] = EntitySentence::query()
        ->where('entity_id', $entity->id)
        ->orderBy('id')
        ->get()
        ->all();
    $firstStressAt = $first->refresh()->enrichment_stamps['en_stress']['at'];
    $secondStressAt = $second->refresh()->enrichment_stamps['en_stress']['at'];
    $secondStress = $second->refresh()->stressed_content;

    // A phrasal version bump (en_phrasal v4) re-stales every sentence for
    // exactly that analysis; an edit re-stales the edited one for both.
    $this->travel(1)->hours();
    $first->update(['content' => 'She gave up smoking quickly.']);
    $bumpedPhrasal = new class extends EnglishPhrasalVerbEnricher
    {
        public function version(): int
        {
            return 4;
        }
    };
    $this->app->instance(EnricherRegistry::class, new EnricherRegistry([new EnglishStressEnricher, $bumpedPhrasal]));

    (new EnrichEntitySentences($entity->id))->handle();

    // One payload: the union of the sentences' stale sets, both sentences.
    expect($fake->enrichPayloads)->toHaveCount(2)
        ->and($fake->enrichPayloads[1]['enrichers'])->toBe(['en_stress', 'en_phrasal'])
        ->and(collect($fake->enrichPayloads[1]['sentences'])->pluck('id')->all())->toBe([$first->id, $second->id]);

    // The edited sentence re-ran both analyses; the bump-stale one kept its
    // stress marks AND its stress stamp untouched, gaining only the phrasal
    // stamp at the new version.
    expect($first->refresh()->enrichment_stamps['en_stress']['at'])->not->toBe($firstStressAt)
        ->and($second->refresh()->stressed_content)->toBe($secondStress)
        ->and($second->refresh()->enrichment_stamps['en_stress']['at'])->toBe($secondStressAt)
        ->and($second->refresh()->enrichment_stamps['en_phrasal']['v'])->toBe(4)
        // Nothing is stale anymore under the bumped manifest.
        ->and(app(EnricherRegistry::class)->staleKeysFor($entity->refresh()))->toBe([]);
});

it('dispatches only entities with a stale sentence, skipping fully enriched ones', function () {
    $fresh = sentenceEntity('ru', ['Свежий.']);
    $edited = sentenceEntity('ru', ['Первый.', 'Второй.']);

    // Both fully enriched: per-sentence stamps everywhere.
    (new EnrichEntitySentences($fresh->id))->handle();
    (new EnrichEntitySentences($edited->id))->handle();

    // One sentence edit re-stales exactly its entity.
    $this->travel(1)->hours();
    EntitySentence::query()->where('entity_id', $edited->id)->orderBy('id')->first()
        ->update(['content' => 'Изменённый.']);

    Bus::fake();
    $this->artisan('entities:enrich')->assertSuccessful();

    Bus::assertDispatched(EnrichEntitySentences::class, fn (EnrichEntitySentences $job) => $job->entityId === $edited->id
        && $job->enricherKeys === ['ru_stress']);
    Bus::assertNotDispatched(EnrichEntitySentences::class, fn (EnrichEntitySentences $job) => $job->entityId === $fresh->id);
});

it('never picks an entity without sentences from the sweep', function () {
    // A sentence-less enrichable entity has no stale sentence; nothing needs
    // to mark it done — it never qualifies for a pick.
    $entity = sentenceEntity('ru', []);

    Bus::fake();
    $this->artisan('entities:enrich')->assertSuccessful();

    Bus::assertNotDispatched(EnrichEntitySentences::class, fn (EnrichEntitySentences $job) => $job->entityId === $entity->id);
    // The registry agrees: no stale keys without a sentence.
    expect((new EnricherRegistry)->staleKeysFor($entity))->toBe([]);

    // Its direct job run (the upload pipeline's begin() path) is a clean
    // no-op — the completing branch has nothing to stamp, and the entity
    // stays unpicked.
    (new EnrichEntitySentences($entity->id))->handle();
    $this->artisan('entities:enrich')->assertSuccessful();
    Bus::assertNotDispatched(EnrichEntitySentences::class, fn (EnrichEntitySentences $job) => $job->entityId === $entity->id);
});

it('re-enriches every sentence of the language when the php algorithm version bumps', function () {
    $fake = fakePython();

    $first = sentenceEntity('en', ['She gave up smoking.']);
    $second = sentenceEntity('en', ['She looked it up.']);

    // Full pass at the current versions: everything stamped fresh.
    (new EnrichEntitySentences($first->id))->handle();
    (new EnrichEntitySentences($second->id))->handle();
    expect($fake->enrichPayloads)->toHaveCount(2);

    // A v4 bump of the phrasal analysis (the shipped class is at v3) —
    // every sentence stamped below it re-stales, corpus-wide.
    $bumpedPhrasal = new class extends EnglishPhrasalVerbEnricher
    {
        public function version(): int
        {
            return 4;
        }
    };
    $this->app->instance(EnricherRegistry::class, new EnricherRegistry([new EnglishStressEnricher, $bumpedPhrasal]));

    // The sweep picks both entities for exactly the bumped analysis.
    Bus::fake();
    $this->artisan('entities:enrich')->assertSuccessful();
    Bus::assertDispatched(EnrichEntitySentences::class, fn (EnrichEntitySentences $job) => $job->entityId === $first->id
        && $job->enricherKeys === ['en_phrasal']);
    Bus::assertDispatched(EnrichEntitySentences::class, fn (EnrichEntitySentences $job) => $job->entityId === $second->id
        && $job->enricherKeys === ['en_phrasal']);

    // The run: one minimal payload per entity, only the bumped analysis on
    // the wire, stamps rewritten at the new version.
    (new EnrichEntitySentences($first->id, 0, ['en_phrasal']))->handle();

    expect($fake->enrichPayloads)->toHaveCount(3)
        ->and($fake->enrichPayloads[2]['enrichers'])->toBe(['en_phrasal'])
        ->and($fake->enrichPayloads[2]['sentences'])->toHaveCount(1);

    $sentence = EntitySentence::query()->where('entity_id', $first->id)->first();
    expect($sentence->refresh()->enrichment_stamps['en_phrasal']['v'])->toBe(4)
        ->and($sentence->enrichment_stamps['en_stress']['v'])->toBe(1);

    // Nothing is stale anymore: the next sweep pass dispatches nothing.
    Bus::fake();
    $this->artisan('entities:enrich')->assertSuccessful();
    Bus::assertNotDispatched(EnrichEntitySentences::class, fn (EnrichEntitySentences $job) => $job->entityId === $first->id);
});

it('re-stales sentences whose python version lags the declared one', function () {
    // The parity half of the version contract (ADR 0067), per sentence: the
    // python-reported ALGORITHM_VERSION the stamp records decides freshness
    // against the enricher's declared pythonVersion().
    createLanguages(['en', 'ru', 'be']);
    $entity = sentenceEntity('be', ['Добры дзень.']);
    $sentence = EntitySentence::query()->where('entity_id', $entity->id)->first();
    $at = now()->toISOString();

    // The shipped be_stress declares pythonVersion 1. A python-2 enricher
    // declaration reads pv 1 stamps as stale; pv 2 as fresh; pv 3 (newer
    // than declared) as fresh too.
    $expectsTwo = new class extends BelarusianStressEnricher
    {
        public function pythonVersion(): int
        {
            return 2;
        }
    };
    $registry = new EnricherRegistry([$expectsTwo]);

    stampSentence($sentence, ['be_stress' => ['v' => 1, 'pv' => 1, 'at' => $at]]);
    expect($registry->staleKeysFor($entity->refresh()))->toBe(['be_stress']);

    stampSentence($sentence, ['be_stress' => ['v' => 1, 'pv' => 2, 'at' => $at]]);
    expect($registry->staleKeysFor($entity->refresh()))->toBe([]);

    stampSentence($sentence, ['be_stress' => ['v' => 1, 'pv' => 3, 'at' => $at]]);
    expect($registry->staleKeysFor($entity->refresh()))->toBe([]);

    // The loop: a run under the pv-2 declaration records what the python
    // service actually reported (1, the fake mirrors the shipped module) —
    // the sentence stays stale, so the sweep keeps re-picking until the two
    // sides agree; once the declaration matches the module again, it stops.
    stampSentence($sentence, ['be_stress' => ['v' => 1, 'pv' => 1, 'at' => $at]]);
    $this->app->instance(EnricherRegistry::class, $registry);
    Bus::fake();
    $this->artisan('entities:enrich')->assertSuccessful();
    Bus::assertDispatched(EnrichEntitySentences::class, fn (EnrichEntitySentences $job) => $job->entityId === $entity->id
        && $job->enricherKeys === ['be_stress']);

    (new EnrichEntitySentences($entity->id, 0, ['be_stress']))->handle();
    expect($sentence->refresh()->enrichment_stamps['be_stress']['pv'])->toBe(1)
        ->and($registry->staleKeysFor($entity->refresh()))->toBe(['be_stress']);

    // The python module catches up (the declaration mirrors the shipped
    // module again): the same stamps read fresh.
    $this->app->instance(EnricherRegistry::class, new EnricherRegistry);
    Bus::fake();
    $this->artisan('entities:enrich')->assertSuccessful();
    Bus::assertNotDispatched(EnrichEntitySentences::class);
});
