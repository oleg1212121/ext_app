<?php

namespace App\Classes;

use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The auto-align pipeline's write path for meaning matches: storing a chunk's
 * committed alignment, resequencing rows into document order, and repairing
 * junction-less sentences. The sibling of AlignmentEditorService (ADR 0062) —
 * the editor's mutation domain never writes through here, and this class
 * never writes on the editor's behalf. Path composition (links / dpPath
 * arrays) lives on SentenceAlignmentService; this class owns every DB write
 * and each method opens its own transaction (the job's persistOffsets wraps
 * store calls in an outer one, which nests as a savepoint) — repairCoverage
 * wraps the whole coverage repair in one.
 *
 * Write idioms are deliberate: single-row creates go through Eloquent events
 * (SentenceMeaningMatch::creating backfills the denormalized entity_match_id,
 * ADR 0048), bulk deletes and the two-phase order parking (via
 * SparseOrderService::persistOrdersTwoPhase) use the quiet Eloquent builder,
 * and the hot junction-insert path uses base-builder insert() with hand-set
 * timestamps because it bypasses that hook.
 */
class MeaningMatchStore
{
    public function __construct(private readonly SentenceAlignmentService $aligner) {}

    public static function create(): self
    {
        return new self(app(SentenceAlignmentService::class));
    }

    /**
     * Store the committed prefix of python matches for a single chunk. Only
     * the passed matches are persisted; sentences after the last committed
     * match (up to chunk end) are left untouched and re-fed on the next
     * invocation. On the last chunk the full window is stored, including
     * trailing skip markers.
     *
     * @param  list<array{a_start: int, a_end: int, b_start: int, b_end: int, score: float}>  $committedMatches
     */
    public function storeAlignmentSegmentFromMatches(
        EntityMatch $entityMatch,
        int $alignmentChunk,
        array $committedMatches,
        Collection $aSentences,
        Collection $bSentences,
        bool $isLastChunk = false,
    ): void {
        if ($committedMatches === []) {
            return;
        }

        $path = $isLastChunk
            ? $this->aligner->buildCommittedPath($committedMatches, $aSentences, $bSentences, $aSentences->count(), $bSentences->count())
            : $this->aligner->buildCommittedPath($committedMatches, $aSentences, $bSentences);

        $this->persistSegment($entityMatch, $alignmentChunk, $path['links'], $path['dpPath']);
    }

    /**
     * Store single-sided (skip) meaning matches for sentences on one side.
     * Each sentence becomes a meaning match with only that side junctioned,
     * keeping it visible in the reader while the other column stays empty.
     *
     * @param  'a'|'b'  $side
     */
    public function storeSkipSentences(
        EntityMatch $entityMatch,
        int $alignmentChunk,
        string $side,
        Collection $sentences,
    ): void {
        if ($sentences->isEmpty()) {
            return;
        }

        $type = $side === 'a' ? 'skip_a' : 'skip_b';

        $this->persistSegment(
            $entityMatch,
            $alignmentChunk,
            [],
            $this->aligner->buildSkipOnlyPath($type, $sentences->pluck('id')->values()->all()),
        );
    }

