<?php

namespace App\Jobs;

use App\Classes\Enrichment\Enricher;
use App\Classes\Enrichment\EnricherRegistry;
use App\Classes\SentenceEnrichmentService;
use App\Models\Entity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Self-restarting sentence enrichment pipeline (AlignEntitySentences shape):
 * each run processes a bounded batch of STALE sentences, then re-dispatches
 * itself with the cursor until none remain — every processed sentence
 * carries its own per-enricher stamps (entity_sentences.enrichment_stamps,
 * ADR 0057 as moved per sentence; ADR 0094).
 *
 * Staleness is per-sentence (entity_sentences.enrichment_stamps): a run —
 * full ("begin") or targeted ("beginEnrichers") — walks only the sentences
 * stale for its enrichers, in id-cursor windows, and stamps each processed
 * sentence for exactly the enrichers persisted for it. A chunk and its
 * per-sentence stamps commit in one transaction: a failed chunk stamps
 * nothing and the entities:enrich sweep re-picks the entity.
 *
 * Enrichment is local-only (python service + dictionary data, ADR 0052), so
 * re-running a sentence is cheap and idempotent; after a sentence edit the
 * sweep re-picks only its entity, and the run re-enriches exactly the
 * sentences whose stamps went stale.
 */
#[Queue(QueueLane::LOW)]
class EnrichEntitySentences implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    /** Sentence batches enriched per run before re-dispatching. */
    private const BATCHES_PER_RUN = 2;

    /**
     * @param  list<string>  $enricherKeys  the enrichers this run targets; empty
     *                                      means "resolve the language's full set at handle time"
     */
    public function __construct(
        public readonly int $entityId,
        public readonly int $cursor = 0,
        public readonly array $enricherKeys = [],
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 60, 120];
    }

    /**
     * Dispatch a FULL run: every stale sentence of the entity's language
     * enrichers is processed chunk by chunk. The sweep's targeted variant is
     * beginEnrichers().
     */
    public static function begin(Entity|int $entity): void
    {
        $entityId = $entity instanceof Entity ? $entity->id : $entity;

        self::dispatch(entityId: $entityId);
    }

    /**
     * Dispatch a run for exactly the given enrichers — the sentences stale
     * for any of them are processed (their per-sentence stale sets decide
     * what each payload persists and stamps); a no-op on an empty set.
     *
     * @param  list<string>  $enricherKeys
     */
    public static function beginEnrichers(Entity|int $entity, array $enricherKeys): void
    {
        if ($enricherKeys === []) {
            return;
        }

        $entityId = $entity instanceof Entity ? $entity->id : $entity;

        self::dispatch(entityId: $entityId, enricherKeys: $enricherKeys);
    }

    public function handle(): void
    {
        $entity = Entity::with('language')->findOrFail($this->entityId);
        $enrichment = app(SentenceEnrichmentService::class);
        $registry = app(EnricherRegistry::class);
        $code = $entity->language?->code ?? '';

        // Empty keys = a full run: resolve the language's enrichers here so a
        // newly registered one is included. Explicit keys survive re-dispatch
        // untouched (the run retries the same work after a failure).
        $enrichers = $this->enricherKeys === []
            ? $registry->forLanguage($code)
            : $registry->forKeys($this->enricherKeys);

        // Languages the registry cannot enrich are never stale — the sweep
        // SQL-filters them out and the registry maps them to no stale keys,
        // so a dispatched run for one simply has nothing to do.
        if ($enrichers === []) {
            return;
        }

        $cursor = $this->cursor;
        $byKey = collect($enrichers)->keyBy(fn (Enricher $e) => $e->key());
        $processed = 0;

        for ($i = 0; $i < self::BATCHES_PER_RUN; $i++) {
            $sentences = $registry->staleSentenceQuery($entity, $enrichers)
                ->where('id', '>', $cursor)
                ->limit(SentenceEnrichmentService::CHUNK_SIZE)
                ->get();

            if ($sentences->isEmpty()) {
                // Chain complete: the per-sentence stamps carry everything —
                // there is no entity-level record to write.
                Log::info('EnrichEntitySentences completed', [
                    'entity_id' => $entity->id,
                    'language' => $code,
                    'enrichers' => array_map(fn ($enricher) => $enricher->key(), $enrichers),
                    'processed' => $processed,
                ]);

                return;
            }

            // The SQL window matched sentences stale for ANY run enricher;
            // the per-sentence stale set decides what each sentence gets
            // persisted and stamped — a sentence stale for a subset keeps
            // its fresh annotations and their stamps untouched.
            $perSentence = [];

            foreach ($sentences as $sentence) {
                $keys = $registry->staleKeysForSentence($sentence, $enrichers);
                $perSentence[$sentence->id] = array_map(fn (string $key) => $byKey->get($key), $keys);
            }

            $chunkKeys = collect($perSentence)
                ->flatMap(fn (array $targets) => array_map(fn (Enricher $e) => $e->key(), $targets))
                ->unique()
                ->all();
            $chunkEnrichers = array_values(array_filter(
                $enrichers,
                fn (Enricher $enricher): bool => in_array($enricher->key(), $chunkKeys, true),
            ));

            // Annotations and their stamps commit together: a failed chunk
            // stamps nothing, and the sweep re-picks the entity.
            DB::transaction(function () use ($enrichment, $entity, $sentences, $chunkEnrichers, $perSentence): void {
                $chunkResult = $enrichment->enrichChunk($entity, $sentences, $chunkEnrichers, $perSentence);
                $enrichment->markSentencesEnriched($sentences, $perSentence, $chunkResult['versions']);
            });

            $processed += $sentences->count();
            $cursor = $sentences->last()->id;
        }

        self::dispatch($entity->id, $cursor, $this->enricherKeys);
    }
}
