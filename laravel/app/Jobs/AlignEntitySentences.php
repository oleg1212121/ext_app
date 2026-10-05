<?php

namespace App\Jobs;

use App\Classes\MeaningMatchStore;
use App\Classes\SentenceAlignmentService;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
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
     * the first chunk job. Shared by the Filament "Run from scratch" action
     * and all the "new alignment" dispatch sites; the 5-minute command and
     * the "Re-align" action reach it through begin() when there is nothing
     * to preserve.
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

        $service = app(SentenceAlignmentService::class);

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

        // The aligner works in image-less sentence space (ADR 0050):
        // illustrations never enter a chunk window, a count, or a cursor, so
        // the snapshotted totals are the alignable sentence counts the
        // offsets advance against. Illustration-only entities finalize
        // immediately — finalize()'s completeness repair backfills them
        // single-sided.
        [
            'a_count' => $aSentenceCount,
            'b_count' => $bSentenceCount,
            'chunk_size' => $chunkSize,
            'max_n' => $maxN,
        ] = self::snapshotAlignmentPlan($entityMatch);

        $entityMatch->meaningMatches()->delete();

        // linked_count is a reset, not a resync — the rows were just deleted,
        // so zero is provably the count.
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
     * Re-align an entity match that already has meaning-match rows, keeping
     * the human-made rows (the MeaningMatch::HUMAN_CHUNK sentinel) and
     * high-confidence landmarks
     * (similarity >= LANDMARK_THRESHOLD) pinned in place. Only machine rows
     * below the landmark bar are deleted; the snapshot is refreshed so
     * sentences added or removed since the original run are inside the
     * re-aligned span; handle() then re-aligns the gaps between landmarks as
     * independent pools. Matches with no rows never went through a run —
     * there is nothing to preserve — and delegate to beginFromScratch with
     * its verify pass. a_total_sentences cannot serve as the "never set up"
     * marker: the column is NOT NULL DEFAULT 0.
     */
    public static function begin(int $entityMatchId): void
    {
        $entityMatch = EntityMatch::find($entityMatchId);

        if ($entityMatch === null) {
            return;
        }

        if (! $entityMatch->meaningMatches()->exists()) {
            self::beginFromScratch($entityMatchId);

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

        MeaningMatch::query()
            ->where('entity_match_id', $entityMatch->id)
            ->where('similarity', '<', MeaningMatch::LANDMARK_THRESHOLD)
            ->delete();

        [
            'a_count' => $aSentenceCount,
            'b_count' => $bSentenceCount,
            'chunk_size' => $chunkSize,
            'max_n' => $maxN,
        ] = self::snapshotAlignmentPlan($entityMatch);

        $entityMatch->update([
            'status' => 'aligning',
            'a_total_sentences' => $aSentenceCount,
            'b_total_sentences' => $bSentenceCount,
            'chunk_size' => $chunkSize,
            'max_n' => $maxN,
            'a_last_sentence_offset' => 0,
            'b_last_sentence_offset' => 0,
            'error_message' => null,
            'started_at' => now(),
            'completed_at' => null,
        ]);

        $entityMatch->syncLinkedCount();

        if ($aSentenceCount === 0 || $bSentenceCount === 0) {
            (new self($entityMatchId))->finalize($entityMatch);

            return;
        }

        self::dispatch($entityMatchId);
    }

    /**
     * Snapshot the alignment plan for a run: image-less sentence totals per
     * side (ADR 0050 — illustrations never enter a chunk window, a count, or
     * a cursor), chunk_size/max_n clamped to their effective maxima, and the
     * small-entity single-chunk raise.
     *
     * @return array{a_count: int, b_count: int, chunk_size: int, max_n: int}
     */
    private static function snapshotAlignmentPlan(EntityMatch $entityMatch): array
    {
        $totals = $entityMatch->recountTotals();
        $aSentenceCount = $totals['a'];
        $bSentenceCount = $totals['b'];

        $chunkSize = min(max((int) $entityMatch->chunk_size, 1), self::MAX_EFFECTIVE_CHUNK_SIZE);
        $maxN = min(max((int) $entityMatch->max_n, 1), self::MAX_EFFECTIVE_SPAN);

        // Small entities fit a single /align call: raise the effective chunk
        // size to cover the whole text so one invocation is the last chunk,
        // skipping the seam rollback/trim machinery entirely.
        if (max($aSentenceCount, $bSentenceCount) <= self::MAX_EFFECTIVE_CHUNK_SIZE) {
            $chunkSize = max($aSentenceCount, $bSentenceCount, 1);
        }

        return [
            'a_count' => $aSentenceCount,
            'b_count' => $bSentenceCount,
            'chunk_size' => $chunkSize,
            'max_n' => $maxN,
        ];
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
                ->where('alignment_chunk', MeaningMatch::HUMAN_CHUNK)
                ->orWhere('similarity', '>=', MeaningMatch::LANDMARK_THRESHOLD))
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
            ->withoutImage()
            ->orderBy('order')
            ->orderBy('id')
            ->pluck('id')
            ->values()
            ->all());
    }

    /**
     * The sides whose sentences must stay covered when a chunk produces no
     * committed match, and the sides finalize() repairs at completion: BOTH
     * sides (total completeness, ADR 0048) — every sentence ends junctioned
     * into a meaning match, so nothing is invisible to the reader/simulator.
     * Mid-run, only sides whose cursor actually advances get skip rows (a
     * parked side re-feeds its head into later windows, and a premature skip
     * row would duplicate its junction).
     *
     * @return list<'a'|'b'>
     */
    private static function skipSides(EntityMatch $entityMatch): array
    {
        return ['a', 'b'];
    }

    /**
     * Align a pool small enough to fit a single /align call. The pool edges
     * are the landmarks themselves, so no seam rollback/trim is needed:
     * everything the python service returns is committed and trailing skips
     * are stored up to the pool boundary. Rows and the advanced cursors are
     * committed atomically (see persistOffsets). With no committed match the
     * advancing side's head sentence is stored as a skip and the a cursor
     * advances by one so the alignment never stalls; the parked side is
     * repaired at finalize (total completeness, ADR 0048).
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

        $service = app(SentenceAlignmentService::class);

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

        $alignmentChunk = $entityMatch->nextAlignmentChunk();

        $store = MeaningMatchStore::create();

        $this->persistOffsets(
            $entityMatch,
            $aEnd,
            $bEnd,
            $aTotal,
            function () use ($store, $entityMatch, $alignmentChunk, $committed, $aSentences, $bSentences): void {
                $store->storeAlignmentSegmentFromMatches(
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
     * Store single-sentence skip rows for the pool's advancing side(s).
     * Sentences already covered by an earlier pool are excluded. Only pass
     * heads for sides whose cursor advances past the sentence — a parked
     * side re-feeds its head later, and a skip row here would duplicate it.
     *
     * @param  array<'a'|'b', Collection<int, EntitySentence>>  $windowHeads
     */
    private function storePoolSkips(EntityMatch $entityMatch, array $windowHeads): void
    {
        $chunk = $entityMatch->nextAlignmentChunk();
        $store = MeaningMatchStore::create();

        foreach (self::skipSides($entityMatch) as $side) {
            $sentences = $windowHeads[$side] ?? null;

            if ($sentences !== null && $sentences->isNotEmpty()) {
                $store->storeSkipSentences($entityMatch, $chunk, $side, $sentences);
            }
        }
    }

    private function sentenceSlice(int $entityId, int $offset, int $limit): Collection
    {
        return EntitySentence::query()
            ->where('entity_id', $entityId)
            ->withoutImage()
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

        $service = app(SentenceAlignmentService::class);

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

        $alignmentChunk = $entityMatch->nextAlignmentChunk();

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

        $store = MeaningMatchStore::create();

        $this->persistOffsets(
            $entityMatch,
            $newAOffset,
            $newBOffset,
            $aTotal,
            function () use ($store, $entityMatch, $alignmentChunk, $committed, $aSentences, $bSentences, $isLastChunk): void {
                $store->storeAlignmentSegmentFromMatches(
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
        $chunk = $entityMatch->nextAlignmentChunk();
        $store = MeaningMatchStore::create();

        foreach (self::skipSides($entityMatch) as $side) {
            if (! in_array($side, $sides, true)) {
                continue;
            }

            $entityId = $side === 'a' ? $entityMatch->a_entity_id : $entityMatch->b_entity_id;
            $offset = $side === 'a' ? $aOffset : $bOffset;

            $sentence = EntitySentence::query()
                ->where('entity_id', $entityId)
                ->withoutImage()
                ->orderBy('order')
                ->orderBy('id')
                ->offset($offset)
                ->limit(1)
                ->get();

            $store->storeSkipSentences($entityMatch, $chunk, $side, $sentence);
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
            ->where('alignment_chunk', '!=', MeaningMatch::HUMAN_CHUNK)
            ->where('similarity', '<', MeaningMatch::LANDMARK_THRESHOLD)
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
            ->withoutImage()
            ->whereIn('id', array_unique($sentenceIds))
            ->orderBy('order')
            ->orderBy('id')
            ->first();

        if ($pivot === null) {
            return $currentOffset;
        }

        // The offset is computed in the same image-less space the cursors
        // advance in — counting illustrations here would overshoot them.
        $offset = EntitySentence::query()
            ->where('entity_id', $entityId)
            ->withoutImage()
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
            ]);

            $entityMatch->syncLinkedCount();
        });

        if ($newAOffset >= $aTotal) {
            $this->finalize($entityMatch);

            return;
        }

        self::dispatch($this->entityMatchId);
    }

    /**
     * The single completion gate for an entity match. Every completion site
     * funnels through here so the coverage invariant holds on exit: ANY
     * sentence of EITHER side still junction-less (dropped by a crawl seam,
     * left over after the other side was exhausted, or skipped during a
     * re-align) is junctioned into a single-sided meaning match — total
     * completeness, so the reader never hides a sentence. The repair is
     * whole-or-nothing (one transaction in repairCoverage) and best-effort —
     * if it fails, a warning is logged and completion proceeds regardless.
     */
    private function finalize(EntityMatch $entityMatch): void
    {
        $store = MeaningMatchStore::create();

        try {
            [, $resequenced] = $store->repairCoverage($entityMatch);

            if ($resequenced > 0) {
                Log::info('Resequenced meaning matches by document position on completion', [
                    'entity_match_id' => $entityMatch->id,
                    'rows' => $resequenced,
                ]);
            }
        } catch (Throwable $exception) {
            Log::warning('Failed to repair coverage on alignment completion', [
                'entity_match_id' => $entityMatch->id,
                'error' => $exception->getMessage(),
            ]);
        }

        // The repairs above ran regardless; completion itself only applies to
        // a still-running chain. If a sentence edit flipped the match to
        // stale mid-run, that signal survives — the user decides via
        // Re-align (ADR 0055).
        $entityMatch->refresh();

        $entityMatch->syncLinkedCount();

        if ($entityMatch->status === 'aligning') {
            $entityMatch->update([
                'status' => 'completed',
                'error_message' => null,
                'completed_at' => now(),
            ]);
        }
    }

    public function failed(Throwable $exception): void
    {
        $entityMatch = EntityMatch::find($this->entityMatchId);

        if ($entityMatch === null) {
            return;
        }

        // Same rule as finalize(): only a still-running chain can fail the
        // match; a stale flag flipped in mid-run survives the chain dying.
        if ($entityMatch->status !== 'aligning') {
            Log::warning('Alignment chain failed but the match was no longer aligning; status left untouched', [
                'entity_match_id' => $this->entityMatchId,
                'status' => $entityMatch->status,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        $entityMatch->update([
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
            'completed_at' => now(),
        ]);
    }
}
