<?php

namespace App\Jobs;

use App\Classes\SentenceAlignmentService;
use App\Models\EnEntitySentence;
use App\Models\EnRuEntityMatch;
use App\Models\RuEntitySentence;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AlignEntitySentenceChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public int $tries = 5;

    public function __construct(
        private readonly int $entityMatchId,
        private readonly int $alignmentChunk,
        private readonly int $enOffset,
        private readonly int $ruOffset,
        private readonly int $chunkSize,
        private readonly bool $isLastChunk = false,
        private readonly ?int $ruWindowSize = null,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 60, 120, 300];
    }

    public function handle(): void
    {
        $entityMatch = EnRuEntityMatch::find($this->entityMatchId);

        if ($entityMatch === null) {
            return;
        }

        $service = SentenceAlignmentService::create();

        $enSentences = EnEntitySentence::query()
            ->where('en_entity_id', $entityMatch->en_entity_id)
            ->orderBy('order')
            ->orderBy('id')
            ->offset($this->enOffset)
            ->limit($this->chunkSize)
            ->get();

        $ruSentences = RuEntitySentence::query()
            ->where('ru_entity_id', $entityMatch->ru_entity_id)
            ->orderBy('order')
            ->orderBy('id')
            ->offset($this->ruOffset)
            ->limit($this->ruWindowSize ?? $this->chunkSize)
            ->get();

        $similarities = $service->buildAlignmentSimilarityMatrices(
            $enSentences,
            $ruSentences,
            $entityMatch->max_n,
        );

        $result = $service->alignChunk(
            $enSentences,
            $ruSentences,
            $similarities['individual'],
            $entityMatch->max_n,
            $similarities['groups'],
        );

        $service->storeAlignmentSegment(
            entityMatch: $entityMatch,
            alignmentChunk: $this->alignmentChunk,
            links: $result['links'],
            dpPathSegment: $result['dpPath'],
        );

        if ($this->isLastChunk) {
            $entityMatch->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        EnRuEntityMatch::whereKey($this->entityMatchId)->update([
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
            'completed_at' => now(),
        ]);
    }
}
