<?php

namespace App\Jobs;

use App\Classes\SentenceAlignmentService;
use App\Models\EnEntity;
use App\Models\EnEntitySentence;
use App\Models\EnRuEntityMatch;
use App\Models\RuEntity;
use App\Models\RuEntitySentence;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

class AlignEntitySentences implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAX_EFFECTIVE_CHUNK_SIZE = 75;

    private const MAX_EFFECTIVE_SPAN = 8;

    private const RU_WINDOW_OVERLAP = 25;

    public int $timeout = 180;

    public int $tries = 5;

    public function __construct(
        private readonly int $entityMatchId,
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
        $entityMatch = EnRuEntityMatch::findOrFail($this->entityMatchId);

        $enEntity = EnEntity::findOrFail($entityMatch->en_entity_id);
        $ruEntity = RuEntity::findOrFail($entityMatch->ru_entity_id);

        $service = SentenceAlignmentService::create();

        $entityMatch->update(['status' => 'verifying', 'started_at' => now()]);

        $verification = $service->verifyEntityPair($enEntity, $ruEntity);

        $entityMatch->update(['entity_similarity' => $verification['similarity']]);

        if (! $verification['passed']) {
            $entityMatch->update([
                'status' => 'failed',
                'error_message' => $verification['message'],
                'completed_at' => now(),
            ]);

            return;
        }

        $entityMatch->meaningMatches()->delete();

        $entityMatch->update(['status' => 'aligning']);

        $enSentenceCount = EnEntitySentence::where('en_entity_id', $enEntity->id)->count();
        $ruSentenceCount = RuEntitySentence::where('ru_entity_id', $ruEntity->id)->count();

        $chunkSize = min(
            max((int) $entityMatch->chunk_size, 1),
            self::MAX_EFFECTIVE_CHUNK_SIZE,
        );
        $maxN = min(
            max((int) $entityMatch->max_n, 1),
            self::MAX_EFFECTIVE_SPAN,
        );

        $entityMatch->update([
            'en_total_sentences' => $enSentenceCount,
            'ru_total_sentences' => $ruSentenceCount,
            'linked_count' => 0,
            'chunk_size' => $chunkSize,
            'max_n' => $maxN,
            'dp_path' => null,
            'error_message' => null,
        ]);

        if ($enSentenceCount === 0 || $ruSentenceCount === 0) {
            $entityMatch->update([
                'status' => 'completed',
                'error_message' => 'One or both entities have no sentences',
                'completed_at' => now(),
            ]);

            return;
        }

        $chunkCount = (int) ceil($enSentenceCount / $chunkSize);

        $jobs = [];

        for ($chunkIndex = 0; $chunkIndex < $chunkCount; $chunkIndex++) {
            $enOffset = $chunkIndex * $chunkSize;
            $enLimit = min($chunkSize, $enSentenceCount - $enOffset);
            $ruWindow = $this->ruWindowForEnRange(
                enOffset: $enOffset,
                enLimit: $enLimit,
                enSentenceCount: $enSentenceCount,
                ruSentenceCount: $ruSentenceCount,
            );

            $jobs[] = new AlignEntitySentenceChunk(
                entityMatchId: $entityMatch->id,
                alignmentChunk: $chunkIndex,
                enOffset: $enOffset,
                ruOffset: $ruWindow['offset'],
                chunkSize: $enLimit,
                isLastChunk: $chunkIndex === $chunkCount - 1,
                ruWindowSize: $ruWindow['limit'],
            );
        }

        Bus::chain($jobs)->dispatch();
    }

    /**
     * @return array{offset: int, limit: int}
     */
    private function ruWindowForEnRange(int $enOffset, int $enLimit, int $enSentenceCount, int $ruSentenceCount): array
    {
        $enStartProgress = $enOffset / max($enSentenceCount, 1);
        $enEndProgress = ($enOffset + $enLimit) / max($enSentenceCount, 1);
        $ruStart = (int) floor($enStartProgress * $ruSentenceCount) - self::RU_WINDOW_OVERLAP;
        $ruEnd = (int) ceil($enEndProgress * $ruSentenceCount) + self::RU_WINDOW_OVERLAP;
        $ruStart = max(0, $ruStart);
        $ruEnd = min($ruSentenceCount, max($ruStart + 1, $ruEnd));

        return [
            'offset' => $ruStart,
            'limit' => $ruEnd - $ruStart,
        ];
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
