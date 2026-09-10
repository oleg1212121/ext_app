<?php

namespace App\Classes;

use App\Models\Entity;
use App\Models\EntityMatch;
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
