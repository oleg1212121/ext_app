<?php

namespace App\Console\Commands;

use App\Classes\EntitySentenceImporter;
use App\Models\EnEntity;
use App\Models\EnRuEntityMatch;
use App\Models\RuEntity;
use Database\Seeders\SimulatorEntitySeeder;
use Illuminate\Console\Command;

class ImportSimulatorEntitiesCommand extends Command
{
    protected $signature = 'entities:import-simulator
                            {--file= : Import one basename, e.g. book_thief_1}
                            {--all : Import all simulator entities}
                            {--skip-existing : Skip pairs that already have a completed entity match}';

    protected $description = 'Import bilingual sentence pairs for simulator text entities';

    public function __construct(
        protected EntitySentenceImporter $importer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->option('all') && $this->option('file') === null) {
            $this->error('Specify --all or --file=<basename>.');

            return self::FAILURE;
        }

        $enEntities = $this->resolveEnEntities();

        if ($enEntities->isEmpty()) {
            $this->error('No simulator entities found. Run: php artisan db:seed --class=SimulatorEntitySeeder');

            return self::FAILURE;
        }

        $rows = [];
        $failures = 0;

        foreach ($enEntities as $enEntity) {
            $basename = $this->basenameFromFilePath($enEntity->file_path);

            if ($basename === null) {
                $rows[] = [$enEntity->file_path ?? '(missing)', 0, '-', 'failed', 'Invalid file path'];
                $failures++;

                continue;
            }

            $ruEntity = RuEntity::query()
                ->where('name', SimulatorEntitySeeder::ruEntityName($basename))
                ->first();

            if ($ruEntity === null) {
                $rows[] = [$basename, 0, '-', 'failed', 'Russian entity not found'];
                $failures++;

                continue;
            }

            $existingMatch = EnRuEntityMatch::query()
                ->where('en_entity_id', $enEntity->id)
                ->where('ru_entity_id', $ruEntity->id)
                ->first();

            if ($this->option('skip-existing') && $existingMatch?->status === 'completed') {
                $rows[] = [$basename, $existingMatch->linked_count ?? 0, $existingMatch->id, 'skipped', 'Already completed'];

                continue;
            }

            $path = $this->importer->resolvePath($enEntity->file_path ?? '');

            if ($path === null) {
                $rows[] = [$basename, 0, '-', 'failed', 'File not found'];
                $failures++;

                continue;
            }

            try {
                $result = $this->importer->import($enEntity, $ruEntity, $path);
                $rows[] = [$basename, $result->pairCount, $result->entityMatch->id, 'imported', ''];
            } catch (\RuntimeException $e) {
                $rows[] = [$basename, 0, '-', 'failed', $e->getMessage()];
                $failures++;
            }
        }

        $this->table(['File', 'Pairs', 'Match ID', 'Status', 'Message'], $rows);

        if ($failures > 0) {
            $this->error("{$failures} file(s) failed to import.");

            return self::FAILURE;
        }

        $this->info('Simulator import completed.');

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, EnEntity>
     */
    private function resolveEnEntities()
    {
        $query = EnEntity::query()
            ->where('file_path', 'like', SimulatorEntitySeeder::FILE_PATH_PREFIX.'%')
            ->orderBy('name');

        if ($this->option('file') !== null) {
            $basename = (string) $this->option('file');
            $query->where('name', SimulatorEntitySeeder::enEntityName($basename));
        }

        return $query->get();
    }

    private function basenameFromFilePath(?string $filePath): ?string
    {
        if ($filePath === null || ! str_starts_with($filePath, SimulatorEntitySeeder::FILE_PATH_PREFIX)) {
            return null;
        }

        $filename = substr($filePath, strlen(SimulatorEntitySeeder::FILE_PATH_PREFIX));

        if ($filename === '' || ! str_ends_with($filename, '.txt')) {
            return null;
        }

        return pathinfo($filename, PATHINFO_FILENAME);
    }
}