    /**
     * Renumber an entity match's meaning matches 0, 1024, 2048... in document
     * position order, so walking the rows by `order` reads each side's
     * sentences in their original sequence. Repairs the appended-after-max
     * sequences that re-align rounds leave behind — every display surface
     * sorts strictly by `order`, so a scrambled order column IS a scrambled
     * alignment. Two-phase write (park at unique negatives first) respects
     * the (entity_match_id, order) unique index.
     *
     * Strict junction uniqueness is enforced first (ADR 0048): one sentence
     * is junctioned into at most one meaning match per side per entity match.
     * For every (side, sentence) held by several rows a single keeper is
     * elected and the losers' junctions on that sentence are deleted (partial
     * overlaps included); rows left with no junctions are deleted too. See
     * duplicateJunctionResolutions for the priority rules.
     *
     * Ordering rule: rows junctioned on side a (two-sided and a-only) sort by
     * their a position; single-b rows have no a anchor, so they cannot share
     * that comparator — mixing the two scales is what historically put a
     * b-only row after a two-sided row whose b sentences came later. Instead,
     * a-anchored rows are laid out first and each pending b-only row is
     * emitted immediately before the first anchored row whose b position is
     * larger (a-only rows give no b information and never hold one back).
     * Junction-less rows go last by their stored order.
     *
     * @return int The number of junctions/rows deleted or orders changed
     */
    public function resequenceMatchesByDocumentPosition(EntityMatch $entityMatch): int
    {
        $positions = [];

        foreach ([$entityMatch->a_entity_id, $entityMatch->b_entity_id] as $entityId) {
            $sentenceIds = EntitySentence::query()
                ->where('entity_id', $entityId)
                ->orderBy('order')
                ->orderBy('id')
                ->pluck('id')
                ->all();

            foreach ($sentenceIds as $index => $sentenceId) {
                $positions[$sentenceId] = $index;
            }
        }

        $rows = MeaningMatch::query()
            ->where('entity_match_id', $entityMatch->id)
            ->with('sentenceMeaningMatches')
            ->orderBy('order')
            ->orderBy('id')
            ->get(['id', 'order', 'similarity', 'alignment_chunk']);

        [$deleteJunctionIds, $deleteRowIds] = $this->duplicateJunctionResolutions($rows);

        if ($deleteRowIds !== []) {
            $rows = $rows->reject(fn ($row) => in_array($row->id, $deleteRowIds))->values();
        }

        $anchored = [];
        $bOnly = [];
        $junctionless = [];

        foreach ($rows as $row) {
            $posA = null;
            $posB = null;

            foreach ($row->sentenceMeaningMatches as $junction) {
                $position = $positions[$junction->entity_sentence_id] ?? null;

                if ($position === null) {
                    continue;
                }

                if ($junction->side === 'a') {
                    $posA = $posA === null ? $position : min($posA, $position);
                } else {
                    $posB = $posB === null ? $position : min($posB, $position);
                }
            }

            $entry = [
                'id' => $row->id,
                'order' => (int) $row->order,
                'posA' => $posA,
                'posB' => $posB,
            ];

            if ($posA !== null) {
                $anchored[] = $entry;
            } elseif ($posB !== null) {
                $bOnly[] = $entry;
            } else {
                $junctionless[] = $entry;
            }
        }

        usort($anchored, fn (array $x, array $y): int => [$x['posA'], $x['posB'] ?? PHP_INT_MAX, $x['order']]
            <=> [$y['posA'], $y['posB'] ?? PHP_INT_MAX, $y['order']]);

        usort($bOnly, fn (array $x, array $y): int => [$x['posB'], $x['order']] <=> [$y['posB'], $y['order']]);

        $sequence = [];

        foreach ($anchored as $entry) {
            if ($entry['posB'] !== null) {
                while ($bOnly !== [] && $bOnly[0]['posB'] < $entry['posB']) {
                    $sequence[] = array_shift($bOnly);
                }
            }

            $sequence[] = $entry;
        }

        foreach ($bOnly as $entry) {
            $sequence[] = $entry;
        }

        foreach ($junctionless as $entry) {
            $sequence[] = $entry;
        }

        $changes = [];
        $index = 0;

        foreach ($sequence as $entry) {
            $newOrder = SparseOrderService::STRIDE * $index;

            if ($entry['order'] !== $newOrder) {
                $changes[] = ['id' => $entry['id'], 'order' => $newOrder];
            }

            $index++;
        }

        if ($changes === [] && $deleteJunctionIds === [] && $deleteRowIds === []) {
            return 0;
        }

        DB::transaction(function () use ($changes, $deleteJunctionIds, $deleteRowIds): void {
            // Rows first — their junctions cascade — then any remaining loser
            // junctions of rows that keep other sentences.
            if ($deleteRowIds !== []) {
                MeaningMatch::query()->whereKey($deleteRowIds)->delete();
            }

            if ($deleteJunctionIds !== []) {
                SentenceMeaningMatch::query()->whereKey($deleteJunctionIds)->delete();
            }

            app(SparseOrderService::class)->persistOrdersTwoPhase(MeaningMatch::class, $changes);
        });

        return count($changes) + count($deleteJunctionIds) + count($deleteRowIds);
    }

