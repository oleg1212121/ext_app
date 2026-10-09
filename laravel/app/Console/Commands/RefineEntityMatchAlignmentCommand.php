<?php

namespace App\Console\Commands;

use App\Classes\AlignmentRefineService;
use App\Models\EntityMatch;
use Illuminate\Console\Command;
use Throwable;

class RefineEntityMatchAlignmentCommand extends Command
{
    protected $signature = 'alignments:refine
        {entityMatch? : The entity match ID to refine (skipped with --all)}
        {--all : Refine every completed entity match}';

    protected $description = 'Second alignment round: re-align the regions around one-sided machine rows with the DP aligner and joined window embeddings, replacing machine rows only on strict score improvement';

    public function handle(AlignmentRefineService $refiner): int
    {
        if ($this->option('all')) {
            return $this->refineAll($refiner);
        }

        $id = (int) $this->argument('entityMatch');

        if ($id <= 0) {
            $this->error('Pass an entity match ID, or --all to refine every completed match.');

            return self::FAILURE;
        }

        $entityMatch = EntityMatch::query()->find($id);

        if ($entityMatch === null) {
            $this->error("Entity match {$id} not found.");

            return self::FAILURE;
        }

        $summary = $refiner->refine($entityMatch);

        $this->report($summary, "Entity match {$entityMatch->id}");

        return self::SUCCESS;
    }

    private function refineAll(AlignmentRefineService $refiner): int
    {
        $totalRegions = 0;
        $totalApplied = 0;
        $totalRejected = 0;
        $refined = 0;
        $failures = 0;

        EntityMatch::query()
            ->orderBy('id')
            ->chunkById(100, function ($chunk) use ($refiner, &$totalRegions, &$totalApplied, &$totalRejected, &$refined, &$failures): void {
                foreach ($chunk as $entityMatch) {
                    try {
                        $summary = $refiner->refine($entityMatch);

                        if ($summary['status'] !== 'refined') {
                            continue;
                        }

                        $refined++;
                        $totalRegions += $summary['regions'];
                        $totalApplied += $summary['applied'];
                        $totalRejected += $summary['rejected'];
                    } catch (Throwable $exception) {
                        $failures++;
                        $this->error("Entity match {$entityMatch->id}: {$exception->getMessage()}");
                    }
                }
            });

        $this->info(sprintf(
            'Refined %d entity match(es): %d region(s) considered, %d re-aligned, %d rejected (no improvement)%s.',
            $refined,
            $totalRegions,
            $totalApplied,
            $totalRejected,
            $failures > 0 ? ", {$failures} failure(s)" : '',
        ));

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array{status: string, reason?: string, regions: int, applied: int, rejected: int, skipped_regions: int, one_sided_before: int, one_sided_after: int}  $summary
     */
    private function report(array $summary, string $label): void
    {
        if ($summary['status'] === 'skipped') {
            $this->warn(sprintf('%s skipped: %s.', $label, $summary['reason'] ?? 'nothing to do'));

            return;
        }

        $this->info(sprintf(
            '%s: %d region(s) considered, %d re-aligned, %d rejected (no improvement), %d skipped; one-sided rows %d → %d.',
            $label,
            $summary['regions'],
            $summary['applied'],
            $summary['rejected'],
            $summary['skipped_regions'],
            $summary['one_sided_before'],
            $summary['one_sided_after'],
        ));
    }
}
