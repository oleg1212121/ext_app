<?php

namespace App\Console\Commands;

use App\Jobs\ComputeEntityTextHash;
use App\Models\Entity;
use Illuminate\Console\Command;

class RefreshEntityTextHashesCommand extends Command
{
    protected $signature = 'entities:refresh-text-hashes
        {--entity=* : Entity ids to refresh (all stale entities when omitted)}
        {--limit=100 : Maximum entities to dispatch per run}
        {--dry-run : Report what would be dispatched without dispatching}';

    protected $description = 'Find entities with a stale text hash and dispatch background rehash jobs. Scheduled every five minutes.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $entities = $this->entities($limit);

        if ($entities->isEmpty()) {
            $this->info('No entities need a text-hash refresh.');

            return self::SUCCESS;
        }

        $dispatched = 0;

        foreach ($entities as $entity) {
            if ($dryRun) {
                $this->line("Would rehash entity #{$entity->id} ({$entity->name})");
            } else {
                ComputeEntityTextHash::dispatch($entity->id);
            }

            $dispatched++;
        }

        $verb = $dryRun ? 'Would dispatch' : 'Dispatched';
        $this->info("{$verb} {$dispatched} text-hash jobs.");

        return self::SUCCESS;
    }

    private function entities(int $limit)
    {
        $ids = array_map(intval(...), (array) $this->option('entity'));

        $query = Entity::query()->select(['id', 'name']);

        if ($ids !== []) {
            return $query->whereIn('id', $ids)->orderBy('id')->limit($limit)->get();
        }

        return $query
            // Stale hash: never computed, or any sentence mutation happened
            // after the last computation (mirrors EntityTextHasher::isStale).
            ->where(function ($stale): void {
                $stale->whereNull('entities.text_hash')
                    ->orWhereNull('entities.text_hashed_at')
                    ->orWhere(function ($touched): void {
                        $touched->whereNotNull('entities.sentences_updated_at')
                            ->whereColumn('entities.sentences_updated_at', '>', 'entities.text_hashed_at');
                    });
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }
}
