<?php

namespace App\Jobs;

use App\Classes\EntityWordIndexer;
use App\Classes\EntityWordLinker;
use App\Models\Entity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RefreshEntityWords implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    public function __construct(private readonly int $entityId) {}

    public function uniqueId(): string
    {
        return (string) $this->entityId;
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(EntityWordIndexer $indexer, EntityWordLinker $linker): void
    {
        $entity = Entity::query()->find($this->entityId);

        if ($entity === null) {
            Log::info('RefreshEntityWords skipped: entity missing', ['entity_id' => $this->entityId]);

            return;
        }

        $t0 = microtime(true);

        // Re-check staleness at run time: an earlier queued duplicate may have
        // already rebuilt the list between dispatch and here.
        $reindexed = false;
        $uniqueWords = 0;

        if ($indexer->isStale($entity)) {
            $uniqueWords = $indexer->index($entity);
            $reindexed = true;
        }

        $stats = $linker->link($entity);

        Log::info('RefreshEntityWords completed', array_filter([
            'entity_id' => $this->entityId,
            'reindexed' => $reindexed,
            'unique_words' => $reindexed ? $uniqueWords : null,
            'linked' => $stats['linked'],
            'unmatched' => $stats['unmatched'],
            'total_ms' => (int) round((microtime(true) - $t0) * 1000),
        ]));
    }
}
