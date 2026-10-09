<?php

namespace App\Classes;

use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The alignment refine round — a second pass over a completed alignment that
 * re-aligns only the regions around one-sided machine rows, with the python
 * service's DP aligner and joined window embeddings instead of round 1's
 * greedy + aggregate defaults.
 *
 * Why a second pass: the greedy window ladder compares raw cosines with no
 * cost for skipping a sentence, so a fusion (one long sentence translated as
 * several short ones) commits its strongest member as a 1:1 and leaves the
 * tail one-sided — even when the pooled 1:2 window would score higher. The DP
 * charges skip_penalty per skipped sentence, so the grouping that covers more
 * of the region wins without having to beat every 1:1's cosine, and joined
 * window embeddings keep multi-sentence windows from diluting the way
 * averaged vectors do.
 *
 * Safety mirrors the re-align pipeline's invariants: a row is replaceable iff
 * alignment_chunk != HUMAN_CHUNK AND similarity < LANDMARK_THRESHOLD. Human
 * rows and auto-landmarks pin their regions (two-sided pins go to python as
 * landmark spans; single-sided pins bound regions, since a one-sided pin has
 * no span the aligner can honor). A region replaces its machine rows only on
 * strict improvement of the per-sentence coverage score — every match's (or
 * row's) similarity counts once per sentence it covers, so a one-sided row
 * contributes its sentence at 0.0 — meaning the accepted regrouping must
 * raise the region's mean sentence similarity. That is the trade the DP
 * makes (a weaker fused window that covers the orphan beats a strong 1:1
 * plus a zero-covered tail); the summed-score gate this replaced vetoed
 * exactly that trade and regrouped nothing. Neutral or worse regroupings
 * never land. Every write goes through MeaningMatchStore, whose
 * persistSegment deletes machine rows below the landmark bar covering
 * incoming sentences and reserves pinned junctions.
 */
class AlignmentRefineService
{
    /** Rows of context taken on each side of a one-sided candidate row. */
    private const CONTEXT_ROWS = 2;

    /** Merged-region cap in rows; context edges are trimmed past it. */
    private const MAX_REGION_ROWS = 8;

    /** Per-side window ceiling — a hard skip, python caps /align at 500. */
    private const MAX_WINDOW_SENTENCES = 200;

    /**
     * Strict-improvement epsilon on the region's per-sentence coverage sums:
     * an identical regrouping ties (rejected — no churn), anything meaningfully
     * better applies.
     */
    private const MIN_IMPROVEMENT = 0.01;

    /**
     * A new match scoring below this bar is garbage, not a weak-but-genuine
     * pair (the repo's rescue bar): a region whose regrouping contains one is
     * rejected outright, leaving the clean single-sided rows in place. Under
     * the DP this never fires — a forced sub-threshold match costs -2.0
     * against -1.0 for double-skipping, so the optimum never contains one —
     * it guards the gate against future algorithm changes.
     */
    private const MATCH_SCORE_FLOOR = 0.45;

    public function __construct(
        private readonly SentenceAlignmentService $alignments,
        private readonly MeaningMatchStore $store,
    ) {}

    public static function create(): self
    {
        return new self(app(SentenceAlignmentService::class), app(MeaningMatchStore::class));
    }

    /**
     * Refine one completed entity match. The match flips completed →
     * aligning → completed so a concurrent re-align or a second dispatch
     * (the status gate) cannot interleave; a mid-run stale flip survives the
     * same rule as the align job's finalize(). A thrown failure restores
     * completed before rethrowing — regions already applied stay applied
     * (each region is its own transaction) and a re-run continues from the
     * remaining one-sided rows.
     *
     * @return array{status: string, reason?: string, regions: int, applied: int, rejected: int, skipped_regions: int, one_sided_before: int, one_sided_after: int}
     */
    public function refine(EntityMatch $entityMatch): array
    {
        if ($entityMatch->status !== 'completed') {
            return $this->summary('skipped', "match status is {$entityMatch->status}, not completed");
        }

        $aEntity = $entityMatch->aEntity;
        $bEntity = $entityMatch->bEntity;

        if ($aEntity === null || $bEntity === null) {
            return $this->summary('skipped', 'missing side entity');
        }

        $oneSidedBefore = $this->oneSidedCount($entityMatch);

        $entityMatch->update(['status' => 'aligning', 'error_message' => null]);

        try {
            $summary = $this->run($entityMatch, $aEntity, $bEntity);
        } catch (Throwable $exception) {
            $entityMatch->refresh();

            if ($entityMatch->status === 'aligning') {
                $entityMatch->update(['status' => 'completed']);
            }

            throw $exception;
        }

        $entityMatch->refresh();

        if ($entityMatch->status === 'aligning') {
            $entityMatch->update(['status' => 'completed']);
        }

        return [
            ...$summary,
            'one_sided_before' => $oneSidedBefore,
            'one_sided_after' => $this->oneSidedCount($entityMatch),
        ];
    }

