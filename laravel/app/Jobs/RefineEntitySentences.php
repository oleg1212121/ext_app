<?php

namespace App\Jobs;

use App\Classes\AlignmentRefineService;
use App\Classes\MeaningMatchStore;
use App\Models\EntityMatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The alignment refine round, queued: re-align the regions around one-sided
 * machine rows with the DP aligner + joined window embeddings (see
 * AlignmentRefineService). One self-contained run per dispatch — regions are
 * bounded and each is its own transaction, so a hard kill leaves applied
 * regions applied and the remaining one-sided rows as candidates for a
 * re-run.
 */
#[Queue(QueueLane::DEFAULT)]
class RefineEntitySentences implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 2;

    public function __construct(
        private readonly int $entityMatchId,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60];
    }

    public function handle(): void
    {
        $entityMatch = EntityMatch::query()->find($this->entityMatchId);

        if ($entityMatch === null) {
            return;
        }

        $summary = app(AlignmentRefineService::class)->refine($entityMatch);

        Log::info('Alignment refine finished', [
            'entity_match_id' => $this->entityMatchId,
            ...$summary,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $entityMatch = EntityMatch::find($this->entityMatchId);

        if ($entityMatch === null) {
            return;
        }

        // refine() restores completed itself on a handled failure; this catch
        // is for hard kills (timeout, worker crash) mid-run. Regions applied
        // so far stay; normalize the order column and coverage best-effort so
        // the editor reads a consistent match either way.
        if ($entityMatch->status === 'aligning') {
            $entityMatch->update(['status' => 'completed']);
        }

        try {
            MeaningMatchStore::create()->repairCoverage($entityMatch);
        } catch (Throwable $repairFailure) {
            Log::warning('Alignment refine failure left coverage unrepaired; run alignments:repair', [
                'entity_match_id' => $this->entityMatchId,
                'error' => $repairFailure->getMessage(),
            ]);
        }

        Log::warning('Alignment refine failed', [
            'entity_match_id' => $this->entityMatchId,
            'error' => $exception->getMessage(),
        ]);
    }
}
