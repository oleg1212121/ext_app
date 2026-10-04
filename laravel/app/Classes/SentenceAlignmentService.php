<?php

namespace App\Classes;

use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SentenceAlignmentService
{
    private const VERIFY_THRESHOLD = 0.70;

    /**
     * Similarity at or above which an auto-aligned row is a landmark: pinned
     * against re-alignment, never deleted by the pipeline. Mirrored by
     * AlignEntitySentences::LANDMARK_THRESHOLD.
     */
    public const LANDMARK_THRESHOLD = 0.90;

    public function __construct(private readonly PythonClient $python) {}

    public static function create(): self
    {
        return new self(PythonClient::create());
    }

    /**
     * Verify that two entities are translations of the same text.
     */
    public function verifyEntityPair(Entity $aEntity, Entity $bEntity): array
    {
        $aSignature = json_decode($aEntity->signature, true);
        $bSignature = json_decode($bEntity->signature, true);

        if (! is_array($aSignature) || ! is_array($bSignature)) {
            return ['similarity' => 0.0, 'passed' => false, 'message' => 'Missing entity signatures'];
        }

        $similarity = $this->cosineSimilarity($aSignature, $bSignature);
        $passed = $similarity >= self::VERIFY_THRESHOLD;

        return [
            'similarity' => round($similarity, 4),
            'passed' => $passed,
            'message' => $passed
                ? "Entity signatures match (score: {$similarity})"
                : "Entity signatures too different (score: {$similarity}, threshold: ".self::VERIFY_THRESHOLD.')',
        ];
    }

    /**
     * Align a chunk of sentences via the python service and adapt the result
     * into links + dpPath steps for storeAlignmentSegment(). The raw python
     * matches are returned too so the caller can trim to the last confident
     * anchor before persisting (see AlignEntitySentences).
     *
     * Optional landmarks (hard human-made pins) and a high-confidence prepass
     * bar are passed straight through to the python service.
     *
     * @param  list<array{a_start: int, a_end: int, b_start: int, b_end: int}>  $landmarks
     * @return array{links: array, dpPath: array, matches: array}
     */
    public function alignChunkRemote(
        Collection $aSentences,
        Collection $bSentences,
        int $maxN = 3,
        array $landmarks = [],
        ?float $highConfidence = null,
    ): array {
        $aIds = $aSentences->pluck('id')->values()->all();
        $bIds = $bSentences->pluck('id')->values()->all();

        if (count($aIds) === 0) {
            return [
                'links' => [],
                'dpPath' => $this->buildSkipOnlyPath('skip_b', $bIds),
                'matches' => [],
            ];
        }

        if (count($bIds) === 0) {
            return [
                'links' => [],
                'dpPath' => $this->buildSkipOnlyPath('skip_a', $aIds),
                'matches' => [],
            ];
        }

        $payload = [
            'a_sentences' => $aSentences->pluck('content')->map(fn ($c) => (string) $c)->values()->all(),
            'b_sentences' => $bSentences->pluck('content')->map(fn ($c) => (string) $c)->values()->all(),
            'max_window' => max(1, $maxN),
        ];

        if ($landmarks !== []) {
            $payload['landmarks'] = $landmarks;
        }

        if ($highConfidence !== null) {
            $payload['high_confidence'] = $highConfidence;
        }

        $matches = $this->python->align($payload);

        $adapted = $this->adaptMatches($matches, $aSentences, $bSentences);

        return [...$adapted, 'matches' => $matches];
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

            foreach ($changes as $change) {
                MeaningMatch::query()
                    ->whereKey($change['id'])
                    ->update(['order' => -($change['id'] + 1_000_000_000)]);
            }

            foreach ($changes as $change) {
                MeaningMatch::query()
                    ->whereKey($change['id'])
                    ->update(['order' => $change['order']]);
            }
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
                    || (float) $row->similarity >= self::LANDMARK_THRESHOLD ? 1 : 0,
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
     * Convert python alignment matches (index spans) into links + dpPath steps.
     * Unmatched sentences (gaps between/around matches) become skip steps.
     *
     * @param  list<array{a_start: int, a_end: int, b_start: int, b_end: int, score: float}>  $matches
     * @return array{links: array, dpPath: array}
     */
    private function adaptMatches(array $matches, Collection $aSentences, Collection $bSentences): array
    {
        return $this->buildCommittedPath(
            $matches,
            $aSentences,
            $bSentences,
            $aSentences->count(),
            $bSentences->count(),
        );
    }

    /**
     * Build links + dpPath for a committed prefix of python matches. Skip
     * steps are only emitted up to the last committed match's end indices
     * (or an explicit stop), so sentences after the commit boundary are left
     * untouched — they are re-aligned with fresh context in the next chunk.
     *
     * @param  list<array{a_start: int, a_end: int, b_start: int, b_end: int, score: float}>  $committedMatches
     * @return array{links: array, dpPath: array}
     */
    private function buildCommittedPath(
        array $committedMatches,
        Collection $aSentences,
        Collection $bSentences,
        ?int $aStop = null,
        ?int $bStop = null,
    ): array {
        $aIds = $aSentences->pluck('id')->values()->all();
        $bIds = $bSentences->pluck('id')->values()->all();
        $aOrders = $aSentences->pluck('order', 'id')->toArray();
        $bOrders = $bSentences->pluck('order', 'id')->toArray();

        $lastCommitted = $committedMatches[array_key_last($committedMatches)] ?? null;
        $aStop ??= (int) ($lastCommitted['a_end'] ?? 0);
        $bStop ??= (int) ($lastCommitted['b_end'] ?? 0);

        $steps = [];
        $i = 0;
        $j = 0;

        foreach ($committedMatches as $match) {
            $aStart = (int) $match['a_start'];
            $aEnd = (int) $match['a_end'];
            $bStart = (int) $match['b_start'];
            $bEnd = (int) $match['b_end'];

            while ($i < $aStart) {
                $steps[] = ['type' => 'skip_a', 'index' => $i];
                $i++;
            }
            while ($j < $bStart) {
                $steps[] = ['type' => 'skip_b', 'index' => $j];
                $j++;
            }

            $steps[] = [
                'type' => 'match',
                'a_start' => $aStart,
                'a_end' => $aEnd,
                'b_start' => $bStart,
                'b_end' => $bEnd,
                'score' => (float) ($match['score'] ?? 0.0),
            ];

            $i = $aEnd;
            $j = $bEnd;
        }

        while ($i < $aStop) {
            $steps[] = ['type' => 'skip_a', 'index' => $i];
            $i++;
        }
        while ($j < $bStop) {
            $steps[] = ['type' => 'skip_b', 'index' => $j];
            $j++;
        }

        $links = [];
        $dpPath = [];
        $linkGroup = 0;

        foreach ($steps as $alignmentOrder => $step) {
            if ($step['type'] === 'match') {
                $linkGroup++;

                for ($ai = $step['a_start']; $ai < $step['a_end']; $ai++) {
                    for ($bj = $step['b_start']; $bj < $step['b_end']; $bj++) {
                        if (! isset($aIds[$ai]) || ! isset($bIds[$bj])) {
                            continue;
                        }

                        $links[] = [
                            'a_sentence_id' => $aIds[$ai],
                            'b_sentence_id' => $bIds[$bj],
                            'a_order' => $aOrders[$aIds[$ai]],
                            'b_order' => $bOrders[$bIds[$bj]],
                            'link_group' => $linkGroup,
                            'similarity' => round($step['score'], 4),
                            'alignment_order' => $alignmentOrder,
                        ];
                    }
                }

                $dpPath[] = ['type' => 'match', 'alignment_order' => $alignmentOrder];
            } elseif ($step['type'] === 'skip_a') {
                $dpPath[] = [
                    'type' => 'skip_a',
                    'a_sentence_id' => $aIds[$step['index']] ?? null,
                    'alignment_order' => $alignmentOrder,
                ];
            } else {
                $dpPath[] = [
                    'type' => 'skip_b',
                    'b_sentence_id' => $bIds[$step['index']] ?? null,
                    'alignment_order' => $alignmentOrder,
                ];
            }
        }

        return [
            'links' => $links,
            'dpPath' => $dpPath,
        ];
    }

    /**
     * Store alignment meaning matches for a single chunk.
     */
    public function storeAlignmentSegment(
        EntityMatch $entityMatch,
        int $alignmentChunk,
        array $links,
        array $dpPathSegment,
    ): void {
        $this->persistSegment($entityMatch, $alignmentChunk, $links, $dpPathSegment);
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
            ? $this->buildCommittedPath($committedMatches, $aSentences, $bSentences, $aSentences->count(), $bSentences->count())
            : $this->buildCommittedPath($committedMatches, $aSentences, $bSentences);

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
            $this->buildSkipOnlyPath($type, $sentences->pluck('id')->values()->all()),
        );
    }

    /**
     * The junction-less sentences of one side in document order, plus the
     * sentence-id => document-index map that anchors their position among the
     * meaning matches.
     *
     * @param  'a'|'b'  $side
     * @return array{0: Collection<int, EntitySentence>, 1: array<int, int>}
     */
    public function junctionlessSentencesFor(EntityMatch $entityMatch, string $side): array
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
     * by the caller): runs on opposite sides between the same anchors compute
     * identical spread values, and without the claim check the second insert
     * would violate unique(entity_match_id, order) and roll the whole side's
     * repair back. Claimed orders are nudged past collisions, which keeps the
     * relative order — the resequence pass normalizes the values afterwards.
     *
     * @param  'a'|'b'  $side
     * @param  Collection<int, EntitySentence>  $junctionless
     * @param  array<int, int>  $index
     * @param  array<int, true>  $claimedOrders
     * @return int The number of single-sided rows created
     */
    public function repairJunctionlessSentences(
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
                    ->where('similarity', '<', self::LANDMARK_THRESHOLD)
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
                        ->orWhere('similarity', '>=', self::LANDMARK_THRESHOLD)))
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

    /**
     * Store alignment links and update the entity match.
     */
    public function storeLinks(
        EntityMatch $entityMatch,
        array $links,
        array $dpPathSegment,
    ): void {
        $this->storeAlignmentSegment($entityMatch, 0, $links, $dpPathSegment);
    }

    /**
     * @param  list<int>  $sentenceIds
     */
    private function buildSkipOnlyPath(string $type, array $sentenceIds): array
    {
        $path = [];

        foreach (array_values($sentenceIds) as $alignmentOrder => $sentenceId) {
            $path[] = [
                'type' => $type,
                ($type === 'skip_a' ? 'a_sentence_id' : 'b_sentence_id') => $sentenceId,
                'alignment_order' => $alignmentOrder,
            ];
        }

        return $path;
    }

    /**
     * Cosine similarity between two vectors.
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        return $this->dotProduct($a, $b);
    }

    /**
     * Dot product of two vectors (optimized for L2-normalized vectors).
     */
    private function dotProduct(array $a, array $b): float
    {
        $dot = 0.0;
        $count = min(count($a), count($b));

        for ($i = 0; $i < $count; $i++) {
            $dot += $a[$i] * $b[$i];
        }

        return $dot;
    }
}
