<?php

namespace App\Jobs;

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
 * with the cursor until the entity is done, when enriched_at is stamped.
 *
 * Enrichment is local-only (python service + dictionary data, ADR 0052), so
 * re-running an entity is cheap and idempotent; after sentence edits the
 * entities:enrich sweep re-picks it because enriched_at goes stale against
 * sentences_updated_at.
 */
#[Queue(QueueLane::LOW)]
class EnrichEntitySentences implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    /** Sentence batches enriched per run before re-dispatching. */
    private const BATCHES_PER_RUN = 2;

    public function __construct(
        public readonly int $entityId,
        private readonly int $cursor = 0,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 60, 120];
    }

    /**
     * Dispatch the pipeline for an entity (idempotent: re-enriching replaces
     * every sentence's enrichment columns).
     */
    public static function begin(Entity|int $entity): void
    {
        $entityId = $entity instanceof Entity ? $entity->id : $entity;

        self::dispatch(entityId: $entityId, cursor: 0);
    }

    public function handle(): void
    {
        $entity = Entity::with('language')->findOrFail($this->entityId);
        $enrichment = SentenceEnrichmentService::create();
        $code = $entity->language?->code ?? '';

        // Languages the service cannot enrich count as done, so the sweep
        // never re-picks them.
        if (! in_array($code, SentenceEnrichmentService::ENRICHABLE_LANGUAGES, true)) {
            $enrichment->markEnriched($entity);

            return;
        }

        $cursor = $this->cursor;

        for ($i = 0; $i < self::BATCHES_PER_RUN; $i++) {
            $sentences = EntitySentence::query()
                ->where('entity_id', $entity->id)
                ->where('id', '>', $cursor)
                ->orderBy('id')
                ->limit(SentenceEnrichmentService::CHUNK_SIZE)
                ->get();

            if ($sentences->isEmpty()) {
                $enrichment->markEnriched($entity);
                Log::info('EnrichEntitySentences completed', [
                    'entity_id' => $entity->id,
                    'language' => $code,
                ]);

                return;
            }

            $enrichment->enrichChunk($entity, $sentences);
            $cursor = $sentences->last()->id;
        }

        self::dispatch($entity->id, $cursor);
    }
}