    /**
     * Strict junction-uniqueness resolution: one sentence is junctioned into
     * at most one meaning match per side per entity match. For every (side,
     * sentence) key held by several rows a single keeper is elected by
     * priority — landmark/human rows (chunk -1 or at/above the landmark bar)
     * over machine rows, then two-sided over one-sided, richer rows, higher
     * similarity, lower order, lower id (so a human-vs-human conflict keeps
     * the earlier row) — and every other row's junction on that sentence is
     * deleted. A machine row can only lose to a landmark or a better machine
     * row; a landmark can only lose to another landmark. Rows left with no
     * junctions at all are deleted (junctions of deleted rows cascade).
     *
     * @param  Collection<int, MeaningMatch>  $rows
     * @return array{0: list<int>, 1: list<int>} [junction ids to delete, row ids to delete]
     */
    private function duplicateJunctionResolutions(Collection $rows): array
    {
        $priorities = [];

        foreach ($rows as $row) {
            $sides = $row->sentenceMeaningMatches->pluck('side');

            $priorities[$row->id] = [
                $row->alignment_chunk === MeaningMatch::HUMAN_CHUNK
                    || (float) $row->similarity >= MeaningMatch::LANDMARK_THRESHOLD ? 1 : 0,
                $sides->contains('a') && $sides->contains('b') ? 1 : 0,
                $row->sentenceMeaningMatches->count(),
                (float) $row->similarity,
                -(int) $row->order,
                -$row->id,
            ];
        }

        $keeperOf = [];
        $entriesByKey = [];

        foreach ($rows as $row) {
            foreach ($row->sentenceMeaningMatches as $junction) {
                $key = $junction->side.':'.$junction->entity_sentence_id;
                $entriesByKey[$key][] = ['junctionId' => $junction->id, 'rowId' => $row->id];

                $incumbentId = $keeperOf[$key] ?? null;

                if ($incumbentId === null
                    || $priorities[$row->id] > $priorities[$incumbentId]) {
                    $keeperOf[$key] = $row->id;
                }
            }
        }

        $deleteJunctionIds = [];
        $lostByRow = [];

        foreach ($entriesByKey as $key => $entries) {
            if (count($entries) < 2) {
                continue;
            }

            $keeperId = $keeperOf[$key];

            foreach ($entries as $entry) {
                if ($entry['rowId'] === $keeperId) {
                    continue;
                }

                $deleteJunctionIds[] = $entry;
                $lostByRow[$entry['rowId']] = ($lostByRow[$entry['rowId']] ?? 0) + 1;
            }
        }

        $deleteRowIds = [];

        foreach ($lostByRow as $rowId => $lost) {
            $row = $rows->firstWhere('id', $rowId);

            if ($row !== null && $row->sentenceMeaningMatches->isNotEmpty()
                && $lost >= $row->sentenceMeaningMatches->count()) {
                $deleteRowIds[] = $rowId;
            }
        }

        // Junctions of rows deleted wholesale cascade with their row — only
        // the survivors' trimmed junctions need an explicit delete (and only
        // those count toward the change total).
        $deleteJunctionIds = collect($deleteJunctionIds)
            ->reject(fn (array $entry): bool => in_array($entry['rowId'], $deleteRowIds))
            ->pluck('junctionId')
            ->values()
            ->all();

        return [$deleteJunctionIds, array_values($deleteRowIds)];
    }

    /**
     * Total-completeness repair for one entity match, whole-or-nothing in a
     * single transaction: every junction-less sentence of EITHER side is
     * junctioned into a single-sided meaning match (similarity 0.0, next
     * machine alignment chunk), the rows are resequenced into document
     * order, and linked_count is synced. The one primitive behind the align
     * job's completion gate and alignments:repair — a failure rolls the
     * whole repair back, leaving no half-repaired state.
     *
     * The resequence runs unconditionally: it is idempotent (returns 0 when
     * nothing is out of place) and normalizes both the pre-existing sequence
     * and the rows this repair creates.
     *
     * @return array{0: int, 1: int} [single-sided rows created, order/junction changes]
     */
    public function repairCoverage(EntityMatch $entityMatch): array
    {
        return DB::transaction(function () use ($entityMatch): array {
            // Order values already taken by this match's rows, shared across
            // both sides' repairs: opposite-side runs between the same anchors
            // compute identical spread values, and the claim check prevents
            // the second insert from violating unique(entity_match_id, order).
            $claimedOrders = array_fill_keys(
                MeaningMatch::query()
                    ->where('entity_match_id', $entityMatch->id)
                    ->pluck('order')
                    ->map(fn ($order) => (int) $order)
                    ->all(),
                true,
            );

            $created = 0;

            foreach (['a', 'b'] as $side) {
                [$junctionless, $index] = $this->junctionlessSentencesFor($entityMatch, $side);

                if ($junctionless->isNotEmpty()) {
                    $created += $this->repairJunctionlessSentences(
                        $entityMatch,
                        $side,
                        $junctionless,
                        $index,
                        $claimedOrders,
                    );
                }
            }

            $resequenced = $this->resequenceMatchesByDocumentPosition($entityMatch);

            $entityMatch->syncLinkedCount();

            return [$created, $resequenced];
        });
    }

