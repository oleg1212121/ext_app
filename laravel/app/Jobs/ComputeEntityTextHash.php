<?php

namespace App\Jobs;

use App\Classes\EntityTextHasher;
use App\Models\Entity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

#[Queue(QueueLane::DEFAULT)]
class ComputeEntityTextHash implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

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
        return [30, 120];
    }

    public function handle(EntityTextHasher $hasher): void
    {
        $entity = Entity::query()->find($this->entityId);

        if ($entity === null) {
            Log::info('ComputeEntityTextHash skipped: entity missing', ['entity_id' => $this->entityId]);

            return;
        }

        $t0 = microtime(true);

        // Re-check staleness at run time: an earlier queued duplicate (or the
        // synchronous fallback) may have hashed this entity between dispatch
        // and here.
        if (! $hasher->isStale($entity)) {
            Log::info('ComputeEntityTextHash skipped: hash fresh', ['entity_id' => $this->entityId]);

            return;
        }

        $hash = $hasher->refreshIfStale($entity);

        Log::info('ComputeEntityTextHash completed', [
            'entity_id' => $this->entityId,
            'text_hash' => $hash,
            'total_ms' => (int) round((microtime(true) - $t0) * 1000),
        ]);
    }
}