    /**
     * @return array{status: string, reason?: string, regions: int, applied: int, rejected: int, skipped_regions: int, one_sided_before: int, one_sided_after: int}
     */
    private function summary(string $status, ?string $reason = null, int $regions = 0, int $applied = 0, int $rejected = 0, int $skippedRegions = 0, int $oneSidedBefore = 0, int $oneSidedAfter = 0): array
    {
        return [
            'status' => $status,
            'reason' => $reason,
            'regions' => $regions,
            'applied' => $applied,
            'rejected' => $rejected,
            'skipped_regions' => $skippedRegions,
            'one_sided_before' => $oneSidedBefore,
            'one_sided_after' => $oneSidedAfter,
        ];
    }

    /**
     * @return array{status: string, reason?: string, regions: int, applied: int, rejected: int, skipped_regions: int, one_sided_before: int, one_sided_after: int}
     */
    private function run(EntityMatch $entityMatch, Entity $aEntity, Entity $bEntity): array
    {
        $rows = MeaningMatch::query()
            ->where('entity_match_id', $entityMatch->id)
            ->orderBy('order')
            ->orderBy('id')
            ->with('sentenceMeaningMatches')
            ->get();

        $candidateIndexes = $this->candidateIndexes($rows);

        if ($candidateIndexes === []) {
            return $this->summary('refined', 'no one-sided machine rows');
        }

        $aIndex = $this->sentenceIndex($aEntity->id);
        $bIndex = $this->sentenceIndex($bEntity->id);

        $regions = $this->regions($rows, $candidateIndexes);

        $applied = 0;
        $rejected = 0;
        $skipped = 0;

        foreach ($regions as $region) {
            $summary = $this->refineRegion($entityMatch, $aEntity, $bEntity, $rows, $region, $aIndex, $bIndex);

            match ($summary) {
                'applied' => $applied++,
                'rejected' => $rejected++,
                default => $skipped++,
            };
        }

        // Resequence + junction-less backfill + linked_count sync: the
        // per-region stores append orders past the previous max, so the
        // order column must be normalized back to document position (the
        // store call itself already covers each window's sentences — this is
        // belt, braces, and the ADR 0043 resequence point).
        $this->store->repairCoverage($entityMatch);

        return $this->summary('refined', null, count($regions), $applied, $rejected, $skipped);
    }

    /**
     * Indexes into $rows of the one-sided machine rows — the refine round's
     * only candidates. One-sided = junctioned on exactly one side (the same
     * shape the editor's Needs-review list flags), machine = not human and
     * below the landmark bar, so pins are never selected.
     *
     * @param  Collection<int, MeaningMatch>  $rows
     * @return list<int>
     */
    private function candidateIndexes(Collection $rows): array
    {
        $candidates = [];

        foreach ($rows as $index => $row) {
            $hasA = $row->sentenceMeaningMatches->contains(fn ($j) => $j->side === 'a');
            $hasB = $row->sentenceMeaningMatches->contains(fn ($j) => $j->side === 'b');

            if ($hasA === $hasB) {
                continue;
            }

            if ($row->alignment_chunk === MeaningMatch::HUMAN_CHUNK
                || (float) $row->similarity >= MeaningMatch::LANDMARK_THRESHOLD) {
                continue;
            }

            $candidates[] = $index;
        }

        return $candidates;
    }

