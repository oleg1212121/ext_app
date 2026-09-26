<?php

namespace App\Jobs;

use App\Classes\SentenceAlignmentService;
use App\Classes\SparseOrderService;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Queue(QueueLane::DEFAULT)]
class AlignEntitySentences implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAX_EFFECTIVE_CHUNK_SIZE = 75;

    private const MAX_EFFECTIVE_SPAN = 8;

    private const ANCHOR_SCORE_THRESHOLD = 0.40;

    private const ROLLBACK_MATCHES = 2;

    public const LANDMARK_THRESHOLD = SentenceAlignmentService::LANDMARK_THRESHOLD;

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
     * "Run from scratch" action, and all the "new alignment" dispatch sites.
     */
    public static function beginFromScratch(int $entityMatchId): void
    {
        $entityMatch = self::loadEntityMatch($entityMatchId);

        if ($entityMatch === null) {
            return;
        }

        $aEntity = $entityMatch->aEntity;
        $bEntity = $entityMatch->bEntity;

        if ($aEntity === null || $bEntity === null) {
            $entityMatch->update([
                'status' => 'failed',
                'error_message' => 'Missing entity for alignment',
                'completed_at' => now(),
            ]);

            return;
        }

        $service = SentenceAlignmentService::create();

        $verification = $service->verifyEntityPair($aEntity, $bEntity);

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

        $aSentenceCount = EntitySentence::query()->where('entity_id', $aEntity->id)->count();
        $bSentenceCount = EntitySentence::query()->where('entity_id', $bEntity->id)->count();

        $chunkSize = min(max((int) $entityMatch->chunk_size, 1), self::MAX_EFFECTIVE_CHUNK_SIZE);
        $maxN = min(max((int) $entityMatch->max_n, 1), self::MAX_EFFECTIVE_SPAN);

        // Small entities fit a single /align call: raise the effective chunk
        // size to cover the whole text so one invocation is the last chunk,
        // skipping the seam rollback/trim machinery entirely.
        if (max($aSentenceCount, $bSentenceCount) <= self::MAX_EFFECTIVE_CHUNK_SIZE) {
            $chunkSize = max($aSentenceCount, $bSentenceCount, 1);
        }

        $entityMatch->meaningMatches()->delete();

        $entityMatch->update([
            'status' => 'aligning',
            'entity_similarity' => $verification['similarity'],
            'a_total_sentences' => $aSentenceCount,
            'b_total_sentences' => $bSentenceCount,
            'linked_count' => 0,
            'chunk_size' => $chunkSize,
            'max_n' => $maxN,
            'a_last_sentence_offset' => 0,
            'b_last_sentence_offset' => 0,
            'error_message' => null,
            'started_at' => now(),
            'completed_at' => null,
        ]);

        if ($aSentenceCount === 0 || $bSentenceCount === 0) {
            (new self($entityMatchId))->finalize($entityMatch);

            return;
        }

        self::dispatch($entityMatchId);
    }

    /**
     * Re-align alignment for an entity match that was already set up, keeping
     * the human-made rows (alignment_chunk -1) and high-confidence landmarks
     * (similarity >= LANDMARK_THRESHOLD) pinned in place. Only machine rows
     * below the landmark bar are deleted; handle() then re-aligns the gaps
     * between landmarks as independent pools. Matches that never went through
     * the fresh setup (no snapshotted totals) delegate to beginFromScratch.
     */
    public static function begin(int $entityMatchId): void
    {
        $entityMatch = EntityMatch::find($entityMatchId);

        if ($entityMatch === null) {
            return;
        }

        if ($entityMatch->a_total_sentences === null) {
            self::beginFromScratch($entityMatchId);

            return;
        }

        MeaningMatch::query()
            ->where('entity_match_id', $entityMatch->id)
            ->where('similarity', '<', self::LANDMARK_THRESHOLD)
            ->delete();

        $entityMatch->update([
            'status' => 'aligning',
            'a_last_sentence_offset' => 0,
            'b_last_sentence_offset' => 0,
            'linked_count' => MeaningMatch::query()
                ->where('entity_match_id', $entityMatch->id)
                ->count(),
            'error_message' => null,
            'started_at' => now(),
            'completed_at' => null,
        ]);

        self::dispatch($entityMatchId);
    }

    public function handle(): void
    {
        $entityMatch = self::loadEntityMatch($this->entityMatchId);

        if ($entityMatch === null) {
            return;
        }

        $aEntity = $entityMatch->aEntity;
        $bEntity = $entityMatch->bEntity;

        if ($aEntity === null || $bEntity === null) {
            $entityMatch->update([
                'status' => 'failed',
                'error_message' => 'Missing entity for alignment',
                'completed_at' => now(),
            ]);

            return;
        }

        $aTotal = (int) $entityMatch->a_total_sentences;
        $bTotal = (int) $entityMatch->b_total_sentences;

        $pools = $this->pools($entityMatch, $aEntity, $bEntity, $aTotal, $bTotal);

        $chunkSize = (int) $entityMatch->chunk_size;
        $aOffset = (int) $entityMatch->a_last_sentence_offset;
        $bOffset = (int) $entityMatch->b_last_sentence_offset;

        $poolIndex = 0;
        $poolCount = count($pools);

        while ($poolIndex < $poolCount) {
            $pool = $pools[$poolIndex];

            $poolAStart = max($pool['a_start'], $aOffset);
            $poolBStart = max($pool['b_start'], $bOffset);

            if ($poolAStart >= $pool['a_end'] && $poolBStart >= $pool['b_end']) {
                $poolIndex++;

                continue;
            }

            $remainingA = $pool['a_end'] - $poolAStart;
            $remainingB = $pool['b_end'] - $poolBStart;

            if ($remainingA <= 0) {
                $poolIndex++;

                continue;
            }

            if ($remainingB <= 0) {
                $this->persistOffsets(
                    $entityMatch,
                    max($aOffset, $pool['a_end']),
                    $bOffset,
                    $aTotal,
                );

                return;
            }

            if ($remainingA <= $chunkSize && $remainingB <= $chunkSize) {
                $hasRollbackCandidates = $this->rollbackCandidates(
                    $entityMatch,
                    $aEntity,
                    $bEntity,
                    $pool,
                )->isNotEmpty();

                if (! $hasRollbackCandidates) {
                    $this->alignWholePool(
                        $entityMatch,
                        $aEntity,
                        $bEntity,
                        $poolAStart,
                        $pool['a_end'],
                        $poolBStart,
                        $pool['b_end'],
                        $aTotal,
                    );

                    return;
                }
            }

            $this->alignPoolChunk(
                $entityMatch,
                $aEntity,
                $bEntity,
                $aTotal,
                $aOffset,
                $bOffset,
                $pool,
            );

            return;
        }

        $this->persistOffsets($entityMatch, $aOffset, $bOffset, $aTotal);
    }

    private static function loadEntityMatch(int $entityMatchId): ?EntityMatch
    {
        return EntityMatch::query()
            ->with(['aEntity.work', 'bEntity.work', 'aEntity.language', 'bEntity.language'])
            ->find($entityMatchId);
    }

    /**
     * Split the sentence span into independent pools delimited by landmark
     * rows. Each pool is an exclusive [start, end) index range on both sides;
     * pools that degenerate to a single point (abutting landmarks) are
     * dropped. With no landmarks the whole span is one pool, which keeps the
     * fresh-alignment path identical to the previous chunking behavior.
     *
     * @return list<array{a_start: int, a_end: int, b_start: int, b_end: int}>
     */
    private function pools(
        EntityMatch $entityMatch,
        Entity $aEntity,
        Entity $bEntity,
        int $aTotal,
        int $bTotal,
    ): array {
        $bounds = self::landmarkBounds(
            self::landmarkRows($entityMatch),
            self::sentenceIndex($aEntity->id),
            self::sentenceIndex($bEntity->id),
        );

        if ($bounds === []) {
            return [[
                'a_start' => 0,
                'a_end' => $aTotal,
                'b_start' => 0,
                'b_end' => $bTotal,
            ]];
        }

        usort(
            $bounds,
            fn (array $a, array $b): int => $a['a_start'] <=> $b['a_start'] ?: $a['b_start'] <=> $b['b_start'],
        );

        $pools = [];
        $aStart = 0;
        $bStart = 0;

        foreach ($bounds as $bound) {
            $pools[] = [
                'a_start' => $aStart,
                'a_end' => $bound['a_start'],
                'b_start' => $bStart,
                'b_end' => $bound['b_start'],
            ];

            $aStart = $bound['a_end'];
            $bStart = $bound['b_end'];
        }

        $pools[] = [
            'a_start' => $aStart,
            'a_end' => $aTotal,
            'b_start' => $bStart,
            'b_end' => $bTotal,
        ];

        return array_values(array_filter(
            $pools,
            fn (array $pool): bool => $pool['a_start'] < $pool['a_end'] && $pool['b_start'] < $pool['b_end'],
        ));
    }

    /**
     * Hard pins for the pool partitioner: human-edited rows (alignment_chunk
     * -1) and auto-landmarks at or above the confidence bar. Ordered by order
     * so the partitioner walks them in document sequence, with the sentence
     * junctions eager-loaded.
     *
     * @return Collection<int, MeaningMatch>
     */
    private static function landmarkRows(EntityMatch $entityMatch): Collection
    {
        return MeaningMatch::query()
            ->where('entity_match_id', $entityMatch->id)
            ->where(fn ($query) => $query
                ->where('alignment_chunk', -1)
                ->orWhere('similarity', '>=', self::LANDMARK_THRESHOLD))
            ->orderBy('order')
            ->orderBy('id')
            ->with('sentenceMeaningMatches')
            ->get();
    }

    /**
     * The inclusive sentence span each landmark pins on both sides, expressed
     * as absolute [start, end) index ranges. Landmarks without junction rows
     * on either side contribute no boundary.
     *
     * @param  Collection<int, MeaningMatch>  $landmarks
     * @param  array<int, int>  $aIndex
     * @param  array<int, int>  $bIndex
     * @return list<array{a_start: int, a_end: int, b_start: int, b_end: int}>
     */
    private static function landmarkBounds(Collection $landmarks, array $aIndex, array $bIndex): array
    {
        $bounds = [];

        foreach ($landmarks as $landmark) {
            $aPositions = $landmark->sentenceMeaningMatches
                ->where('side', 'a')
                ->pluck('entity_sentence_id')
                ->map(fn (int $id): int => $aIndex[$id] ?? -1)
                ->filter(fn (int $position): bool => $position >= 0)
                ->values()
                ->all();

            $bPositions = $landmark->sentenceMeaningMatches
                ->where('side', 'b')
                ->pluck('entity_sentence_id')
                ->map(fn (int $id): int => $bIndex[$id] ?? -1)
                ->filter(fn (int $position): bool => $position >= 0)
                ->values()
                ->all();

            if ($aPositions === [] || $bPositions === []) {
                continue;
            }

            $bounds[] = [
                'a_start' => min($aPositions),
                'a_end' => max($aPositions) + 1,
                'b_start' => min($bPositions),
                'b_end' => max($bPositions) + 1,
            ];
        }

        return $bounds;
    }

    /**
     * Absolute position (0-based) of each sentence in the order-by-order,
     * order-by-id sequence, keyed by sentence id. Mirrors handle()'s
     * offset()/limit() sentence slice.
     *
     * @return array<int, int>
     */
    private static function sentenceIndex(int $entityId): array
    {
        return array_flip(EntitySentence::query()
            ->where('entity_id', $entityId)
            ->orderBy('order')
            ->orderBy('id')
            ->pluck('id')
            ->values()
            ->all());
    }

    /**
     * The sides whose sentences must stay covered when a chunk produces no
     * committed match: the work's original side; both sides when neither
     * entity is the original (two translations of a third-language original).
     *
     * @return list<'a'|'b'>
     */
    private static function skipSides(EntityMatch $entityMatch): array
    {
        $originalSide = $entityMatch->originalSide();

        return $originalSide !== null ? [$originalSide] : ['a', 'b'];
    }

    /**
     * Align a pool small enough to fit a single /align call. The pool edges
     * are the landmarks themselves, so no seam rollback/trim is needed:
     * everything the python service returns is committed and trailing skips
     * are stored up to the pool boundary. Rows and the advanced cursors are
     * committed atomically (see persistOffsets). With no committed match the
     * original-side sentences (both sides for translation↔translation pairs)
     * are stored as skips and the a cursor advances by one so the alignment
     * never stalls.
     */
    private function alignWholePool(
        EntityMatch $entityMatch,
        Entity $aEntity,
        Entity $bEntity,
        int $aStart,
        int $aEnd,
        int $bStart,
        int $bEnd,
        int $aTotal,
    ): void {
        $aSentences = $this->sentenceSlice($aEntity->id, $aStart, $aEnd - $aStart);
        $bSentences = $this->sentenceSlice($bEntity->id, $bStart, $bEnd - $bStart);

        if ($aSentences->isEmpty() || $bSentences->isEmpty()) {
            $this->persistOffsets(
                $entityMatch,
                $aStart + 1,
                $bStart,
                $aTotal,
                function () use ($entityMatch, $aSentences): void {
                    if ($aSentences->isNotEmpty()) {
                        $this->storePoolSkips($entityMatch, ['a' => $aSentences->take(1)]);
                    }
                },
            );

            return;
        }

        $service = SentenceAlignmentService::create();

        $matches = $service->alignChunkRemote(
            $aSentences,
            $bSentences,
            $entityMatch->max_n,
        )['matches'];

        $committed = $this->committedMatches($matches, true);

        if ($committed === []) {
            $this->persistOffsets(
                $entityMatch,
                $aStart + 1,
                $bStart,
                $aTotal,
                // Only the a head is parked: the b cursor stays, so the b
                // head is re-fed and matched by later windows — a skip row
                // here would duplicate its junction when that happens.
                function () use ($entityMatch, $aSentences): void {
                    $this->storePoolSkips($entityMatch, [
                        'a' => $aSentences->take(1),
                    ]);
                },
            );

            return;
        }

        $alignmentChunk = $this->nextAlignmentChunk($entityMatch->id);

        $this->persistOffsets(
            $entityMatch,
            $aEnd,
            $bEnd,
            $aTotal,
            function () use ($service, $entityMatch, $alignmentChunk, $committed, $aSentences, $bSentences): void {
                $service->storeAlignmentSegmentFromMatches(
                    entityMatch: $entityMatch,
                    alignmentChunk: $alignmentChunk,
                    committedMatches: $committed,
                    aSentences: $aSentences,
                    bSentences: $bSentences,
                    isLastChunk: true,
                );
            },
        );
    }

    /**
     * Store single-sentence skip rows for the original side(s) of the pool:
     * the work's original side, or both sides for translation↔translation
     * pairs. Sentences already covered by an earlier pool are excluded. Only
     * pass heads for sides whose cursor advances past the sentence — a parked
     * side re-feeds its head later, and a skip row here would duplicate it.
     *
     * @param  array<'a'|'b', Collection<int, EntitySentence>>  $windowHeads
     */
    private function storePoolSkips(EntityMatch $entityMatch, array $windowHeads): void
    {
        $chunk = $this->nextAlignmentChunk($entityMatch->id);
        $service = SentenceAlignmentService::create();

        foreach (self::skipSides($entityMatch) as $side) {
            $sentences = $windowHeads[$side] ?? null;

            if ($sentences !== null && $sentences->isNotEmpty()) {
                $service->storeSkipSentences($entityMatch, $chunk, $side, $sentences);
            }
        }
    }

    private function sentenceSlice(int $entityId, int $offset, int $limit): Collection
    {
        return EntitySentence::query()
            ->where('entity_id', $entityId)
            ->orderBy('order')
            ->orderBy('id')
            ->offset($offset)
            ->limit($limit)
            ->get();
    }

    /**
     * Align a single chunk inside a large pool and resume in a fresh job. This
     * is the original seam-rollback machinery scoped to the pool's index
     * range: the rollback only ever drags machine rows back within the pool,
     * never across a landmark boundary.
     */
    private function alignPoolChunk(
        EntityMatch $entityMatch,
        Entity $aEntity,
        Entity $bEntity,
        int $aTotal,
        int $aOffset,
        int $bOffset,
        array $pool,
    ): void {
        $chunkSize = (int) $entityMatch->chunk_size;

        $storedAOffset = $aOffset;
        $storedBOffset = $bOffset;

        $aLimit = min($chunkSize, max(0, $pool['a_end'] - $aOffset));
        $bLimit = min($chunkSize, max(0, $pool['b_end'] - $bOffset));

        if ($aLimit <= 0 || $bLimit <= 0) {
            $this->persistOffsets(
                $entityMatch,
                max($aOffset, $pool['a_end']),
                max($bOffset, $pool['b_end']),
                $aTotal,
            );

            return;
        }

        $rollback = $this->rollbackPriorMatches(
            entityMatch: $entityMatch,
            aEntity: $aEntity,
            bEntity: $bEntity,
            aOffset: $aOffset,
            bOffset: $bOffset,
            aLimit: $aLimit,
            bLimit: $bLimit,
            pool: $pool,
        );

        $aOffset = $rollback['a_offset'];
        $bOffset = $rollback['b_offset'];
        $aLimit = $rollback['a_limit'];
        $bLimit = $rollback['b_limit'];

        $aSentences = $this->sentenceSlice($aEntity->id, $aOffset, $aLimit);
        $bSentences = $this->sentenceSlice($bEntity->id, $bOffset, $bLimit);

        $service = SentenceAlignmentService::create();

        $matches = $service->alignChunkRemote(
            $aSentences,
            $bSentences,
            $entityMatch->max_n,
        )['matches'];

        $isLastChunk = $aOffset + $aLimit >= $pool['a_end']
            && $bOffset + $bLimit >= $pool['b_end'];

        $committed = $this->committedMatches($matches, $isLastChunk);

        $lastCommitted = $committed[array_key_last($committed)] ?? null;

        if ($lastCommitted === null) {
            $skipA = max($aOffset, $storedAOffset);
            $skipB = max($bOffset, $storedBOffset);

            $this->persistOffsets(
                $entityMatch,
                $skipA + min(1, $aLimit),
                $storedBOffset,
                $aTotal,
                // Only sides whose cursor advances past the sentence get a
                // skip row; a parked side re-feeds its head into later
                // windows, and a premature skip row would duplicate the
                // junction when one of those windows matches it.
                function () use ($entityMatch, $skipA, $skipB, $aLimit): void {
                    $this->storeChunkSkips(
                        $entityMatch,
                        $skipA,
                        $skipB,
                        $aLimit > 0 ? ['a'] : [],
                    );
                },
            );

            return;
        }

        $alignmentChunk = $this->nextAlignmentChunk($entityMatch->id);

        // The last chunk stores trailing skips up to the window end, so the
        // cursors must advance past everything stored — stopping at the last
        // committed match would re-feed sentences that already carry
        // junctions and duplicate them.
        if ($isLastChunk) {
            $newAOffset = $aOffset + $aSentences->count();
            $newBOffset = $bOffset + $bSentences->count();
        } else {
            $newAOffset = $aOffset + (int) $lastCommitted['a_end'];
            $newBOffset = $bOffset + (int) $lastCommitted['b_end'];
        }

        if ($newAOffset <= $storedAOffset) {
            $newAOffset = $storedAOffset + min(1, $aLimit);
            $newBOffset = $storedBOffset;
        }

        $this->persistOffsets(
            $entityMatch,
            $newAOffset,
            $newBOffset,
            $aTotal,
            function () use ($service, $entityMatch, $alignmentChunk, $committed, $aSentences, $bSentences, $isLastChunk): void {
                $service->storeAlignmentSegmentFromMatches(
                    entityMatch: $entityMatch,
                    alignmentChunk: $alignmentChunk,
                    committedMatches: $committed,
                    aSentences: $aSentences,
                    bSentences: $bSentences,
                    isLastChunk: $isLastChunk,
                );
            },
        );
    }

    /**
     * Skip rows for the alignPoolChunk no-progress path: one sentence per
     * skip side, taken at that side's window head. Only sides listed in
     * $sides are stored — a side whose cursor stays parked re-feeds its head
     * into later windows, and junctioning it now would duplicate it.
     *
     * @param  list<'a'|'b'>  $sides
     */
    private function storeChunkSkips(EntityMatch $entityMatch, int $aOffset, int $bOffset, array $sides): void
    {
        $chunk = $this->nextAlignmentChunk($entityMatch->id);
        $service = SentenceAlignmentService::create();

        foreach (self::skipSides($entityMatch) as $side) {
            if (! in_array($side, $sides, true)) {
                continue;
            }

            $entityId = $side === 'a' ? $entityMatch->a_entity_id : $entityMatch->b_entity_id;
            $offset = $side === 'a' ? $aOffset : $bOffset;

            $sentence = EntitySentence::query()
                ->where('entity_id', $entityId)
                ->orderBy('order')
                ->orderBy('id')
                ->offset($offset)
                ->limit(1)
                ->get();

            $service->storeSkipSentences($entityMatch, $chunk, $side, $sentence);
        }
    }

    /**
     * Machine rows inside the pool that seam-rollback may drag back into the
     * window: not human-edited, below the landmark bar, and junctioned on
     * both sides. Landmark rows are excluded twice over (chunk sentinel and
     * similarity), so the rollback can never pull a pin into a window.
     *
     * @param  array{a_start: int, a_end: int, b_start: int, b_end: int}  $pool
     * @return Collection<int, MeaningMatch>
     */
    private function rollbackCandidates(
        EntityMatch $entityMatch,
        Entity $aEntity,
        Entity $bEntity,
        array $pool,
    ): Collection {
        $poolAIds = $this->sentenceSlice($aEntity->id, $pool['a_start'], $pool['a_end'] - $pool['a_start'])
            ->pluck('id')
            ->all();

        $poolBIds = $this->sentenceSlice($bEntity->id, $pool['b_start'], $pool['b_end'] - $pool['b_start'])
            ->pluck('id')
            ->all();

        return MeaningMatch::query()
            ->where('entity_match_id', $entityMatch->id)
            ->where('alignment_chunk', '!=', -1)
            ->where('similarity', '<', self::LANDMARK_THRESHOLD)
            ->whereHas('sentenceMeaningMatches', fn ($query) => $query
                ->where('side', 'a')
                ->whereIn('entity_sentence_id', $poolAIds))
            ->whereHas('sentenceMeaningMatches', fn ($query) => $query
                ->where('side', 'b')
                ->whereIn('entity_sentence_id', $poolBIds))
            ->orderByDesc('order')
            ->limit(self::ROLLBACK_MATCHES)
            ->get();
    }

    /**
     * Drag the last committed matches back into the window before aligning so
     * the DP sees the context preceding the chunk seam. The strict 1:1
     * window of ADR-0004 means python's DP force-aligns the head of each new
     * chunk with no backward reach, producing the 1:5 / 5:1 garbage trim can
     * only catch on the tail. Rolling back the last few commits (deleting the
     * meaning-match rows, junctions cascade via FK) and re-aligning them
     * against fresh forward context lets the seam dissolve into a clean
     * 1:1 progression. Skip steps anchor no sentences and are not candidates.
     * The rewind is clamped to the pool start so landmarks are never pulled
     * into a window. The limits grow by the rolled-back spans so the window's
     * forward reach is unchanged.
     *
     * @param  array{a_start: int, a_end: int, b_start: int, b_end: int}  $pool
     * @return array{a_offset: int, b_offset: int, a_limit: int, b_limit: int}
     */
    private function rollbackPriorMatches(
        EntityMatch $entityMatch,
        Entity $aEntity,
        Entity $bEntity,
        int $aOffset,
        int $bOffset,
        int $aLimit,
        int $bLimit,
        array $pool,
    ): array {
        $candidates = $this->rollbackCandidates($entityMatch, $aEntity, $bEntity, $pool);

        if ($candidates->isEmpty()) {
            return [
                'a_offset' => $aOffset,
                'b_offset' => $bOffset,
                'a_limit' => $aLimit,
                'b_limit' => $bLimit,
            ];
        }

        $aSentenceIds = [];
        $bSentenceIds = [];

        foreach ($candidates as $candidate) {
            $aSentenceIds = [
                ...$aSentenceIds,
                ...$candidate->sentenceMeaningMatches()->where('side', 'a')->pluck('entity_sentence_id')->all(),
            ];
            $bSentenceIds = [
                ...$bSentenceIds,
                ...$candidate->sentenceMeaningMatches()->where('side', 'b')->pluck('entity_sentence_id')->all(),
            ];
        }

        $newAOffset = max(
            $this->rollbackOffset($aEntity->id, $aSentenceIds, $aOffset),
            $pool['a_start'],
        );
        $newBOffset = max(
            $this->rollbackOffset($bEntity->id, $bSentenceIds, $bOffset),
            $pool['b_start'],
        );

        MeaningMatch::whereKey($candidates->pluck('id'))->delete();

        $aRollback = max(0, $aOffset - $newAOffset);
        $bRollback = max(0, $bOffset - $newBOffset);

        return [
            'a_offset' => $newAOffset,
            'b_offset' => $newBOffset,
            'a_limit' => min($aLimit + $aRollback, max(0, $pool['a_end'] - $newAOffset)),
            'b_limit' => min($bLimit + $bRollback, max(0, $pool['b_end'] - $newBOffset)),
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
        int $entityId,
        array $sentenceIds,
        int $currentOffset,
    ): int {
        if ($sentenceIds === []) {
            return $currentOffset;
        }

        $pivot = EntitySentence::query()
            ->where('entity_id', $entityId)
            ->whereIn('id', array_unique($sentenceIds))
            ->orderBy('order')
            ->orderBy('id')
            ->first();

        if ($pivot === null) {
            return $currentOffset;
        }

        $offset = EntitySentence::query()
            ->where('entity_id', $entityId)
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
     * @param  list<array{a_start: int, a_end: int, b_start: int, b_end: int, score: float}>  $matches
     * @return list<array{a_start: int, a_end: int, b_start: int, b_end: int, score: float}>
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
        $max = MeaningMatch::query()
            ->where('entity_match_id', $entityMatchId)
            ->max('alignment_chunk');

        return $max === null ? 0 : ((int) $max) + 1;
    }

    /**
     * Commit the chunk's rows and the advanced cursors atomically, then hand
     * control back to the queue. Persisting rows and advancing the cursors in
     * separate transactions let a crash between the two re-align the same
     * window under a fresh chunk id while the old rows survived — duplicating
     * junctions no resequence can undo. $writes runs inside that transaction
     * before the cursor update; finalize/dispatch happen only after the commit.
     */
    private function persistOffsets(
        EntityMatch $entityMatch,
        int $newAOffset,
        int $newBOffset,
        int $aTotal,
        ?Closure $writes = null,
    ): void {
        DB::transaction(function () use ($entityMatch, $newAOffset, $newBOffset, $writes): void {
            if ($writes !== null) {
                ($writes)();
            }

            $entityMatch->update([
                'a_last_sentence_offset' => $newAOffset,
                'b_last_sentence_offset' => $newBOffset,
                'linked_count' => MeaningMatch::query()
                    ->where('entity_match_id', $entityMatch->id)
                    ->count(),
            ]);
        });

        if ($newAOffset >= $aTotal) {
            $this->finalize($entityMatch);

            return;
        }

        self::dispatch($this->entityMatchId);
    }

    /**
     * The single completion gate for an entity match. Every completion site
     * funnels through here so the coverage invariant holds on exit: any
     * original-side sentence still junction-less (dropped by a crawl seam,
     * left over after the translation side was exhausted, or skipped during a
     * re-align) is junctioned into a single-sided meaning match. When neither
     * side is the original language (translation↔translation pair), BOTH
     * sides are repaired. The repair is best-effort — if it fails, a warning
     * is logged and completion proceeds regardless.
     */
    private function finalize(EntityMatch $entityMatch): void
    {
        $sides = self::skipSides($entityMatch);

        foreach ($sides as $side) {
            [$junctionless, $index] = $this->junctionlessSentences($entityMatch, $side);

            if ($junctionless->isNotEmpty()) {
                try {
                    $this->repairJunctionlessOriginals($entityMatch, $side, $junctionless, $index);
                } catch (Throwable $exception) {
                    Log::warning('Failed to junction sentences on alignment completion', [
                        'entity_match_id' => $entityMatch->id,
                        'side' => $side,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        try {
            $resequenced = SentenceAlignmentService::create()
                ->resequenceMatchesByDocumentPosition($entityMatch);

            if ($resequenced > 0) {
                Log::info('Resequenced meaning matches by document position on completion', [
                    'entity_match_id' => $entityMatch->id,
                    'rows' => $resequenced,
                ]);
            }
        } catch (Throwable $exception) {
            Log::warning('Failed to resequence meaning matches on alignment completion', [
                'entity_match_id' => $entityMatch->id,
                'error' => $exception->getMessage(),
            ]);
        }

        $entityMatch->update([
            'status' => 'completed',
            'error_message' => null,
            'completed_at' => now(),
            'linked_count' => MeaningMatch::query()
                ->where('entity_match_id', $entityMatch->id)
                ->count(),
        ]);
    }

    /**
     * The junction-less sentences of one side in document order, plus the
     * sentence-id => document-index map that anchors their position among the
     * meaning matches.
     *
     * @param  'a'|'b'  $side
     * @return array{0: Collection<int, EntitySentence>, 1: array<int, int>}
     */
    private function junctionlessSentences(EntityMatch $entityMatch, string $side): array
    {
        $entityId = $side === 'a' ? $entityMatch->a_entity_id : $entityMatch->b_entity_id;

        return [
            EntitySentence::query()
                ->where('entity_id', $entityId)
                ->orderBy('order')
                ->orderBy('id')
                ->doesntHave('meaningJunctions')
                ->get(),
            self::sentenceIndex($entityId),
        ];
    }

    /**
     * Junction every junction-less sentence of one side into a single-sided
     * meaning match (similarity 0.0, next machine alignment chunk id) ordered
     * so the reader's meaning-match sequence preserves document order. Runs
     * of junction-less sentences falling between the same pair of junctioned
     * anchors share the machine chunk id, so a future re-align deletes and
     * re-feeds them like any other machine row.
     *
     * @param  'a'|'b'  $side
     * @param  Collection<int, EntitySentence>  $junctionless
     * @param  array<int, int>  $index
     */
    private function repairJunctionlessOriginals(
        EntityMatch $entityMatch,
        string $side,
        Collection $junctionless,
        array $index,
    ): void {
        $anchors = [];

        MeaningMatch::query()
            ->where('entity_match_id', $entityMatch->id)
            ->orderBy('order')
            ->orderBy('id')
            ->with(['sentenceMeaningMatches' => fn ($query) => $query->where('side', $side)])
            ->get()
            ->each(function (MeaningMatch $match) use (&$anchors, $index): void {
                foreach ($match->sentenceMeaningMatches as $junction) {
                    $docIndex = $index[$junction->entity_sentence_id] ?? null;

                    if ($docIndex !== null) {
                        $anchors[$docIndex] = (int) $match->order;
                    }
                }
            });

        ksort($anchors);

        $runs = [];
        $run = [];
        $previousIndex = null;

        foreach ($junctionless as $sentence) {
            $docIndex = $index[$sentence->id] ?? null;

            if ($docIndex === null) {
                continue;
            }

            if ($run !== [] && $previousIndex !== null && $docIndex === $previousIndex + 1) {
                $run[] = $sentence;
            } else {
                if ($run !== []) {
                    $runs[] = $run;
                }

                $run = [$sentence];
            }

            $previousIndex = $docIndex;
        }

        if ($run !== []) {
            $runs[] = $run;
        }

        if ($runs === []) {
            return;
        }

        $sparseOrder = app(SparseOrderService::class);
        $alignmentChunk = $this->nextAlignmentChunk($entityMatch->id);
        $anchorIndexes = array_keys($anchors);

        DB::transaction(function () use (
            $entityMatch,
            $side,
            $sparseOrder,
            $alignmentChunk,
            $anchorIndexes,
            $anchors,
            $index,
            $runs,
        ): void {
            foreach ($runs as $run) {
                $firstIndex = $index[$run[0]->id];
                $lastIndex = $index[$run[array_key_last($run)]->id];

                $low = null;
                $high = null;

                foreach ($anchorIndexes as $anchorIndex) {
                    if ($anchorIndex < $firstIndex) {
                        $low = $anchors[$anchorIndex];
                    }

                    if ($anchorIndex > $lastIndex && $high === null) {
                        $high = $anchors[$anchorIndex];
                    }
                }

                $orders = $sparseOrder->spreadOrders(count($run), $low, $high);

                foreach ($orders as $offset => $order) {
                    $meaningMatch = MeaningMatch::create([
                        'entity_match_id' => $entityMatch->id,
                        'order' => $order,
                        'similarity' => 0.0,
                        'alignment_chunk' => $alignmentChunk,
                    ]);

                    SentenceMeaningMatch::create([
                        'entity_sentence_id' => $run[$offset]->id,
                        'meaning_match_id' => $meaningMatch->id,
                        'side' => $side,
                    ]);
                }
            }
        });
    }

    public function failed(Throwable $exception): void
    {
        EntityMatch::whereKey($this->entityMatchId)->update([
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
            'completed_at' => now(),
        ]);
    }
}
