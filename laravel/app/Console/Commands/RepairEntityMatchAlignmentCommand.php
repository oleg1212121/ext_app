<?php

namespace App\Console\Commands;

use App\Classes\MeaningMatchStore;
use App\Models\EntityMatch;
use App\Models\MeaningMatch;
use Illuminate\Console\Command;
use Throwable;

class RepairEntityMatchAlignmentCommand extends Command
{
    protected $signature = 'alignments:repair
        {entityMatch? : The entity match ID to repair (skipped with --all)}
        {--all : Repair every entity match, chunked}';

    protected $description = 'Repair an alignment in place: resolve duplicate junctions, backfill single-sided rows for junction-less sentences on both sides, resequence by document position';

    public function handle(): int
    {
        if ($this->option('all')) {
            return $this->repairAll();
        }

        $id = (int) $this->argument('entityMatch');

        if ($id <= 0) {
            $this->error('Pass an entity match ID, or --all to repair every match.');

            return self::FAILURE;
        }

        $entityMatch = EntityMatch::query()->find($id);

        if ($entityMatch === null) {
            $this->error("Entity match {$id} not found.");

            return self::FAILURE;
        }

        [$repaired, $created, $resequenced] = $this->repair($entityMatch);

        $this->info(sprintf(
            'Entity match %d: %d duplicate junction row(s) removed, %d single-sided row(s) created, %d order/junction change(s).',
            $entityMatch->id,
            $repaired,
            $created,
            $resequenced,
        ));

        return self::SUCCESS;
    }

    private function repairAll(): int
    {
        $totalMatches = 0;
        $totalRepairs = 0;
        $totalCreated = 0;
        $failures = 0;

        EntityMatch::query()
            ->orderBy('id')
            ->chunkById(100, function ($chunk) use (&$totalMatches, &$totalRepairs, &$totalCreated, &$failures): void {
                foreach ($chunk as $entityMatch) {
                    $totalMatches++;

                    try {
                        [$repaired, $created] = $this->repair($entityMatch);
                        $totalRepairs += $repaired;
                        $totalCreated += $created;
                    } catch (Throwable $exception) {
                        $failures++;
                        $this->error("Entity match {$entityMatch->id}: {$exception->getMessage()}");
                    }
                }
            });

        $this->info(sprintf(
            'Repaired %d entity match(es): %d duplicate junction row(s) removed, %d single-sided row(s) created%s.',
            $totalMatches,
            $totalRepairs,
            $totalCreated,
            $failures > 0 ? ", {$failures} failure(s)" : '',
        ));

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The in-place repair for one match: strict junction-uniqueness pass +
     * resequence (one store call, the dedupe's $removed count comes from the
     * row delta), then repairCoverage — the junction-less backfill on both
     * sides, its resequence, and the linked_count sync, whole-or-nothing in
     * one transaction.
     *
     * @return array{0: int, 1: int, 2: int} [rows removed by the dedupe, single-sided rows created, total order/junction changes]
     */
    private function repair(EntityMatch $entityMatch): array
    {
        $store = MeaningMatchStore::create();

        $rowCount = fn (): int => (int) MeaningMatch::query()
            ->where('entity_match_id', $entityMatch->id)
            ->count();

        $beforeDedupe = $rowCount();

        $resequenced = $store->resequenceMatchesByDocumentPosition($entityMatch);

        $removed = $beforeDedupe - $rowCount();

        [$created, $coverageResequenced] = $store->repairCoverage($entityMatch);

        return [$removed, $created, $resequenced + $coverageResequenced];
    }
}
