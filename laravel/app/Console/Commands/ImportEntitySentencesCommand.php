<?php

namespace App\Console\Commands;

use App\Classes\EntitySentenceImporter;
use App\Models\EnEntity;
use App\Models\RuEntity;
use Illuminate\Console\Command;

class ImportEntitySentencesCommand extends Command
{
    protected $signature = 'entities:import-sentences
                            {file : Path to the bilingual text file}
                            {en_entity_id : English entity ID}
                            {ru_entity_id : Russian entity ID}';

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

        $enEntity = EnEntity::query()->find($this->argument('en_entity_id'));
        if ($enEntity === null) {
            $this->error("English entity not found: {$this->argument('en_entity_id')}");

            return self::FAILURE;
        }

        $ruEntity = RuEntity::query()->find($this->argument('ru_entity_id'));
        if ($ruEntity === null) {
            $this->error("Russian entity not found: {$this->argument('ru_entity_id')}");

            return self::FAILURE;
        }

        try {
            $result = $this->importer->import($enEntity, $ruEntity, $path);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Import completed!');
        $this->table(['Metric', 'Value'], [
            ['Pairs imported', $result->pairCount],
            ['Entity match ID', $result->entityMatch->id],
            ['EN entity ID', $result->enEntity->id],
            ['RU entity ID', $result->ruEntity->id],
        ]);

        return self::SUCCESS;
    }
}