    /**
     * The junction-less sentences of one side in document order, plus the
     * sentence-id => document-index map that anchors their position among the
     * meaning matches.
     *
     * @param  'a'|'b'  $side
     * @return array{0: Collection<int, EntitySentence>, 1: array<int, int>}
     */
    private function junctionlessSentencesFor(EntityMatch $entityMatch, string $side): array
    {
        $entityId = $side === 'a' ? $entityMatch->a_entity_id : $entityMatch->b_entity_id;

        $index = [];

        foreach (
            EntitySentence::query()
                ->where('entity_id', $entityId)
                ->orderBy('order')
                ->orderBy('id')
                ->pluck('id') as $position => $sentenceId
        ) {
            $index[$sentenceId] = $position;
        }

        return [
            EntitySentence::query()
                ->where('entity_id', $entityId)
                ->orderBy('order')
                ->orderBy('id')
                ->doesntHave('meaningJunctions')
                ->get(),
            $index,
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
     * $claimedOrders carries the meaning-match order values already taken
     * (pre-seeded with every existing row, shared across both sides' repairs
     * by repairCoverage): runs on opposite sides between the same anchors
     * compute identical spread values, and without the claim check the
     * second insert would violate unique(entity_match_id, order) and roll
     * the whole side's repair back. Claimed orders are nudged past
     * collisions, which keeps the relative order — the resequence pass
     * normalizes the values afterwards.
     *
     * @param  'a'|'b'  $side
     * @param  Collection<int, EntitySentence>  $junctionless
     * @param  array<int, int>  $index
     * @param  array<int, true>  $claimedOrders
     * @return int The number of single-sided rows created
     */
    private function repairJunctionlessSentences(
        EntityMatch $entityMatch,
        string $side,
        Collection $junctionless,
        array $index,
        array &$claimedOrders,
    ): int {
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
            return 0;
        }

        $sparseOrder = app(SparseOrderService::class);
        $alignmentChunk = $entityMatch->nextAlignmentChunk();
        $anchorIndexes = array_keys($anchors);
        $created = 0;

        DB::transaction(function () use (
            $entityMatch,
            $side,
            $sparseOrder,
            $alignmentChunk,
            $anchorIndexes,
            $anchors,
            $index,
            $runs,
            &$claimedOrders,
            &$created,
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
                    $order = self::claimOrder((int) $order, $claimedOrders);

                    $meaningMatch = MeaningMatch::create([
                        'entity_match_id' => $entityMatch->id,
                        'order' => $order,
                        'similarity' => 0.0,
                        'alignment_chunk' => $alignmentChunk,
                    ]);

                    SentenceMeaningMatch::create([
                        'entity_match_id' => $entityMatch->id,
                        'entity_sentence_id' => $run[$offset]->id,
                        'meaning_match_id' => $meaningMatch->id,
                        'side' => $side,
                    ]);

                    $created++;
                }
            }
        });

