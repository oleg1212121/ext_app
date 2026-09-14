<?php

namespace App\Console\Commands;

use App\Classes\EntitySentenceImporter;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\Work;
use Database\Seeders\SimulatorEntitySeeder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

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

        $entities = $this->resolveSimulatorEntities();

        if ($entities->isEmpty()) {
            $this->error('No simulator entities found. Run: php artisan db:seed --class=SimulatorEntitySeeder');

            return self::FAILURE;
        }

        $pairs = $entities
            ->filter(fn (Entity $entity): bool => $this->basenameFromFilePath($entity->file_path) !== null)
            ->groupBy('work_id')
            ->map(fn (Collection $group): ?array => $this->pairForWork($group))
            ->filter()
            ->values();

        $rows = [];
        $failures = 0;

        foreach ($pairs as $pair) {
            /** @var array{basename: string, first: Entity, second: Entity} $pair */
            $basename = $pair['basename'];
            $first = $pair['first'];
            $second = $pair['second'];

            $existingMatch = EntityMatch::query()
                ->where(function ($query) use ($first, $second): void {
                    $query->where(function ($q) use ($first, $second): void {
                        $q->where('a_entity_id', min($first->id, $second->id))
                            ->where('b_entity_id', max($first->id, $second->id));
                    });
                })
                ->first();

            if ($this->option('skip-existing') && $existingMatch?->status === 'completed') {
                $rows[] = [$basename, $existingMatch->linked_count ?? 0, $existingMatch->id, 'skipped', 'Already completed'];

                continue;
            }

            $path = $this->importer->resolvePath($first->file_path ?? '');

            if ($path === null) {
                $rows[] = [$basename, 0, '-', 'failed', 'File not found'];
                $failures++;

                continue;
            }

            try {
                $result = $this->importer->import($first, $second, $path);
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
     * @return Collection<int, Entity>
     */
    private function resolveSimulatorEntities()
    {
        return Entity::query()
            ->where('file_path', 'like', SimulatorEntitySeeder::FILE_PATH_PREFIX.'%')
            ->orderBy('name')
            ->get();
    }

    /**
     * The two distinct-language entities of one simulator work, keyed by the
     * file basename. Simulator works are seeded with exactly two entities.
     *
     * @param  Collection<int, Entity>  $entities
     * @return array{basename: string, first: Entity, second: Entity}|null
     */
    private function pairForWork(Collection $entities): ?array
    {
        $distinct = $entities
            ->filter(fn (Entity $entity): bool => $this->basenameFromFilePath($entity->file_path) !== null)
            ->unique('language_id')
            ->values();

        if ($distinct->count() !== 2) {
            return null;
        }

        $first = $distinct[0];
        $basename = $this->basenameFromFilePath($first->file_path);

        if ($this->option('file') !== null && $basename !== (string) $this->option('file')) {
            return null;
        }

        return [
            'basename' => (string) $basename,
            'first' => $first,
            'second' => $distinct[1],
        ];
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