    /**
     * Merge each candidate's context window into disjoint row-index
     * intervals. Expansion stops at single-sided pins: they hold no
     * alignable span on one side, so a region spanning one would submit its
     * sentence to python only to have persistSegment drop it as reserved —
     * the score sum would count a junction that never lands. Two-sided pins
     * are ordinary context rows; their region passes them to python as
     * landmark spans. Intervals on opposite sides of a single-sided pin
     * never touch, so they never merge across it.
     *
     * @param  Collection<int, MeaningMatch>  $rows
     * @param  list<int>  $candidates
     * @return list<array{start: int, end: int}> inclusive row-index intervals
     */
    private function regions(Collection $rows, array $candidates): array
    {
        $singleSidedPins = [];

        foreach ($rows as $index => $row) {
            if (! $this->isPin($row)) {
                continue;
            }

            $hasA = $row->sentenceMeaningMatches->contains(fn ($j) => $j->side === 'a');
            $hasB = $row->sentenceMeaningMatches->contains(fn ($j) => $j->side === 'b');

            if ($hasA !== $hasB) {
                $singleSidedPins[$index] = true;
            }
        }

        $intervals = [];

        foreach ($candidates as $candidate) {
            $start = $candidate;

            for ($back = 0; $back < self::CONTEXT_ROWS; $back++) {
                if ($start === 0 || isset($singleSidedPins[$start - 1])) {
                    break;
                }

                $start--;
            }

            $end = $candidate;
            $last = count($rows) - 1;

            for ($ahead = 0; $ahead < self::CONTEXT_ROWS; $ahead++) {
                if ($end === $last || isset($singleSidedPins[$end + 1])) {
                    break;
                }

                $end++;
            }

            $intervals[] = ['start' => $start, 'end' => $end];
        }

        usort($intervals, fn (array $x, array $y): int => $x['start'] <=> $y['start']);

        $merged = [];

        foreach ($intervals as $interval) {
            $last = count($merged) - 1;

            if ($last >= 0 && $interval['start'] <= $merged[$last]['end'] + 1) {
                $merged[$last]['end'] = max($merged[$last]['end'], $interval['end']);

                continue;
            }

            $merged[] = $interval;
        }

        return array_map(
            fn (array $interval): array => $this->trimInterval($rows, $candidates, $interval),
            $merged,
        );
    }

    /**
     * Trim context rows from the interval's edges past MAX_REGION_ROWS —
     * candidate rows always stay, and when both edges are trimmable a plain
     * machine row goes before a pin (pins anchor landmarks, though trimming
     * one from an edge is safe at row granularity: a pin is fully in or out).
     *
     * @param  Collection<int, MeaningMatch>  $rows
     * @param  list<int>  $candidates
     * @param  array{start: int, end: int}  $interval
     * @return array{start: int, end: int}
     */
    private function trimInterval(Collection $rows, array $candidates, array $interval): array
    {
        $candidateSet = array_fill_keys($candidates, true);

        while ($interval['end'] - $interval['start'] + 1 > self::MAX_REGION_ROWS) {
            $startIsCandidate = isset($candidateSet[$interval['start']]);
            $endIsCandidate = isset($candidateSet[$interval['end']]);

            if (! $startIsCandidate && ($endIsCandidate || ! $this->isPin($rows[$interval['start']]))) {
                $interval['start']++;
            } elseif (! $endIsCandidate) {
                $interval['end']--;
            } else {
                break; // only candidate rows left — let the window run wide
            }
        }

        return $interval;
    }

    private function isPin(MeaningMatch $row): bool
    {
        return $row->alignment_chunk === MeaningMatch::HUMAN_CHUNK
            || (float) $row->similarity >= MeaningMatch::LANDMARK_THRESHOLD;
    }

