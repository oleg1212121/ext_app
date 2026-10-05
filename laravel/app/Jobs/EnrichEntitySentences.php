<?php

namespace App\Jobs;

use App\Classes\Enrichment\EnricherRegistry;
use App\Classes\SentenceEnrichmentService;
use App\Models\Entity;
use App\Models\EntitySentence;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Self-restarting sentence enrichment pipeline (AlignEntitySentences shape):
 * each run enriches a bounded batch of sentences, then re-dispatches itself
 * with the cursor until the entity is done, when the run's enrichers are
 * stamped on entities.enrichment_stamps (ADR 0057).
 *
 * Enrichment is local-only (python service + dictionary data, ADR 0052), so
 * re-running an entity is cheap and idempotent; after sentence edits the
 * entities:enrich sweep re-picks only the enrichers whose stamps went stale.
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
        private readonly int $cursor = 0,
        private readonly array $enricherKeys = [],
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 60, 120];
    }

    /**
     * Dispatch a FULL re-enrichment: every enricher of the entity's language
     * runs regardless of stamps. The sweep's targeted variant is
     * beginEnrichers().
     */
    public static function begin(Entity|int $entity): void
    {
        $entityId = $entity instanceof Entity ? $entity->id : $entity;

        self::dispatch(entityId: $entityId);
    }

    /**
     * Dispatch enrichment for exactly the given enrichers (their stamps are
     * written when the run completes); a no-op on an empty set.
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
        $enrichment = SentenceEnrichmentService::create();
        $registry = app(EnricherRegistry::class);
        $code = $entity->language?->code ?? '';

        // Empty keys = a full run: resolve the language's enrichers here so a
        // newly registered one is included. Explicit keys survive re-dispatch
        // untouched (the run retries the same work after a failure).
        $enrichers = $this->enricherKeys === []
            ? $registry->forLanguage($code)
            : $registry->forKeys($this->enricherKeys);

        // Languages the registry cannot enrich count as done (an empty stamp
        // map) so the sweep never re-picks them.
        if ($enrichers === []) {
            $enrichment->markEnriched($entity, []);

            return;
        }

        $cursor = $this->cursor;
        // The python-reported algorithm versions of the run's chunks — the
        // stamp's input (ADR 0067); last chunk wins (same service, same
        // versions, and an entity with no sentences stamps the declared
        // fallback).
        $reportedVersions = [];

        for ($i = 0; $i < self::BATCHES_PER_RUN; $i++) {
            $sentences = EntitySentence::query()
                ->where('entity_id', $entity->id)
                ->where('id', '>', $cursor)
                ->orderBy('id')
                ->limit(SentenceEnrichmentService::CHUNK_SIZE)
                ->get();

            if ($sentences->isEmpty()) {
                $enrichment->markEnriched($entity, $enrichers, $reportedVersions);
                Log::info('EnrichEntitySentences completed', [
                    'entity_id' => $entity->id,
                    'language' => $code,
                    'enrichers' => array_map(fn ($enricher) => $enricher->key(), $enrichers),
                ]);

                return;
            }

            $result = $enrichment->enrichChunk($entity, $sentences, $enrichers);
            $reportedVersions = $result['versions'];
            $cursor = $sentences->last()->id;
        }

        self::dispatch($entity->id, $cursor, $this->enricherKeys);
    }
}
