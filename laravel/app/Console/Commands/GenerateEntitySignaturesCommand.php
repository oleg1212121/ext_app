<?php

namespace App\Console\Commands;

use App\Jobs\GenerateEntitySignature;
use App\Models\Entity;
use Illuminate\Console\Command;

class GenerateEntitySignaturesCommand extends Command
{
    protected $signature = 'entity:generate-signatures';

    protected $description = 'Generate signatures for entities that have files but no signature';

    public function handle(): int
    {
        $entities = Entity::query()
            ->whereNotNull('file_path')
            ->whereNull('signature')
            ->get();

        foreach ($entities as $entity) {
            GenerateEntitySignature::dispatch($entity->id, $entity->file_path);
        }

        $this->info('Total jobs dispatched: '.$entities->count());

        return self::SUCCESS;
    }
}
