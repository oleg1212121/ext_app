<?php

namespace App\Jobs;

use App\Models\Entity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * First stage of the upload pipeline: verify the entity and hand the file to
 * the sentence splitter. Sentence extraction, hashing, exact-copy derivation
 * reuse and the embedding signature all run in the background — an upload
 * never calls the Python service synchronously (ADR 0033).
 */
#[Queue(QueueLane::DEFAULT)]
class ProcessEntityFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 5;

    public function __construct(
        private int $entityId,
        private string $filePath,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 60, 120, 300];
    }

    public function handle(): void
    {
        $entity = Entity::query()->findOrFail($this->entityId);

        Log::info('ProcessEntityFile dispatching sentence split', [
            'entity_id' => $this->entityId,
            'lang' => $entity->language?->code,
        ]);

        SplitEntityFileSentences::dispatch($this->entityId, $this->filePath);
    }
}
