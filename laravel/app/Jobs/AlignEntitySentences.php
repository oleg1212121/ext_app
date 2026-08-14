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
use Illuminate\Support\Collection;

class AlignEntitySentences implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAX_EFFECTIVE_CHUNK_SIZE = 75;

    private const MAX_EFFECTIVE_SPAN = 8;

    private const ANCHOR_SCORE_THRESHOLD = 0.40;

    private const ROLLBACK_MATCHES = 2;

    public const LANDMARK_THRESHOLD = 0.90;

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

        // Small entities fit a single /align call: raise the effective chunk
        // size to cover the whole text so one invocation is the last chunk,
        // skipping the seam rollback/trim machinery entirely.
        if (max($enSentenceCount, $ruSentenceCount) <= self::MAX_EFFECTIVE_CHUNK_SIZE) {
            $chunkSize = max($enSentenceCount, $ruSentenceCount, 1);
        }

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
        $entityMatch = EnRuEntityMatch::find($entityMatchId);

        if ($entityMatch === null) {
            return;
        }

        if ($entityMatch->en_total_sentences === null) {
            self::beginFromScratch($entityMatchId);

            return;
        }

        EnRuMeaningMatch::query()
            ->where('en_ru_entity_match_id', $entityMatch->id)
            ->where('similarity', '<', self::LANDMARK_THRESHOLD)
            ->delete();

        $entityMatch->update([
            'status' => 'aligning',
            'last_en_sentence_offset' => 0,
            'last_ru_sentence_offset' => 0,
            'linked_count' => EnRuMeaningMatch::query()
                ->where('en_ru_entity_match_id', $entityMatch->id)
                ->count(),
            'error_message' => null,
            'started_at' => now(),
            'completed_at' => null,
        ]);

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

        $pools = $this->pools($entityMatch, $enEntity, $ruEntity, $enTotal, $ruTotal);

        $chunkSize = (int) $entityMatch->chunk_size;
        $enOffset = (int) $entityMatch->last_en_sentence_offset;
        $ruOffset = (int) $entityMatch->last_ru_sentence_offset;

        $poolIndex = 0;
        $poolCount = count($pools);

        while ($poolIndex < $poolCount) {
            $pool = $pools[$poolIndex];

            $poolEnStart = max($pool['en_start'], $enOffset);
            $poolRuStart = max($pool['ru_start'], $ruOffset);

            if ($poolEnStart >= $pool['en_end'] && $poolRuStart >= $pool['ru_end']) {
                $poolIndex++;

                continue;
            }

            $remainingEn = $pool['en_end'] - $poolEnStart;
            $remainingRu = $pool['ru_end'] - $poolRuStart;

            if ($remainingEn <= 0) {
                $poolIndex++;

                continue;
            }

            if ($remainingRu <= 0) {
                $entityMatch->update([
                    'status' => 'completed',
                    'error_message' => 'RU sentences exhausted before EN',
                    'last_en_sentence_offset' => max($enOffset, $pool['en_end']),
                    'last_ru_sentence_offset' => $ruOffset,
                    'completed_at' => now(),
                ]);

                return;
            }

            if ($remainingEn <= $chunkSize && $remainingRu <= $chunkSize) {
                $hasRollbackCandidates = $this->rollbackCandidates(
                    $entityMatch,
                    $enEntity,
                    $ruEntity,
                    $pool,
                )->isNotEmpty();

                if (! $hasRollbackCandidates) {
                    $offsets = $this->alignWholePool(
                        $entityMatch,
                        $enEntity,
                        $ruEntity,
                        $poolEnStart,
                        $pool['en_end'],
                        $poolRuStart,
                        $pool['ru_end'],
                    );

                    $enOffset = $offsets['en_offset'];
                    $ruOffset = $offsets['ru_offset'];

                    if ($enOffset >= $pool['en_end'] && $ruOffset >= $pool['ru_end']) {
                        $poolIndex++;
                    }

                    continue;
                }
            }

            $this->alignPoolChunk(
                $entityMatch,
                $enEntity,
                $ruEntity,
                $enTotal,
                $enOffset,
                $ruOffset,
                $pool,
            );

            return;
        }

        $this->persistOffsets($entityMatch, $enOffset, $ruOffset, $enTotal);
    }

    /**
     * Split the sentence span into independent pools delimited by landmark
     * rows. Each pool is an exclusive [start, end) index range on both sides;
     * pools that degenerate to a single point (abutting landmarks) are
     * dropped. With no landmarks the whole span is one pool, which keeps the
     * fresh-alignment path identical to the previous chunking behavior.
     *
     * @return list<array{en_start: int, en_end: int, ru_start: int, ru_end: int}>
     */
    private function pools(
        EnRuEntityMatch $entityMatch,
        EnEntity $enEntity,
        RuEntity $ruEntity,
        int $enTotal,
        int $ruTotal,
    ): array {
        $bounds = self::landmarkBounds(
            self::landmarkRows($entityMatch),
            self::sentenceIndex(EnEntitySentence::class, 'en_entity_id', $enEntity->id),
            self::sentenceIndex(RuEntitySentence::class, 'ru_entity_id', $ruEntity->id),
        );

        if ($bounds === []) {
            return [[
                'en_start' => 0,
                'en_end' => $enTotal,
                'ru_start' => 0,
                'ru_end' => $ruTotal,
            ]];
        }

        usort(
            $bounds,
            fn (array $a, array $b): int => $a['en_start'] <=> $b['en_start'] ?: $a['ru_start'] <=> $b['ru_start'],
        );

        $pools = [];
        $enStart = 0;
        $ruStart = 0;

        foreach ($bounds as $bound) {
            $pools[] = [
                'en_start' => $enStart,
                'en_end' => $bound['en_start'],
                'ru_start' => $ruStart,
                'ru_end' => $bound['ru_start'],
            ];

            $enStart = $bound['en_end'];
            $ruStart = $bound['ru_end'];
        }

        $pools[] = [
            'en_start' => $enStart,
            'en_end' => $enTotal,
            'ru_start' => $ruStart,
            'ru_end' => $ruTotal,
        ];

        return array_values(array_filter(
            $pools,
            fn (array $pool): bool => $pool['en_start'] < $pool['en_end'] && $pool['ru_start'] < $pool['ru_end'],
        ));
    }

    /**
     * Hard pins for the pool partitioner: human-edited rows (alignment_chunk
     * -1) and auto-landmarks at or above the confidence bar. Ordered by order
     * so the partitioner walks them in document sequence, with both junction
     * sides eager-loaded.
     *
     * @return Collection<int, EnRuMeaningMatch>
     */
    private static function landmarkRows(EnRuEntityMatch $entityMatch): Collection
    {
        return EnRuMeaningMatch::query()
            ->where('en_ru_entity_match_id', $entityMatch->id)
            ->where(fn ($query) => $query
                ->where('alignment_chunk', -1)
                ->orWhere('similarity', '>=', self::LANDMARK_THRESHOLD))
            ->orderBy('order')
            ->orderBy('id')
            ->with('enSentenceMatches', 'ruSentenceMatches')
            ->get();
    }

    /**
     * The inclusive sentence span each landmark pins on both sides, expressed
     * as absolute [start, end) index ranges. Landmarks without junction rows
     * on either side contribute no boundary.
     *
     * @param  Collection<int, EnRuMeaningMatch>  $landmarks
     * @param  array<int, int>  $enIndex
     * @param  array<int, int>  $ruIndex
     * @return list<array{en_start: int, en_end: int, ru_start: int, ru_end: int}>
     */
    private static function landmarkBounds(Collection $landmarks, array $enIndex, array $ruIndex): array
    {
        $bounds = [];

        foreach ($landmarks as $landmark) {
            $enPositions = $landmark->enSentenceMatches
                ->pluck('en_entity_sentence_id')
                ->map(fn (int $id): int => $enIndex[$id] ?? -1)
                ->filter(fn (int $position): bool => $position >= 0)
                ->values()
                ->all();

            $ruPositions = $landmark->ruSentenceMatches
                ->pluck('ru_entity_sentence_id')
                ->map(fn (int $id): int => $ruIndex[$id] ?? -1)
                ->filter(fn (int $position): bool => $position >= 0)
                ->values()
                ->all();

            if ($enPositions === [] || $ruPositions === []) {
                continue;
            }

            $bounds[] = [
                'en_start' => min($enPositions),
                'en_end' => max($enPositions) + 1,
                'ru_start' => min($ruPositions),
                'ru_end' => max($ruPositions) + 1,
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
    private static function sentenceIndex(string $modelClass, string $entityColumn, int $entityId): array
    {
        return array_flip($modelClass::query()
            ->where($entityColumn, $entityId)
            ->orderBy('order')
            ->orderBy('id')
            ->pluck('id')
            ->values()
            ->all());
    }

    /**
     * Align a pool small enough to fit a single /align call. The pool edges
     * are the landmarks themselves, so no seam rollback/trim is needed:
     * everything the python service returns is committed and trailing skips
     * are stored up to the pool boundary. With no committed match the EN
     * cursor advances by one so the alignment never stalls.
     *
     * @return array{en_offset: int, ru_offset: int}
     */
    private function alignWholePool(
        EnRuEntityMatch $entityMatch,
        EnEntity $enEntity,
        RuEntity $ruEntity,
        int $enStart,
        int $enEnd,
        int $ruStart,
        int $ruEnd,
    ): array {
        $enSentences = EnEntitySentence::query()
            ->where('en_entity_id', $enEntity->id)
            ->orderBy('order')
            ->orderBy('id')
            ->offset($enStart)
            ->limit($enEnd - $enStart)
            ->get();

        $ruSentences = RuEntitySentence::query()
            ->where('ru_entity_id', $ruEntity->id)
            ->orderBy('order')
            ->orderBy('id')
            ->offset($ruStart)
            ->limit($ruEnd - $ruStart)
            ->get();

        if ($enSentences->isEmpty() || $ruSentences->isEmpty()) {
            return ['en_offset' => $enStart + 1, 'ru_offset' => $ruStart];
        }

        $service = SentenceAlignmentService::create();

        $matches = $service->alignChunkRemote(
            $enSentences,
            $ruSentences,
            $entityMatch->max_n,
        )['matches'];

        $committed = $this->committedMatches($matches, true);

        if ($committed === []) {
            return ['en_offset' => $enStart + 1, 'ru_offset' => $ruStart];
        }

        $alignmentChunk = $this->nextAlignmentChunk($entityMatch->id);

        $service->storeAlignmentSegmentFromMatches(
            entityMatch: $entityMatch,
            alignmentChunk: $alignmentChunk,
            committedMatches: $committed,
            enSentences: $enSentences,
            ruSentences: $ruSentences,
            isLastChunk: true,
        );

        return ['en_offset' => $enEnd, 'ru_offset' => $ruEnd];
    }

    /**
     * Align a single chunk inside a large pool and resume in a fresh job. This
     * is the original seam-rollback machinery scoped to the pool's index
     * range: the rollback only ever drags machine rows back within the pool,
     * never across a landmark boundary.
     */
    private function alignPoolChunk(
        EnRuEntityMatch $entityMatch,
        EnEntity $enEntity,
        RuEntity $ruEntity,
        int $enTotal,
        int $enOffset,
        int $ruOffset,
        array $pool,
    ): void {
        $chunkSize = (int) $entityMatch->chunk_size;

        $storedEnOffset = $enOffset;
        $storedRuOffset = $ruOffset;

        $enLimit = min($chunkSize, max(0, $pool['en_end'] - $enOffset));
        $ruLimit = min($chunkSize, max(0, $pool['ru_end'] - $ruOffset));

        if ($enLimit <= 0 || $ruLimit <= 0) {
            $this->persistOffsets(
                $entityMatch,
                max($enOffset, $pool['en_end']),
                max($ruOffset, $pool['ru_end']),
                $enTotal,
            );

            return;
        }

        $rollback = $this->rollbackPriorMatches(
            entityMatch: $entityMatch,
            enEntity: $enEntity,
            ruEntity: $ruEntity,
            enOffset: $enOffset,
            ruOffset: $ruOffset,
            enLimit: $enLimit,
            ruLimit: $ruLimit,
            pool: $pool,
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

        $isLastChunk = $enOffset + $enLimit >= $pool['en_end']
            && $ruOffset + $ruLimit >= $pool['ru_end'];

        $committed = $this->committedMatches($matches, $isLastChunk);

        $lastCommitted = $committed[array_key_last($committed)] ?? null;

        if ($lastCommitted === null) {
            $this->persistOffsets(
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

        $this->persistOffsets($entityMatch, $newEnOffset, $newRuOffset, $enTotal);
    }

    /**
     * Machine rows inside the pool that seam-rollback may drag back into the
     * window: not human-edited, below the landmark bar, and junctioned on
     * both sides. Landmark rows are excluded twice over (chunk sentinel and
     * similarity), so the rollback can never pull a pin into a window.
     *
     * @param  array{en_start: int, en_end: int, ru_start: int, ru_end: int}  $pool
     * @return Collection<int, EnRuMeaningMatch>
     */
    private function rollbackCandidates(
        EnRuEntityMatch $entityMatch,
        EnEntity $enEntity,
        RuEntity $ruEntity,
        array $pool,
    ): Collection {
        $poolEnIds = EnEntitySentence::query()
            ->where('en_entity_id', $enEntity->id)
            ->orderBy('order')
            ->orderBy('id')
            ->offset($pool['en_start'])
            ->limit($pool['en_end'] - $pool['en_start'])
            ->pluck('id')
            ->all();

        $poolRuIds = RuEntitySentence::query()
            ->where('ru_entity_id', $ruEntity->id)
            ->orderBy('order')
            ->orderBy('id')
            ->offset($pool['ru_start'])
            ->limit($pool['ru_end'] - $pool['ru_start'])
            ->pluck('id')
            ->all();

        return EnRuMeaningMatch::query()
            ->where('en_ru_entity_match_id', $entityMatch->id)
            ->where('alignment_chunk', '!=', -1)
            ->where('similarity', '<', self::LANDMARK_THRESHOLD)
            ->whereHas('enSentenceMatches', fn ($query) => $query->whereIn('en_entity_sentence_id', $poolEnIds))
            ->whereHas('ruSentenceMatches', fn ($query) => $query->whereIn('ru_entity_sentence_id', $poolRuIds))
            ->orderByDesc('order')
            ->limit(self::ROLLBACK_MATCHES)
            ->get();
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
     * candidates. The rewind is clamped to the pool start so landmarks are
     * never pulled into a window. The limits grow by the rolled-back spans so
     * the window's forward reach is unchanged.
     *
     * @param  array{en_start: int, en_end: int, ru_start: int, ru_end: int}  $pool
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
        array $pool,
    ): array {
        $candidates = $this->rollbackCandidates($entityMatch, $enEntity, $ruEntity, $pool);

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

        $newEnOffset = max(
            $this->rollbackOffset(EnEntitySentence::class, 'en_entity_id', $enEntity->id, $enSentenceIds, $enOffset),
            $pool['en_start'],
        );
        $newRuOffset = max(
            $this->rollbackOffset(RuEntitySentence::class, 'ru_entity_id', $ruEntity->id, $ruSentenceIds, $ruOffset),
            $pool['ru_start'],
        );

        EnRuMeaningMatch::whereKey($candidates->pluck('id'))->delete();

        $enRollback = max(0, $enOffset - $newEnOffset);
        $ruRollback = max(0, $ruOffset - $newRuOffset);

        return [
            'en_offset' => $newEnOffset,
            'ru_offset' => $newRuOffset,
            'en_limit' => min($enLimit + $enRollback, max(0, $pool['en_end'] - $newEnOffset)),
            'ru_limit' => min($ruLimit + $ruRollback, max(0, $pool['ru_end'] - $newRuOffset)),
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

    private function persistOffsets(
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
