<?php

namespace App\Console\Commands;

use App\Services\AiModelSyncRegistry;
use Illuminate\Console\Command;

class SyncAiModels extends Command
{
    protected $signature = 'ai:sync-models {--provider= : Only sync the given provider key}';

    protected $description = 'Fetch available AI models from each configured provider and store them';

    public function handle(AiModelSyncRegistry $registry): int
    {
        $provider = $this->option('provider');

        if ($provider !== null && $provider !== '') {
            if (! in_array($provider, $registry->keys(), true)) {
                $this->error("Unknown provider: {$provider}");

                return self::FAILURE;
            }

            $syncers = [$provider => $registry->for($provider)];
        } else {
            $syncers = $registry->all();
        }

        $this->info('Syncing AI models...');

        $synced = 0;
        $failed = 0;

        foreach ($syncers as $key => $syncer) {
            try {
                $count = $syncer->sync();
                $this->info("Synced {$count} {$key} models.");
                $synced++;
            } catch (\Throwable $e) {
                $this->error("FAILED {$key}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("Done: {$synced} synced, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
