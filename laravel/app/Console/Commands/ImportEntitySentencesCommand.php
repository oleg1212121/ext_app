<?php

namespace App\Console\Commands;

use App\Classes\EntitySentenceImporter;
use App\Models\Entity;
use Illuminate\Console\Command;

class ImportEntitySentencesCommand extends Command
{
    protected $signature = 'entities:import-sentences
                            {file : Path to the bilingual text file}
                            {first_entity_id : First entity ID}
                            {second_entity_id : Second entity ID}';

    protected $description = 'Import bilingual sentence pairs and create meaning matches';

    public function __construct(
        protected EntitySentenceImporter $importer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = $this->importer->resolvePath($this->argument('file'));

        if ($path === null) {
            $this->error("File not found: {$this->argument('file')}");

            return self::FAILURE;
        }

        $firstEntity = Entity::query()->find($this->argument('first_entity_id'));
        if ($firstEntity === null) {
            $this->error("First entity not found: {$this->argument('first_entity_id')}");

            return self::FAILURE;
        }

        $secondEntity = Entity::query()->find($this->argument('second_entity_id'));
        if ($secondEntity === null) {
            $this->error("Second entity not found: {$this->argument('second_entity_id')}");

            return self::FAILURE;
        }

        try {
            $result = $this->importer->import($firstEntity, $secondEntity, $path);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Import completed!');
        $this->table(['Metric', 'Value'], [
            ['Pairs imported', $result->pairCount],
            ['Entity match ID', $result->entityMatch->id],
            ['A entity ID', $result->aEntity->id],
            ['B entity ID', $result->bEntity->id],
        ]);

        return self::SUCCESS;
    }
}
