<?php

namespace App\Console\Commands;

use App\Classes\EntityWordKnowledgeService;
use Illuminate\Console\Command;

class RefreshEntityWordKnowledgeCommand extends Command
{
    protected $signature = 'entities:refresh-word-knowledge
        {--limit=100 : Maximum user-entity pairs to recompute per run}';

    protected $description = 'Recompute stored word-knowledge scores older than three days or whose entity word list was rebuilt since. Scheduled every five minutes.';

    public function handle(EntityWordKnowledgeService $service): int
    {
        $refreshed = $service->refreshStale(max(1, (int) $this->option('limit')));

        if ($refreshed === 0) {
            $this->info('No word-knowledge scores need a refresh.');

            return self::SUCCESS;
        }

        $this->info("Refreshed {$refreshed} word-knowledge scores.");

        return self::SUCCESS;
    }
}
