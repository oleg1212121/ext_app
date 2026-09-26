<?php

namespace App\Jobs;

use App\Classes\SentenceSplitter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Second stage of the upload pipeline: sentence-split the uploaded file in
 * bounded runs (a run feeds a limited number of byte chunks to the Python
 * service, then re-dispatches itself until the file is consumed — ADR 0043).
 * A failed or interrupted run resumes from the last committed chunk instead
 * of re-splitting the whole file; the final stage (FinalizeEntityDerivations)
 * is dispatched only at end-of-file.
 */
#[Queue(QueueLane::DEFAULT)]
class SplitEntityFileSentences implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 5;

    public function __construct(
        private readonly int $entityId,
        private readonly string $filePath,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 60, 120, 300];
    }

    public function handle(SentenceSplitter $splitter): void
    {
        $t0 = microtime(true);

        $stats = $splitter->process($this->entityId, $this->filePath);

        Log::info('SplitEntityFileSentences run completed', array_merge($stats, [
            'entity_id' => $this->entityId,
            'total_ms' => (int) round((microtime(true) - $t0) * 1000),
        ]));

        if (! ($stats['eof'] ?? false)) {
            self::dispatch($this->entityId, $this->filePath);

            return;
        }

        FinalizeEntityDerivations::dispatch($this->entityId, $this->filePath);
    }
}
