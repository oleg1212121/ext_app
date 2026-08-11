<?php

namespace App\Jobs;

use App\Classes\SentenceAlignmentService;
use App\Models\EnEntity;
use App\Models\EnEntitySentence;
use App\Models\EnRuEntityMatch;
use App\Models\EnRuMeaningMatch;
use App\Models\RuEntity;
use App\Models\RuEntitySentence;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AlignEntitySentences implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAX_EFFECTIVE_CHUNK_SIZE = 75;

    private const MAX_EFFECTIVE_SPAN = 8;

    private const ANCHOR_SCORE_THRESHOLD = 0.40;

    private const ROLLBACK_MATCHES = 2;

    public int $timeout = 600;

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

    /**
     * Begin a fresh alignment run for an entity match: verify the pair,
     * reset progress, snapshot totals, transition to aligning, and dispatch
     * the first chunk job. Shared by the 5-minute command, the Filament
     * "Re-run" action, and all the "new alignment" dispatch sites.
     */
    public static function begin(int $entityMatchId): void
    {
        $entityMatch = EnRuEntityMatch::find($entityMatchId);

        if ($entityMatch === null) {
            return;
        }

        $enEntity = EnEntity::find($entityMatch->en_entity_id);
        $ruEntity = RuEntity::find($entityMatch->ru_entity_id);

        if ($enEntity === null || $ruEntity === null) {
            $entityMatch->update([
                'status' => 'failed',
                'error_message' => 'Missing en or ru entity for alignment',
                'completed_at' => now(),
            ]);

            return;
        }

        $service = SentenceAlignmentService::create();

        $verification = $service->verifyEntityPair($enEntity, $ruEntity);

        if (! $verification['passed']) {
            $entityMatch->update([
                'status' => 'failed',
                'entity_similarity' => $verification['similarity'],
                'error_message' => $verification['message'],
                'started_at' => now(),
                'completed_at' => now(),
            ]);

            return;
        }

        $enSentenceCount = EnEntitySentence::query()
            ->where('en_entity_id', $enEntity->id)
            ->count();
        $ruSentenceCount = RuEntitySentence::query()
            ->where('ru_entity_id', $ruEntity->id)
            ->count();

        $chunkSize = min(max((int) $entityMatch->chunk_size, 1), self::MAX_EFFECTIVE_CHUNK_SIZE);
        $maxN = min(max((int) $entityMatch->max_n, 1), self::MAX_EFFECTIVE_SPAN);

        $entityMatch->meaningMatches()->delete();

        $entityMatch->update([
            'status' => 'aligning',
            'entity_similarity' => $verification['similarity'],
            'en_total_sentences' => $enSentenceCount,
            'ru_total_sentences' => $ruSentenceCount,
            'linked_count' => 0,
            'chunk_size' => $chunkSize,
            'max_n' => $maxN,
            'last_en_sentence_offset' => 0,
            'last_ru_sentence_offset' => 0,
            'error_message' => null,
            'started_at' => now(),
            'completed_at' => null,
        ]);

        if ($enSentenceCount === 0 || $ruSentenceCount === 0) {
            $entityMatch->update([
                'status' => 'completed',
                'error_message' => 'One or both entities have no sentences',
                'completed_at' => now(),
            ]);

            return;
        }

        self::dispatch($entityMatchId);
    }

    public function handle(): void
    {
        $entityMatch = EnRuEntityMatch::find($this->entityMatchId);

        if ($entityMatch === null) {
            return;
        }

        $enEntity = EnEntity::find($entityMatch->en_entity_id);
        $ruEntity = RuEntity::find($entityMatch->ru_entity_id);

        if ($enEntity === null || $ruEntity === null) {
            $entityMatch->update([
                'status' => 'failed',
                'error_message' => 'Missing en or ru entity for alignment',
                'completed_at' => now(),
            ]);

            return;
        }

        $enTotal = (int) $entityMatch->en_total_sentences;
        $ruTotal = (int) $entityMatch->ru_total_sentences;

        $chunkSize = (int) $entityMatch->chunk_size;
        $enOffset = (int) $entityMatch->last_en_sentence_offset;
        $ruOffset = (int) $entityMatch->last_ru_sentence_offset;

        $enLimit = min($chunkSize, max(0, $enTotal - $enOffset));
        $ruLimit = min($chunkSize, max(0, $ruTotal - $ruOffset));

        if ($enLimit <= 0) {
            $entityMatch->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            return;
        }

        if ($ruLimit <= 0) {
            $entityMatch->update([
                'status' => 'completed',
                'error_message' => 'RU sentences exhausted before EN',
                'last_en_sentence_offset' => $enOffset + $enLimit,
                'completed_at' => now(),
            ]);

            return;
        }

        $storedEnOffset = $enOffset;
        $storedRuOffset = $ruOffset;

        $rollback = $this->rollbackPriorMatches(
            entityMatch: $entityMatch,
            enEntity: $enEntity,
            ruEntity: $ruEntity,
            enOffset: $enOffset,
            ruOffset: $ruOffset,
            enLimit: $enLimit,
            ruLimit: $ruLimit,
            enTotal: $enTotal,
            ruTotal: $ruTotal,
        );

        $enOffset = $rollback['en_offset'];
        $ruOffset = $rollback['ru_offset'];
        $enLimit = $rollback['en_limit'];
        $ruLimit = $rollback['ru_limit'];

        $enSentences = EnEntitySentence::query()
            ->where('en_entity_id', $enEntity->id)
            ->orderBy('order')
            ->orderBy('id')
            ->offset($enOffset)
            ->limit($enLimit)
            ->get();

        $ruSentences = RuEntitySentence::query()
            ->where('ru_entity_id', $ruEntity->id)
            ->orderBy('order')
            ->orderBy('id')
            ->offset($ruOffset)
            ->limit($ruLimit)
            ->get();

        $service = SentenceAlignmentService::create();

        $matches = $service->alignChunkRemote(
            $enSentences,
            $ruSentences,
            $entityMatch->max_n,
        )['matches'];

        $isLastChunk = $enOffset + $enLimit >= $enTotal
            && $ruOffset + $ruLimit >= $ruTotal;

        $committed = $this->committedMatches($matches, $isLastChunk);

        $lastCommitted = $committed[array_key_last($committed)] ?? null;

        if ($lastCommitted === null) {
            $this->dispatchNext(
                $entityMatch,
                max($enOffset, $storedEnOffset) + min(1, $enLimit),
                $storedRuOffset,
                $enTotal,
            );

            return;
        }

        $alignmentChunk = $this->nextAlignmentChunk($entityMatch->id);

        $service->storeAlignmentSegmentFromMatches(
            entityMatch: $entityMatch,
            alignmentChunk: $alignmentChunk,
            committedMatches: $committed,
            enSentences: $enSentences,
            ruSentences: $ruSentences,
            isLastChunk: $isLastChunk,
        );

        $newEnOffset = $enOffset + (int) $lastCommitted['en_end'];
        $newRuOffset = $ruOffset + (int) $lastCommitted['ru_end'];

        if ($newEnOffset <= $storedEnOffset) {
            $newEnOffset = $storedEnOffset + min(1, $enLimit);
            $newRuOffset = $storedRuOffset;
        }

        $this->dispatchNext($entityMatch, $newEnOffset, $newRuOffset, $enTotal);
    }

    /**
     * Drag the last committed matches back into the window before aligning so
     * the DP sees the context preceding the chunk seam. The strict 1:1 RU
     * window of ADR-0004 means python's DP force-aligns the head of each new
     * chunk with no backward reach, producing the 1:5 / 5:1 garbage trim can
     * only catch on the tail. Rolling back the last few commits (deleting the
     * meaning-match rows, junctions cascade via FK) and re-aligning them
     * against fresh forward context lets the seam dissolve into a clean
     * 1:1 progression. Skip-en/skip-ru steps anchor no sentences and are not
     * candidates. The limits grow by the rolled-back spans so the window's
     * forward reach is unchanged.
     *
     * @return array{en_offset: int, ru_offset: int, en_limit: int, ru_limit: int}
     */
    private function rollbackPriorMatches(
        EnRuEntityMatch $entityMatch,
        EnEntity $enEntity,
        RuEntity $ruEntity,
        int $enOffset,
        int $ruOffset,
        int $enLimit,
        int $ruLimit,
        int $enTotal,
        int $ruTotal,
    ): array {
        $candidates = EnRuMeaningMatch::query()
            ->where('en_ru_entity_match_id', $entityMatch->id)
            ->where('alignment_chunk', '!=', -1)
            ->whereHas('enSentenceMatches')
            ->whereHas('ruSentenceMatches')
            ->orderByDesc('order')
            ->limit(self::ROLLBACK_MATCHES)
            ->get();

        if ($candidates->isEmpty()) {
            return [
                'en_offset' => $enOffset,
                'ru_offset' => $ruOffset,
                'en_limit' => $enLimit,
                'ru_limit' => $ruLimit,
            ];
        }

        $enSentenceIds = [];
        $ruSentenceIds = [];

        foreach ($candidates as $candidate) {
            $enSentenceIds = [
                ...$enSentenceIds,
                ...$candidate->enSentenceMatches()->pluck('en_entity_sentence_id')->all(),
            ];
            $ruSentenceIds = [
                ...$ruSentenceIds,
                ...$candidate->ruSentenceMatches()->pluck('ru_entity_sentence_id')->all(),
            ];
        }

        $newEnOffset = $this->rollbackOffset(
            EnEntitySentence::class,
            'en_entity_id',
            $enEntity->id,
            $enSentenceIds,
            $enOffset,
        );
        $newRuOffset = $this->rollbackOffset(
            RuEntitySentence::class,
            'ru_entity_id',
            $ruEntity->id,
            $ruSentenceIds,
            $ruOffset,
        );

        EnRuMeaningMatch::whereKey($candidates->pluck('id'))->delete();

        $enRollback = max(0, $enOffset - $newEnOffset);
        $ruRollback = max(0, $ruOffset - $newRuOffset);

        return [
            'en_offset' => $newEnOffset,
            'ru_offset' => $newRuOffset,
            'en_limit' => min($enLimit + $enRollback, max(0, $enTotal - $newEnOffset)),
            'ru_limit' => min($ruLimit + $ruRollback, max(0, $ruTotal - $newRuOffset)),
        ];
    }

    /**
     * Offset (0-based position in the order-by-order, order-by-id sequence)
     * of the earliest rolled-back sentence, clamped to not move past the
     * current cursor. Mirrors handle()'s offset()/limit() sentence slice.
     *
     * @param  list<int>  $sentenceIds
     */
    private function rollbackOffset(
        string $modelClass,
        string $entityColumn,
        int $entityId,
        array $sentenceIds,
        int $currentOffset,
    ): int {
        if ($sentenceIds === []) {
            return $currentOffset;
        }

        $pivot = $modelClass::query()
            ->where($entityColumn, $entityId)
            ->whereIn('id', array_unique($sentenceIds))
            ->orderBy('order')
            ->orderBy('id')
            ->first();

        if ($pivot === null) {
            return $currentOffset;
        }

        $offset = $modelClass::query()
            ->where($entityColumn, $entityId)
            ->where(fn ($query) => $query
                ->where('order', '<', $pivot->order)
                ->orWhere(fn ($query2) => $query2
                    ->where('order', $pivot->order)
                    ->where('id', '<', $pivot->id)))
            ->count();

        return min($offset, $currentOffset);
    }

    /**
     * Keep the DP from jamming boundary sentences into force-matched garbage.
     * When this is not the final chunk, only matches up to and including the
     * last confident anchor are committed; the uncertain tail is dropped and
     * re-aligned with fresh context by the next invocation. With no anchor at
     * all, everything is committed to guarantee forward progress (mirrors
     * BilingualAligner._trim_to_last_anchor).
     *
     * @param  list<array{en_start: int, en_end: int, ru_start: int, ru_end: int, score: float}>  $matches
     * @return list<array{en_start: int, en_end: int, ru_start: int, ru_end: int, score: float}>
     */
    private function committedMatches(array $matches, bool $isLastChunk): array
    {
        if ($matches === [] || $isLastChunk) {
            return $matches;
        }

        for ($index = count($matches) - 1; $index >= 0; $index--) {
            if ((float) ($matches[$index]['score'] ?? 0.0) >= self::ANCHOR_SCORE_THRESHOLD) {
                return array_slice($matches, 0, $index + 1);
            }
        }

        return $matches;
    }

    /**
     * Monotonic per-run alignment chunk id. Human-edited rows use the -1
     * sentinel, so MAX+1 can never collide with it.
     */
    private function nextAlignmentChunk(int $entityMatchId): int
    {
        $max = EnRuMeaningMatch::query()
            ->where('en_ru_entity_match_id', $entityMatchId)
            ->max('alignment_chunk');

        return $max === null ? 0 : ((int) $max) + 1;
    }

    private function dispatchNext(
        EnRuEntityMatch $entityMatch,
        int $newEnOffset,
        int $newRuOffset,
        int $enTotal,
    ): void {
        $entityMatch->update([
            'last_en_sentence_offset' => $newEnOffset,
            'last_ru_sentence_offset' => $newRuOffset,
            'linked_count' => EnRuMeaningMatch::query()
                ->where('en_ru_entity_match_id', $entityMatch->id)
                ->count(),
        ]);

        if ($newEnOffset >= $enTotal) {
            $entityMatch->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            return;
        }

        self::dispatch($this->entityMatchId);
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
