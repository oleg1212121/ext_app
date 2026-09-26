<?php

namespace App\Console\Commands;

use App\Jobs\GenerateEntitySignature;
use App\Models\Entity;
use Illuminate\Console\Command;

class GenerateEntitySignaturesCommand extends Command
{
    protected $signature = 'entity:generate-signatures
        {--limit=100 : Maximum entities to dispatch per run}';

    protected $description = 'Generate signatures for entities that have files but no signature';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $entities = Entity::query()
            ->whereNotNull('file_path')
            ->whereNull('signature')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'file_path']);

        foreach ($entities as $entity) {
            GenerateEntitySignature::dispatch($entity->id, $entity->file_path);
        }

        $this->info('Total jobs dispatched: '.$entities->count());

        return self::SUCCESS;
    }
}
