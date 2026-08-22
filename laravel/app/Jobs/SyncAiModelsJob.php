<?php

namespace App\Jobs;

use App\Services\AiModelSyncRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncAiModelsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    public const LOCK_KEY = 'ai-model-sync';

    public const LOCK_TTL = 300;

    public function __construct(
        private readonly string $lockOwner,
        private readonly ?string $provider = null,
    ) {}

    public function handle(AiModelSyncRegistry $registry): void
    {
        try {
            $syncers = ($this->provider !== null && $this->provider !== '')
                ? [$this->provider => $registry->for($this->provider)]
                : $registry->all();

            foreach ($syncers as $key => $syncer) {
                try {
                    $syncer->sync();
                    Log::info("AI model sync succeeded for {$key}");
                } catch (\Throwable $e) {
                    Log::error("AI model sync failed for {$key}", ['message' => $e->getMessage()]);
                }
            }
        } finally {
            Cache::restoreLock(self::LOCK_KEY, $this->lockOwner)->release();
        }
    }
}
