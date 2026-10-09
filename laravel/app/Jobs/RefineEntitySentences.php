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
 * AlignmentRefineService). One bounded run per execution — the service
 * stops at its region budget or wall-clock deadline and reports `has_more`
 * with a sentence-position cursor; this job then re-dispatches itself with
 * that cursor (same pattern as AlignEntitySentences' offset chain). A whole
 * book can carry hundreds of one-sided regions, more than one execution may
 * align inside the timeout. Each run applies what it can and ends with
 * repairCoverage, so a hard kill leaves applied regions applied, the match
 * at total coverage, and the remaining one-sided rows as candidates for the
 * next link — or for a manual re-run.
 */
#[Queue(QueueLane::DEFAULT)]
class RefineEntitySentences implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Chain links allowed before the job stops re-dispatching and asks for a
     * re-run — a runaway guard (200 links × 50 regions is far past any real
     * book's one-sided count); it can only fire on a service that keeps
     * reporting progress without exhausting the cursor.
     */
    private const MAX_CHAIN_LINKS = 200;

    public int $timeout = 600;

    public int $tries = 2;

    public function __construct(
        public readonly int $entityMatchId,
        public readonly ?int $afterPosition = null,
        public readonly ?int $maxRegions = null,
        public readonly int $chainDepth = 0,
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

        $summary = app(AlignmentRefineService::class)->refine($entityMatch, $this->afterPosition, $this->maxRegions);

        Log::info('Alignment refine chunk finished', [
            'entity_match_id' => $this->entityMatchId,
            'chain_depth' => $this->chainDepth,
            ...$summary,
        ]);

        if (! ($summary['has_more'] ?? false)) {
            return;
        }

        if ($this->chainDepth + 1 >= self::MAX_CHAIN_LINKS) {
            Log::warning('Alignment refine chain cap reached; run the command or the button again to continue', [
                'entity_match_id' => $this->entityMatchId,
                'chain_depth' => $this->chainDepth,
                'cursor' => $summary['cursor'],
            ]);

            return;
        }

        self::dispatch($this->entityMatchId, $summary['cursor'], $this->maxRegions, $this->chainDepth + 1);
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
