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

    /**
     * Runs are bounded (region budget + wall-clock deadline), so one match's
     * refine may need several runs — the CLI has no timeout, so it just keeps
     * feeding the returned cursor back until has_more drops.
     */
    private const MAX_RUNS_PER_MATCH = 1000;

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

        $summary = $this->refineFully($refiner, $entityMatch);

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
                        $summary = $this->refineFully($refiner, $entityMatch);

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
     * Drive one match's bounded runs to completion, accumulating the last
     * full-run counters. Each run still flips the match through aligning and
     * repairs coverage, so an interrupted loop leaves the match consistent —
     * a re-run continues from the remaining one-sided rows.
     *
     * @return array{status: string, reason?: string, regions: int, applied: int, rejected: int, skipped_regions: int, one_sided_before: int, one_sided_after: int, has_more: bool, cursor: int|null}
     */
    private function refineFully(AlignmentRefineService $refiner, EntityMatch $entityMatch): array
    {
        $cursor = null;
        $runs = 0;

        do {
            $summary = $refiner->refine($entityMatch, $cursor);

            if ($summary['status'] !== 'refined') {
                return $summary;
            }

            $runs++;
            $cursor = $summary['cursor'];

            if ($summary['has_more'] && $runs >= self::MAX_RUNS_PER_MATCH) {
                $this->warn(sprintf(
                    'Entity match %d still has one-sided regions after %d runs; re-run the command to continue.',
                    $entityMatch->id,
                    $runs,
                ));

                break;
            }
        } while ($summary['has_more']);

        return $summary;
    }

    /**
     * @param  array{status: string, reason?: string, regions: int, applied: int, rejected: int, skipped_regions: int, one_sided_before: int, one_sided_after: int, has_more: bool, cursor: int|null}  $summary
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
