<?php

namespace App\Jobs;

use App\Classes\EntityWordAdoption;
use App\Classes\EntityWordIndexer;
use App\Classes\EntityWordLinker;
use App\Models\Entity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Re-tokenizes an entity's stale sentences in a bounded batch, then keeps
 * the derived word list linked and adopted. Self-restarting chain
 * (EnrichEntitySentences shape): while stale sentences remain past the
 * run's budget the job re-dispatches itself with the sentence-id cursor, so
 * a fresh book upload flows through many small yieldable runs that
 * interleave fairly with the rest of the default lane instead of one giant
 * job.
 *
 * Deliberately NOT ShouldBeUnique: the unique lock is only released after
 * handle() returns, so a same-uniqueId re-dispatch from inside handle()
 * would be silently dropped and the chain would die after its first run.
 * The tradeoff — the five-minute sweep can stack a second chain while one
 * is in flight — is benign: every run processes only sentences that are
 * still actually stale (transactional row replacement, idempotent), and a
 * duplicate final run just re-derives the same list.
 */
#[Queue(QueueLane::DEFAULT)]
class RefreshEntityWords implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    /**
     * @param  int  $cursor  last processed sentence id of the previous chain run (0 = start)
     * @param  int|null  $sentenceBudget  per-run stale-sentence budget; null = the indexer default
     */
    public function __construct(
        private readonly int $entityId,
        private readonly int $cursor = 0,
        private readonly ?int $sentenceBudget = null,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(EntityWordIndexer $indexer, EntityWordLinker $linker, EntityWordAdoption $adoption): void
    {
        $entity = Entity::query()->find($this->entityId);

        if ($entity === null) {
            Log::info('RefreshEntityWords skipped: entity missing', ['entity_id' => $this->entityId]);

            return;
        }

        $t0 = microtime(true);

        // Sentence-granular: only stale sentences are re-tokenized (a fresh
        // entity is a phase-1 no-op), at most $sentenceBudget of them past
        // the cursor. A queued duplicate therefore does no redundant
        // tokenization — it processes whatever is still stale, if anything.
        $stats = $indexer->indexBatch(
            $entity,
            $this->cursor,
            $this->sentenceBudget ?? EntityWordIndexer::SENTENCES_PER_RUN,
        );

        $chained = false;

        if ($stats['remaining']) {
            $chained = true;

            self::dispatch(entityId: $this->entityId, cursor: $stats['cursor'], sentenceBudget: $this->sentenceBudget);
        }

        $linkStats = $linker->link($entity);

        $adoptionStats = $adoption->adoptForEntity($entity);

        Log::info('RefreshEntityWords completed', array_filter([
            'entity_id' => $this->entityId,
            'reindexed' => $stats['processed'] > 0,
            'indexed' => $stats['processed'] ?: null,
            'chained' => $chained ?: null,
            'cursor' => $stats['cursor'] ?: null,
            'unique_words' => $stats['unique_words'],
            'linked' => $linkStats['linked'],
            'unmatched' => $linkStats['unmatched'],
            'adopted' => $adoptionStats['adopted'] ?: null,
            'fetches_dispatched' => $adoptionStats['dispatched'] ?: null,
            'total_ms' => (int) round((microtime(true) - $t0) * 1000),
        ]));
    }
}
