<?php

namespace App\Jobs;

use App\Classes\SentenceSplitter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SplitEntityFileSentences implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public int $tries = 5;

    public function __construct(
        private readonly int $entityId,
        private readonly string $filePath,
        private readonly string $lang,
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

        $tSplit = microtime(true);
        $stats = $splitter->process($this->entityId, $this->filePath, $this->lang);
        $splitMs = (int) round((microtime(true) - $tSplit) * 1000);
        $totalMs = (int) round((microtime(true) - $t0) * 1000);

        Log::info('SplitEntityFileSentences completed', array_merge(
            $stats,
            [
                'entity_id' => $this->entityId,
                'lang' => $this->lang,
                'split_and_insert_ms' => $splitMs,
                'total_ms' => $totalMs,
                'used_passthrough_content' => false,
            ]
        ));
    }
}