        return $created;
    }

    /**
     * The first order value at or after $order not present in $claimed,
     * marking it claimed. Nudging forward preserves relative order.
     *
     * @param  array<int, true>  $claimed
     */
    private static function claimOrder(int $order, array &$claimed): int
    {
        while (array_key_exists($order, $claimed)) {
            $order++;
        }

        $claimed[$order] = true;

        return $order;
    }

    private function persistSegment(
        EntityMatch $entityMatch,
        int $alignmentChunk,
        array $links,
        array $dpPathSegment,
    ): void {
        DB::transaction(function () use ($entityMatch, $alignmentChunk, $links, $dpPathSegment) {
            $now = now();

            MeaningMatch::query()
                ->where('entity_match_id', $entityMatch->id)
                ->where('alignment_chunk', $alignmentChunk)
                ->delete();

            // A re-fed window (retry after a crash, duplicated job) can carry
            // sentences that already carry junctions in machine rows from
            // other chunks. Junctioning them again puts one sentence in two
            // rows — unfixable by renumbering — so any machine row below the
            // landmark bar covering an incoming sentence is deleted wholesale
            // and its coverage re-stored here. Landmark and human rows
            // (chunk -1) are pinned and never deleted; pools and rollback
            // exclude them by construction, so an intersection is not
            // expected and any surviving overlap is left to the resequence
            // dedupe to resolve in favor of the pinned row.
            $incomingSentenceIds = collect($links)
                ->pluck('a_sentence_id')
                ->merge(collect($links)->pluck('b_sentence_id'))
                ->merge(collect($dpPathSegment)->pluck('a_sentence_id'))
                ->merge(collect($dpPathSegment)->pluck('b_sentence_id'))
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($incomingSentenceIds !== []) {
                MeaningMatch::query()
                    ->where('entity_match_id', $entityMatch->id)
                    ->where('alignment_chunk', '!=', MeaningMatch::HUMAN_CHUNK)
                    ->where('similarity', '<', MeaningMatch::LANDMARK_THRESHOLD)
                    ->whereHas('sentenceMeaningMatches', fn ($query) => $query
                        ->whereIn('entity_sentence_id', $incomingSentenceIds))
                    ->delete();
            }

            // Landmark sentences (human rows and at/above the bar) are
            // reserved: a machine window overlapping a single-sided landmark
            // re-feeds its sentence (single-sided landmarks delimit no pool),
            // and junctioning it here would put one sentence in two rows. The
            // incoming matches keep their other-side junctions; the reserved
            // sentence keeps its landmark row.
            $reserved = $incomingSentenceIds === [] ? [] : SentenceMeaningMatch::query()
                ->whereIn('entity_sentence_id', $incomingSentenceIds)
                ->whereHas('meaningMatch', fn ($query) => $query
                    ->where('entity_match_id', $entityMatch->id)
                    ->where(fn ($inner) => $inner
                        ->where('alignment_chunk', MeaningMatch::HUMAN_CHUNK)
                        ->orWhere('similarity', '>=', MeaningMatch::LANDMARK_THRESHOLD)))
                ->pluck('entity_sentence_id')
                ->flip()
                ->all();

            $maxOrder = MeaningMatch::query()
                ->where('entity_match_id', $entityMatch->id)
                ->max('order');

            $sparseOrder = app(SparseOrderService::class);
            $nextAlignmentOrder = $maxOrder === null ? 0 : ((int) $maxOrder) + SparseOrderService::STRIDE;

            $linksByOrder = collect($links)->groupBy('alignment_order');

            foreach ($dpPathSegment as $step) {
                $order = $nextAlignmentOrder + $sparseOrder->initial((int) $step['alignment_order']);
                $stepLinks = $linksByOrder->get($step['alignment_order'], collect());

                $similarity = $step['type'] === 'match' && $stepLinks->isNotEmpty()
                    ? round((float) $stepLinks->avg('similarity'), 4)
                    : 0.0;

                if ($step['type'] === 'match') {
                    $rows = $stepLinks
                        ->map(fn (array $link) => [
                            ['entity_sentence_id' => $link['a_sentence_id'], 'side' => 'a', 'a_order' => $link['a_order']],
                            ['entity_sentence_id' => $link['b_sentence_id'], 'side' => 'b', 'b_order' => $link['b_order']],
                        ])
                        ->flatten(1)
                        ->unique(fn (array $row): string => $row['side'].':'.$row['entity_sentence_id'])
                        ->filter(fn (array $row): bool => ! isset($reserved[$row['entity_sentence_id']]))
                        ->sortBy(fn (array $row): int => $row['side'] === 'a' ? $row['a_order'] : $row['b_order'])
                        ->values()
                        ->map(fn (array $row) => [
                            'entity_match_id' => $entityMatch->id,
                            'entity_sentence_id' => $row['entity_sentence_id'],
                            'meaning_match_id' => null, // filled once the row exists
                            'side' => $row['side'],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ])
                        ->all();

                    if ($rows === []) {
                        // Every sentence of the window already belongs to a
                        // landmark row — storing an empty meaning match would
                        // only add noise.
                        continue;
                    }

                    $meaningMatch = MeaningMatch::create([
                        'entity_match_id' => $entityMatch->id,
                        'order' => $order,
                        'similarity' => $similarity,
                        'alignment_chunk' => $alignmentChunk,
                    ]);

                    foreach ($rows as &$row) {
                        $row['meaning_match_id'] = $meaningMatch->id;
                    }
                    unset($row);

                    foreach (array_chunk($rows, 500) as $chunk) {
                        SentenceMeaningMatch::insert($chunk);
                    }

                    continue;
                }

                $sentenceId = $step['type'] === 'skip_a'
                    ? ($step['a_sentence_id'] ?? null)
                    : ($step['b_sentence_id'] ?? null);

                if ($sentenceId === null || isset($reserved[$sentenceId])) {
                    continue;
                }

                $meaningMatch = MeaningMatch::create([
                    'entity_match_id' => $entityMatch->id,
                    'order' => $order,
                    'similarity' => $similarity,
                    'alignment_chunk' => $alignmentChunk,
                ]);

                SentenceMeaningMatch::create([
                    'entity_match_id' => $entityMatch->id,
                    'entity_sentence_id' => $sentenceId,
                    'meaning_match_id' => $meaningMatch->id,
                    'side' => $step['type'] === 'skip_a' ? 'a' : 'b',
                ]);
            }

            $entityMatch->syncLinkedCount();

            // Resequencing happens once at finalize() — a per-chunk pass here
            // re-read both entities' full sentence lists after every window,
            // making the whole pipeline quadratic (ADR 0043).
        });
    }
}
