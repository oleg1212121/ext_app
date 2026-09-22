<?php

namespace App\Classes;

use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class SentenceAlignmentService
{
    private const VERIFY_THRESHOLD = 0.70;

    private const RETRY_DELAYS_MS = [500, 1_500, 3_000];

    /**
     * Similarity at or above which an auto-aligned row is a landmark: pinned
     * against re-alignment, never deleted by the pipeline. Mirrored by
     * AlignEntitySentences::LANDMARK_THRESHOLD.
     */
    public const LANDMARK_THRESHOLD = 0.90;

    public function __construct(
        private readonly string $apiUrl,
        private readonly int $timeout,
        private readonly ?int $alignTimeout = null,
    ) {}

    public static function create(): self
    {
        return new self(
            apiUrl: config('services.python.url', 'http://ext_python:8000'),
            timeout: (int) config('services.python.timeout', 30),
            alignTimeout: (int) config('services.python.align_timeout', 300),
        );
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

        $response = Http::timeout($this->alignTimeout ?? $this->timeout)
            ->retry(
                self::RETRY_DELAYS_MS,
                0,
                fn (Throwable $exception, PendingRequest $request): bool => $exception instanceof ConnectionException,
                false,
            )
            ->post("{$this->apiUrl}/align", $payload);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Python alignment service error: {$response->status()} - {$response->body()}"
            );
        }

        $matches = [];

        foreach ($response->json('matches', []) as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $matches[] = [
                'a_start' => (int) ($raw['a_start'] ?? 0),
                'a_end' => (int) ($raw['a_end'] ?? 0),
                'b_start' => (int) ($raw['b_start'] ?? 0),
                'b_end' => (int) ($raw['b_end'] ?? 0),
                'score' => (float) ($raw['score'] ?? 0.0),
            ];
        }

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
     * Machine rows whose every junction is also held by a higher-priority row
     * (one sentence junctioned into several rows — the signature of a re-fed
     * window) are deleted first: two rows claiming the same sentence can
     * never both sit in document order. Partial overlaps of multi-sentence
     * rows are legitimate n:m matches and stay.
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
     * @return int The number of rows deleted or whose order changed
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

        $victimIds = $this->subsumedDuplicateRowIds($rows);

        if ($victimIds !== []) {
            $rows = $rows->reject(fn ($row) => in_array($row->id, $victimIds))->values();
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

        if ($changes === [] && $victimIds === []) {
            return 0;
        }

        DB::transaction(function () use ($changes, $victimIds): void {
            // Junctions cascade via FK, so deleting a subsumed row removes
            // exactly its duplicated sentence claims.
            if ($victimIds !== []) {
                MeaningMatch::query()->whereKey($victimIds)->delete();
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

        return count($changes) + count($victimIds);
    }

    /**
     * Ids of machine rows whose every junction is also held by a
     * higher-priority row of the same entity match — the
     * one-sentence-junctioned-into-two-rows signature of a re-fed window.
     * Priority: landmark/human rows (chunk -1 or at/above the landmark bar)
     * over machine rows, then two-sided over one-sided, richer rows, higher
     * similarity, lower order, lower id. Landmark and human rows are never
     * victims; a row keeping at least one junction of its own stays (partial
     * overlaps are legitimate n:m matches).
     *
     * @param  Collection<int, MeaningMatch>  $rows
     * @return list<int>
     */
    private function subsumedDuplicateRowIds(Collection $rows): array
    {
        $priorities = [];

        foreach ($rows as $row) {
            $sides = $row->sentenceMeaningMatches->pluck('side');

            $priorities[$row->id] = [
                $row->alignment_chunk === -1
                    || (float) $row->similarity >= self::LANDMARK_THRESHOLD ? 1 : 0,
                $sides->contains('a') && $sides->contains('b') ? 1 : 0,
                $row->sentenceMeaningMatches->count(),
                (float) $row->similarity,
                -$row->order,
                -$row->id,
            ];
        }

        $keeperOf = [];

        foreach ($rows as $row) {
            foreach ($row->sentenceMeaningMatches as $junction) {
                $key = $junction->side.':'.$junction->entity_sentence_id;
                $incumbentId = $keeperOf[$key] ?? null;

                if ($incumbentId === null
                    || $priorities[$row->id] > $priorities[$incumbentId]) {
                    $keeperOf[$key] = $row->id;
                }
            }
        }

        return $rows
            ->filter(fn (MeaningMatch $row): bool => $priorities[$row->id][0] === 0
                && $row->sentenceMeaningMatches->isNotEmpty()
                && $row->sentenceMeaningMatches->every(
                    fn ($junction) => ($keeperOf[$junction->side.':'.$junction->entity_sentence_id] ?? null) !== $row->id,
                ))
            ->pluck('id')
            ->values()
            ->all();
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
                    ->where('alignment_chunk', '!=', -1)
                    ->where('similarity', '<', self::LANDMARK_THRESHOLD)
                    ->whereHas('sentenceMeaningMatches', fn ($query) => $query
                        ->whereIn('entity_sentence_id', $incomingSentenceIds))
                    ->delete();
            }

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

                $meaningMatch = MeaningMatch::create([
                    'entity_match_id' => $entityMatch->id,
                    'order' => $order,
                    'similarity' => $similarity,
                    'alignment_chunk' => $alignmentChunk,
                ]);

                if ($step['type'] === 'match') {
                    $rows = $stepLinks
                        ->map(fn (array $link) => [
                            ['entity_sentence_id' => $link['a_sentence_id'], 'side' => 'a', 'a_order' => $link['a_order']],
                            ['entity_sentence_id' => $link['b_sentence_id'], 'side' => 'b', 'b_order' => $link['b_order']],
                        ])
                        ->flatten(1)
                        ->unique(fn (array $row): string => $row['side'].':'.$row['entity_sentence_id'])
                        ->sortBy(fn (array $row): int => $row['side'] === 'a' ? $row['a_order'] : $row['b_order'])
                        ->values()
                        ->map(fn (array $row) => [
                            'entity_sentence_id' => $row['entity_sentence_id'],
                            'meaning_match_id' => $meaningMatch->id,
                            'side' => $row['side'],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ])
                        ->all();

                    foreach (array_chunk($rows, 500) as $chunk) {
                        SentenceMeaningMatch::insert($chunk);
                    }

                    continue;
                }

                if ($step['type'] === 'skip_a' && ! empty($step['a_sentence_id'])) {
                    SentenceMeaningMatch::create([
                        'entity_sentence_id' => $step['a_sentence_id'],
                        'meaning_match_id' => $meaningMatch->id,
                        'side' => 'a',
                    ]);

                    continue;
                }

                if ($step['type'] === 'skip_b' && ! empty($step['b_sentence_id'])) {
                    SentenceMeaningMatch::create([
                        'entity_sentence_id' => $step['b_sentence_id'],
                        'meaning_match_id' => $meaningMatch->id,
                        'side' => 'b',
                    ]);
                }
            }

            $entityMatch->update([
                'linked_count' => $this->countLinkedPairs($entityMatch->id),
            ]);

            // Rows land append-after-max, which misplaces re-align pool rows
            // between surviving landmarks; renormalize in the same transaction
            // so mid-run state is already in document order.
            $this->resequenceMatchesByDocumentPosition($entityMatch);
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

    private function countLinkedPairs(int $entityMatchId): int
    {
        return (int) MeaningMatch::query()
            ->where('entity_match_id', $entityMatchId)
            ->count();
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