    /**
     * Re-align one region and replace its machine rows when the regrouping
     * strictly improves the region's per-sentence coverage: old rows
     * contribute their similarity once per junction (a one-sided row covers
     * its sentence at 0.0), new matches once per sentence they span — so a
     * fused window that scores below the head 1:1 still wins when it pulls a
     * zero-covered orphan into the group, and an identical regrouping ties
     * and is rejected. Returns 'applied', 'rejected' (gate said no —
     * nothing written), or 'skipped' (degenerate window).
     *
     * @param  Collection<int, MeaningMatch>  $rows
     * @param  array{start: int, end: int}  $region
     * @param  array<int, int>  $aIndex
     * @param  array<int, int>  $bIndex
     * @return 'applied'|'rejected'|'skipped'
     */
    private function refineRegion(
        EntityMatch $entityMatch,
        Entity $aEntity,
        Entity $bEntity,
        Collection $rows,
        array $region,
        array $aIndex,
        array $bIndex,
    ): string {
        $regionRows = range($region['start'], $region['end']);

        $aPositions = [];
        $bPositions = [];

        foreach ($regionRows as $index) {
            $row = $rows[$index];

            foreach ($row->sentenceMeaningMatches as $junction) {
                if ($junction->side === 'a') {
                    if (($position = $aIndex[$junction->entity_sentence_id] ?? null) !== null) {
                        $aPositions[] = $position;
                    }
                } else {
                    if (($position = $bIndex[$junction->entity_sentence_id] ?? null) !== null) {
                        $bPositions[] = $position;
                    }
                }
            }
        }

        if ($aPositions === [] || $bPositions === []) {
            return 'skipped';
        }

        $aStart = min($aPositions);
        $aEnd = max($aPositions) + 1;
        $bStart = min($bPositions);
        $bEnd = max($bPositions) + 1;

        if ($aEnd - $aStart > self::MAX_WINDOW_SENTENCES
            || $bEnd - $bStart > self::MAX_WINDOW_SENTENCES) {
            return 'skipped';
        }

        // Two-sided pins inside the region go to python as hard landmark
        // spans (window-relative indices); their rows are excluded from both
        // sides of the gate — they survive the replacement unchanged. The
        // gate's unit is one sentence's similarity: an old row contributes
        // its similarity once per junction (a one-sided row covers its
        // sentence at 0.0), a new match once per sentence it spans.
        $landmarks = [];
        $oldSum = 0.0;

        foreach ($regionRows as $index) {
            $row = $rows[$index];

            if ($this->isPin($row)) {
                $span = $this->pinSpan($row, $aIndex, $bIndex, $aStart, $aEnd, $bStart, $bEnd);

                if ($span !== null) {
                    $landmarks[] = $span;
                }

                continue;
            }

            $oldSum += (float) $row->similarity * $row->sentenceMeaningMatches->count();
        }

        $aSentences = $this->sentenceSlice($aEntity->id, $aStart, $aEnd - $aStart);
        $bSentences = $this->sentenceSlice($bEntity->id, $bStart, $bEnd - $bStart);

        $matches = $this->alignments->alignChunkRemote(
            $aSentences,
            $bSentences,
            (int) $entityMatch->max_n,
            $landmarks,
            null,
            'dp',
            'joined',
        )['matches'];

        $newSum = 0.0;
        $scores = [];

        foreach ($matches as $match) {
            if ($this->isPinSpan($match, $landmarks)) {
                continue;
            }

            $score = (float) ($match['score'] ?? 0.0);

            // One garbage match poisons the region: a sub-floor grouping is
            // noise, not a weak-but-genuine pair. (Under the DP this cannot
            // fire — a forced sub-threshold match costs -2.0 against -1.0
            // for double-skipping — it guards the gate against algorithm
            // changes; see MATCH_SCORE_FLOOR.)
            if ($score < self::MATCH_SCORE_FLOOR) {
                return 'rejected';
            }

            $scores[] = $score;
            $newSum += $score * (((int) $match['a_end'] - (int) $match['a_start'])
                + ((int) $match['b_end'] - (int) $match['b_start']));
        }

        if ($newSum <= $oldSum + self::MIN_IMPROVEMENT) {
            Log::info('Alignment refine region rejected', [
                'entity_match_id' => $entityMatch->id,
                'region_rows' => [$region['start'], $region['end']],
                'old_sum' => round($oldSum, 4),
                'new_sum' => round($newSum, 4),
                'scores' => $scores,
            ]);

            return 'rejected';
        }

        Log::info('Alignment refine region applied', [
            'entity_match_id' => $entityMatch->id,
            'region_rows' => [$region['start'], $region['end']],
            'old_sum' => round($oldSum, 4),
            'new_sum' => round($newSum, 4),
            'scores' => $scores,
        ]);

        $this->store->storeAlignmentSegmentFromMatches(
            entityMatch: $entityMatch,
            alignmentChunk: $entityMatch->nextAlignmentChunk(),
            committedMatches: $matches,
            aSentences: $aSentences,
            bSentences: $bSentences,
            isLastChunk: true,
        );

        return 'applied';
    }

