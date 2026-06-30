<?php

namespace App\Console\Commands;

use App\Jobs\GenerateEntitySignature;
use App\Models\EnEntity;
use App\Models\RuEntity;
use Illuminate\Console\Command;

class GenerateEntitySignaturesCommand extends Command
{
    protected $signature = 'entity:generate-signatures
                            {--lang=all : Language to process (en, ru, or all)}';

    protected $description = 'Generate signatures for entities that have files but no signature';

    public function handle(): int
    {
        $lang = $this->option('lang');

        $total = 0;

        if (in_array($lang, ['en', 'all'])) {
            $count = $this->processLanguage('en', EnEntity::class);
            $total += $count;
            $this->info("Dispatched {$count} signature jobs for English entities.");
        }

        if (in_array($lang, ['ru', 'all'])) {
            $count = $this->processLanguage('ru', RuEntity::class);
            $total += $count;
            $this->info("Dispatched {$count} signature jobs for Russian entities.");
        }

        $this->info("Total jobs dispatched: {$total}");

        return self::SUCCESS;
    }

    private function processLanguage(string $lang, string $modelClass): int
    {
        $entities = $modelClass::whereNotNull('file_path')
            ->whereNull('signature')
            ->get();

        foreach ($entities as $entity) {
            GenerateEntitySignature::dispatch($entity->id, $entity->file_path, $lang);
        }

        return $entities->count();
    }
}