    /**
     * A two-sided pin's span as window-relative landmark indices, or null
     * when its junctions don't sit fully inside the region window (only
     * possible for degenerate rows — the pin is a region row by
     * construction).
     *
     * @param  array<int, int>  $aIndex
     * @param  array<int, int>  $bIndex
     * @return array{a_start: int, a_end: int, b_start: int, b_end: int}|null
     */
    private function pinSpan(MeaningMatch $row, array $aIndex, array $bIndex, int $aStart, int $aEnd, int $bStart, int $bEnd): ?array
    {
        $aPositions = [];
        $bPositions = [];

        foreach ($row->sentenceMeaningMatches as $junction) {
            if ($junction->side === 'a') {
                $aPositions[] = $aIndex[$junction->entity_sentence_id] ?? -1;
            } else {
                $bPositions[] = $bIndex[$junction->entity_sentence_id] ?? -1;
            }
        }

        if ($aPositions === [] || $bPositions === [] || min($aPositions) < 0 || min($bPositions) < 0) {
            return null;
        }

        $span = [
            'a_start' => min($aPositions) - $aStart,
            'a_end' => max($aPositions) + 1 - $aStart,
            'b_start' => min($bPositions) - $bStart,
            'b_end' => max($bPositions) + 1 - $bStart,
        ];

        if ($span['a_start'] < 0 || $span['b_start'] < 0
            || $span['a_end'] > $aEnd - $aStart || $span['b_end'] > $bEnd - $bStart) {
            return null;
        }

        return $span;
    }

    /**
     * A python match is the re-emission of a landmark pin we sent (score
     * 1.0, exact span) — excluded from the gate's new sum; the pin's row
     * survives the replacement untouched.
     *
     * @param  array{a_start: int, a_end: int, b_start: int, b_end: int}  $match
     * @param  list<array{a_start: int, a_end: int, b_start: int, b_end: int}>  $landmarks
     */
    private function isPinSpan(array $match, array $landmarks): bool
    {
        foreach ($landmarks as $landmark) {
            if ((int) $match['a_start'] === $landmark['a_start']
                && (int) $match['a_end'] === $landmark['a_end']
                && (int) $match['b_start'] === $landmark['b_start']
                && (int) $match['b_end'] === $landmark['b_end']) {
                return true;
            }
        }

        return false;
    }

    /**
     * The image-less sentence position (0-based, order-by-order then id) of
     * each sentence, keyed by id — the same space the align job's cursors
     * and windows advance in (ADR 0050).
     *
     * @return array<int, int>
     */
    private function sentenceIndex(int $entityId): array
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
     * An image-less slice of one side's sentences, mirroring the align job's
     * window loading.
     */
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

    private function oneSidedCount(EntityMatch $entityMatch): int
    {
        return MeaningMatch::query()
            ->where('entity_match_id', $entityMatch->id)
            ->where('alignment_chunk', '!=', MeaningMatch::HUMAN_CHUNK)
            ->where('similarity', '<', MeaningMatch::LANDMARK_THRESHOLD)
            ->where(function ($query) {
                $query->where(function ($oneSided) {
                    $oneSided->whereHas('sentenceMeaningMatches', fn ($j) => $j->where('side', 'a'))
                        ->whereDoesntHave('sentenceMeaningMatches', fn ($j) => $j->where('side', 'b'));
                })->orWhere(function ($oneSided) {
                    $oneSided->whereHas('sentenceMeaningMatches', fn ($j) => $j->where('side', 'b'))
                        ->whereDoesntHave('sentenceMeaningMatches', fn ($j) => $j->where('side', 'a'));
                });
            })
            ->count();
    }
}
