<?php

namespace App\Classes;

use App\Models\EnEntity;
use App\Models\EnRuEntityMatch;
use App\Models\EnRuMeaningMatch;
use App\Models\EnSentenceMeaningMatch;
use App\Models\RuEntity;
use App\Models\RuSentenceMeaningMatch;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class SentenceAlignmentService
{
    private const VERIFY_THRESHOLD = 0.70;

    private const GAP_PENALTY = 0.08;

    private const MATCH_PENALTY = 0.35;

    private const EXTRA_SENTENCE_PENALTY = 0.02;

    private const IMBALANCE_PENALTY = 0.45;

    private const LOW_CONFIDENCE_THRESHOLD = 0.55;

    private const LOW_CONFIDENCE_PENALTY = 1.2;

    private const RETRY_DELAYS_MS = [500, 1_500, 3_000];

    private const DEFAULT_ALIGNMENT_BATCH_SIZE = 25;

    private const DEFAULT_ALIGNMENT_SENTENCE_SAMPLE_CHARS = 4_000;

    private const ALIGNMENT_SAMPLE_SEPARATOR = "\n\n...\n\n";

    public function __construct(
        private readonly string $apiUrl,
        private readonly int $timeout,
    ) {}

    public static function create(): self
    {
        return new self(
            apiUrl: config('services.embedding.url', 'http://ext_embedding:8000'),
            timeout: config('services.embedding.timeout', 30),
        );
    }

    /**
     * Verify that two entities are translations of the same text.
     */
    public function verifyEntityPair(EnEntity $enEntity, RuEntity $ruEntity): array
    {
        $enSignature = json_decode($enEntity->signature, true);
        $ruSignature = json_decode($ruEntity->signature, true);

        if (! is_array($enSignature) || ! is_array($ruSignature)) {
            return ['similarity' => 0.0, 'passed' => false, 'message' => 'Missing entity signatures'];
        }

        $similarity = $this->cosineSimilarity($enSignature, $ruSignature);
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
     * Batch embed sentences using the embedding service.
     *
     * @return array<int, array> sentenceId => vector
     */
    public function batchEmbed(Collection $sentences): array
    {
        $embeddings = [];
        $batchSize = max(1, min(
            100,
            (int) config('services.embedding.alignment_batch_size', self::DEFAULT_ALIGNMENT_BATCH_SIZE),
        ));
        $batches = $sentences->chunk($batchSize);

        foreach ($batches as $batch) {
            $texts = $batch
                ->pluck('content')
                ->map(fn ($content) => $this->textSampleForAlignmentEmbedding((string) $content))
                ->toArray();

            $response = Http::timeout($this->timeout)
                ->retry(
                    self::RETRY_DELAYS_MS,
                    0,
                    fn (Throwable $exception, PendingRequest $request): bool => $exception instanceof ConnectionException,
                    false,
                )
                ->post("{$this->apiUrl}/embed/batch", [
                    'texts' => $texts,
                ]);

            if (! $response->successful()) {
                throw new \RuntimeException(
                    "Embedding service error: {$response->status()} - {$response->body()}"
                );
            }

            $vectors = $response->json('vectors', []);

            foreach ($batch->values() as $index => $sentence) {
                $embeddings[$sentence->id] = $vectors[$index] ?? null;
            }
        }

        return array_filter($embeddings, fn ($v) => $v !== null);
    }

    /**
     * Build individual and contiguous-span similarity matrices for alignment.
     *
     * @return array{individual: array<int, array<int, float>>, groups: array<string, float>}
     */
    public function buildAlignmentSimilarityMatrices(Collection $enSentences, Collection $ruSentences, int $maxN): array
    {
        $maxSpan = max(1, $maxN);
        $enIds = $enSentences->pluck('id')->values()->all();
        $ruIds = $ruSentences->pluck('id')->values()->all();
        $enEmbeddings = $this->batchEmbed($enSentences);
        $ruEmbeddings = $this->batchEmbed($ruSentences);
        $enSpans = $this->buildContiguousSpanVectors($enIds, $enEmbeddings, $maxSpan);
        $ruSpans = $this->buildContiguousSpanVectors($ruIds, $ruEmbeddings, $maxSpan);

        $individual = [];
        $groups = [];

        foreach ($enSpans as $enSpan) {
            $enVector = $enSpan['vector'];
            if (! $enVector) {
                continue;
            }

            foreach ($ruSpans as $ruSpan) {
                $ruVector = $ruSpan['vector'];
                if (! $ruVector) {
                    continue;
                }

                $similarity = $this->dotProduct($enVector, $ruVector);
                $groups[$this->groupSimilarityKey(
                    $enSpan['start'],
                    $ruSpan['start'],
                    $enSpan['length'],
                    $ruSpan['length'],
                )] = $similarity;

                if ($enSpan['length'] === 1 && $ruSpan['length'] === 1) {
                    $individual[$enSpan['start']][$ruSpan['start']] = $similarity;
                }
            }
        }

        return [
            'individual' => $individual,
            'groups' => $groups,
        ];
    }

    /**
     * @param  list<int>  $sentenceIds
     * @param  array<int, array>  $embeddings
     * @return list<array{start: int, length: int, vector: array}>
     */
    private function buildContiguousSpanVectors(array $sentenceIds, array $embeddings, int $maxSpan): array
    {
        $spans = [];
        $count = count($sentenceIds);

        for ($start = 0; $start < $count; $start++) {
            $vectors = [];
            $remaining = min($maxSpan, $count - $start);

            for ($length = 1; $length <= $remaining; $length++) {
                $sentenceId = $sentenceIds[$start + $length - 1];
                $vector = $embeddings[$sentenceId] ?? null;

                if (! $vector) {
                    break;
                }

                $vectors[] = $vector;
                $spans[] = [
                    'start' => $start,
                    'length' => $length,
                    'vector' => $this->averageNormalizedVectors($vectors),
                ];
            }
        }

        return $spans;
    }

    /**
     * @param  list<array>  $vectors
     * @return array
     */
    private function averageNormalizedVectors(array $vectors): array
    {
        $sum = [];

        foreach ($vectors as $vector) {
            foreach ($vector as $index => $value) {
                $sum[$index] = ($sum[$index] ?? 0.0) + (float) $value;
            }
        }

        $count = max(count($vectors), 1);

        foreach ($sum as $index => $value) {
            $sum[$index] = $value / $count;
        }

        return $this->normalizeVector($sum);
    }

    /**
     * @return array
     */
    private function normalizeVector(array $vector): array
    {
        $norm = 0.0;

        foreach ($vector as $value) {
            $norm += ((float) $value) ** 2;
        }

        $norm = sqrt($norm);

        if ($norm < 1e-15) {
            return $vector;
        }

        return array_map(fn ($value): float => (float) $value / $norm, $vector);
    }

    private function textSampleForAlignmentEmbedding(string $text): string
    {
        $encoding = 'UTF-8';
        $maxChars = max(
            1,
            (int) config('services.embedding.alignment_sentence_max_chars', self::DEFAULT_ALIGNMENT_SENTENCE_SAMPLE_CHARS),
        );
        $length = mb_strlen($text, $encoding);

        if ($length <= $maxChars) {
            return $text;
        }

        $headChars = intdiv($maxChars, 2);
        $tailChars = $maxChars - $headChars;
        $head = mb_substr($text, 0, $headChars, $encoding);
        $tail = mb_substr($text, -$tailChars, null, $encoding);

        return $head.self::ALIGNMENT_SAMPLE_SEPARATOR.$tail;
    }

    /**
     * Build a similarity matrix from two sets of embeddings.
     * Since vectors are L2-normalized, cosine similarity = dot product.
     *
     * @return array<int, array<int, float>>
     */
    public function buildSimilarityMatrix(array $enEmbeddings, array $ruEmbeddings, array $enIds, array $ruIds): array
    {
        $matrix = [];

        foreach ($enIds as $i => $enId) {
            $enVec = $enEmbeddings[$enId] ?? null;
            if (! $enVec) {
                continue;
            }

            foreach ($ruIds as $j => $ruId) {
                $ruVec = $ruEmbeddings[$ruId] ?? null;
                if (! $ruVec) {
                    continue;
                }

                $matrix[$i][$j] = $this->dotProduct($enVec, $ruVec);
            }
        }

        return $matrix;
    }

    /**
     * Run DP alignment on a chunk of sentences.
     *
     * @return array{links: array, dpPath: array}
     */
    public function alignChunk(
        Collection $enSentences,
        Collection $ruSentences,
        array $similarityMatrix,
        int $maxN = 3,
        array $groupSimilarityMatrix = [],
    ): array {
        $n = $enSentences->count();
        $m = $ruSentences->count();

        $enIds = $enSentences->pluck('id')->toArray();
        $ruIds = $ruSentences->pluck('id')->toArray();
        $enOrders = $enSentences->pluck('order', 'id')->toArray();
        $ruOrders = $ruSentences->pluck('order', 'id')->toArray();

        if ($n === 0) {
            return [
                'links' => [],
                'dpPath' => $this->buildSkipOnlyPath('skip_ru', $ruIds),
            ];
        }

        if ($m === 0) {
            return [
                'links' => [],
                'dpPath' => $this->buildSkipOnlyPath('skip_en', $enIds),
            ];
        }

        $maxSpan = max(1, $maxN);
        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, -INF));
        $trace = array_fill(0, $n + 1, array_fill(0, $m + 1, null));
        $dp[0][0] = 0.0;

        for ($i = 0; $i <= $n; $i++) {
            for ($j = 0; $j <= $m; $j++) {
                if ($dp[$i][$j] === -INF) {
                    continue;
                }

                if ($i < $n) {
                    $targetI = $i + 1;
                    $score = $dp[$i][$j] - self::GAP_PENALTY;
                    $candidateTrace = ['type' => 'skip_en', 'pi' => $i, 'pj' => $j, 'span_i' => 1, 'span_j' => 0];

                    if ($this->shouldReplaceTrace($score, $dp[$targetI][$j], $candidateTrace, $trace[$targetI][$j])) {
                        $dp[$targetI][$j] = $score;
                        $trace[$targetI][$j] = $candidateTrace;
                    }
                }

                if ($j < $m) {
                    $targetJ = $j + 1;
                    $score = $dp[$i][$j] - self::GAP_PENALTY;
                    $candidateTrace = ['type' => 'skip_ru', 'pi' => $i, 'pj' => $j, 'span_i' => 0, 'span_j' => 1];

                    if ($this->shouldReplaceTrace($score, $dp[$i][$targetJ], $candidateTrace, $trace[$i][$targetJ])) {
                        $dp[$i][$targetJ] = $score;
                        $trace[$i][$targetJ] = $candidateTrace;
                    }
                }

                $maxEnSpan = min($maxSpan, $n - $i);
                $maxRuSpan = min($maxSpan, $m - $j);

                for ($spanI = 1; $spanI <= $maxEnSpan; $spanI++) {
                    for ($spanJ = 1; $spanJ <= $maxRuSpan; $spanJ++) {
                        $similarity = $this->spanSimilarity(
                            $similarityMatrix,
                            $groupSimilarityMatrix,
                            $i,
                            $j,
                            $spanI,
                            $spanJ,
                        );
                        $score = $dp[$i][$j] + $this->alignmentStepScore($similarity, $spanI, $spanJ);
                        $targetI = $i + $spanI;
                        $targetJ = $j + $spanJ;
                        $candidateTrace = ['type' => 'match', 'pi' => $i, 'pj' => $j, 'span_i' => $spanI, 'span_j' => $spanJ];

                        if ($this->shouldReplaceTrace($score, $dp[$targetI][$targetJ], $candidateTrace, $trace[$targetI][$targetJ])) {
                            $dp[$targetI][$targetJ] = $score;
                            $trace[$targetI][$targetJ] = $candidateTrace;
                        }
                    }
                }
            }
        }

        $path = [];
        $i = $n;
        $j = $m;

        while (($i > 0 || $j > 0) && $trace[$i][$j] !== null) {
            $t = $trace[$i][$j];
            $path[] = [
                'type' => $t['type'],
                'i' => $t['pi'],
                'j' => $t['pj'],
                'span_i' => $t['span_i'],
                'span_j' => $t['span_j'],
            ];
            $i = $t['pi'];
            $j = $t['pj'];
        }

        // Handle remaining sentences if path ended early
        while ($i > 0) {
            $i--;
            $path[] = ['type' => 'skip_en', 'i' => $i, 'j' => -1, 'span_i' => 1, 'span_j' => 0];
        }
        while ($j > 0) {
            $j--;
            $path[] = ['type' => 'skip_ru', 'i' => -1, 'j' => $j, 'span_i' => 0, 'span_j' => 1];
        }

        $path = array_reverse($path);

        // Convert path to links and ordered step entries
        $links = [];
        $dpPathEntries = [];
        $alignmentOrder = 0;
        $linkGroup = 0;

        foreach ($path as $step) {
            if ($step['type'] === 'match') {
                $linkGroup++;
                $si = $step['i'];
                $sj = $step['j'];
                $spanI = $step['span_i'];
                $spanJ = $step['span_j'];

                // Create one link per (EN, RU) pair in this alignment group
                for ($di = 0; $di < $spanI; $di++) {
                    for ($dj = 0; $dj < $spanJ; $dj++) {
                        $ei = $si + $di;
                        $rj = $sj + $dj;

                        if ($ei < 0 || $rj < 0 || ! isset($enIds[$ei]) || ! isset($ruIds[$rj])) {
                            continue;
                        }

                        $sim = $similarityMatrix[$ei][$rj] ?? 0.0;
                        $links[] = [
                            'en_entity_sentence_id' => $enIds[$ei],
                            'ru_entity_sentence_id' => $ruIds[$rj],
                            'en_order' => $enOrders[$enIds[$ei]],
                            'ru_order' => $ruOrders[$ruIds[$rj]],
                            'link_group' => $linkGroup,
                            'similarity' => round($sim, 4),
                            'alignment_order' => $alignmentOrder,
                        ];
                    }
                }

                $dpPathEntries[] = ['type' => 'match', 'alignment_order' => $alignmentOrder];
            } elseif ($step['type'] === 'skip_en') {
                $dpPathEntries[] = [
                    'type' => 'skip_en',
                    'en_sentence_id' => $enIds[$step['i']] ?? null,
                    'alignment_order' => $alignmentOrder,
                ];
            } elseif ($step['type'] === 'skip_ru') {
                $dpPathEntries[] = [
                    'type' => 'skip_ru',
                    'ru_sentence_id' => $ruIds[$step['j']] ?? null,
                    'alignment_order' => $alignmentOrder,
                ];
            }

            $alignmentOrder++;
        }

        return [
            'links' => $links,
            'dpPath' => $dpPathEntries,
        ];
    }

    private function spanSimilarity(
        array $similarityMatrix,
        array $groupSimilarityMatrix,
        int $startI,
        int $startJ,
        int $spanI,
        int $spanJ,
    ): float {
        $groupSimilarity = $groupSimilarityMatrix[$this->groupSimilarityKey($startI, $startJ, $spanI, $spanJ)] ?? null;

        if ($groupSimilarity !== null) {
            return $groupSimilarity;
        }

        $sum = 0.0;
        $count = 0;

        for ($di = 0; $di < $spanI; $di++) {
            for ($dj = 0; $dj < $spanJ; $dj++) {
                $sum += $similarityMatrix[$startI + $di][$startJ + $dj] ?? 0.0;
                $count++;
            }
        }

        return $count === 0 ? 0.0 : $sum / $count;
    }

    private function alignmentStepScore(float $similarity, int $spanI, int $spanJ): float
    {
        $extraSentencePenalty = max(0, $spanI + $spanJ - 2) * self::EXTRA_SENTENCE_PENALTY;
        $imbalancePenalty = abs(log($spanI / $spanJ)) * self::IMBALANCE_PENALTY;
        $lowConfidencePenalty = $similarity < self::LOW_CONFIDENCE_THRESHOLD
            ? (self::LOW_CONFIDENCE_THRESHOLD - $similarity) * self::LOW_CONFIDENCE_PENALTY
            : 0.0;

        return $similarity
            - self::MATCH_PENALTY
            - $extraSentencePenalty
            - $imbalancePenalty
            - $lowConfidencePenalty;
    }

    private function shouldReplaceTrace(float $candidateScore, float $currentScore, array $candidateTrace, ?array $currentTrace): bool
    {
        if ($candidateScore > $currentScore + 0.000001) {
            return true;
        }

        if (abs($candidateScore - $currentScore) > 0.000001) {
            return false;
        }

        if ($currentTrace === null) {
            return true;
        }

        if ($candidateTrace['type'] === 'match' && $currentTrace['type'] !== 'match') {
            return true;
        }

        if ($candidateTrace['type'] !== $currentTrace['type']) {
            return false;
        }

        if ($candidateTrace['type'] === 'match') {
            $candidateImbalance = abs($candidateTrace['span_i'] - $candidateTrace['span_j']);
            $currentImbalance = abs($currentTrace['span_i'] - $currentTrace['span_j']);

            if ($candidateImbalance !== $currentImbalance) {
                return $candidateImbalance < $currentImbalance;
            }
        }

        return $this->traceSpanSize($candidateTrace) < $this->traceSpanSize($currentTrace);
    }

    private function traceSpanSize(array $trace): int
    {
        return $trace['span_i'] + $trace['span_j'];
    }

    private function groupSimilarityKey(int $startI, int $startJ, int $spanI, int $spanJ): string
    {
        return "{$startI}:{$startJ}:{$spanI}:{$spanJ}";
    }

    /**
     * Store alignment meaning matches for a single chunk.
     */
    public function storeAlignmentSegment(
        EnRuEntityMatch $entityMatch,
        int $alignmentChunk,
        array $links,
        array $dpPathSegment,
    ): void {
        DB::transaction(function () use ($entityMatch, $alignmentChunk, $links, $dpPathSegment) {
            $now = now();

            EnRuMeaningMatch::query()
                ->where('en_ru_entity_match_id', $entityMatch->id)
                ->where('alignment_chunk', $alignmentChunk)
                ->delete();

            $maxOrder = EnRuMeaningMatch::query()
                ->where('en_ru_entity_match_id', $entityMatch->id)
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

                $meaningMatch = EnRuMeaningMatch::create([
                    'en_ru_entity_match_id' => $entityMatch->id,
                    'order' => $order,
                    'similarity' => $similarity,
                    'alignment_chunk' => $alignmentChunk,
                ]);

                if ($step['type'] === 'match') {
                    $enRows = $stepLinks
                        ->unique('en_entity_sentence_id')
                        ->sortBy('en_order')
                        ->values()
                        ->map(fn (array $link, int $index) => [
                            'en_entity_sentence_id' => $link['en_entity_sentence_id'],
                            'en_ru_meaning_match_id' => $meaningMatch->id,
                            'order' => $index,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ])
                        ->all();

                    $ruRows = $stepLinks
                        ->unique('ru_entity_sentence_id')
                        ->sortBy('ru_order')
                        ->values()
                        ->map(fn (array $link, int $index) => [
                            'ru_entity_sentence_id' => $link['ru_entity_sentence_id'],
                            'en_ru_meaning_match_id' => $meaningMatch->id,
                            'order' => $index,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ])
                        ->all();

                    foreach (array_chunk($enRows, 500) as $chunk) {
                        EnSentenceMeaningMatch::insert($chunk);
                    }

                    foreach (array_chunk($ruRows, 500) as $chunk) {
                        RuSentenceMeaningMatch::insert($chunk);
                    }

                    continue;
                }

                if ($step['type'] === 'skip_en' && ! empty($step['en_sentence_id'])) {
                    EnSentenceMeaningMatch::create([
                        'en_entity_sentence_id' => $step['en_sentence_id'],
                        'en_ru_meaning_match_id' => $meaningMatch->id,
                        'order' => 0,
                    ]);

                    continue;
                }

                if ($step['type'] === 'skip_ru' && ! empty($step['ru_sentence_id'])) {
                    RuSentenceMeaningMatch::create([
                        'ru_entity_sentence_id' => $step['ru_sentence_id'],
                        'en_ru_meaning_match_id' => $meaningMatch->id,
                        'order' => 0,
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
        EnRuEntityMatch $entityMatch,
        array $links,
        array $dpPathSegment,
    ): void {
        $this->storeAlignmentSegment($entityMatch, 0, $links, $dpPathSegment);
    }

    private function countLinkedPairs(int $entityMatchId): int
    {
        return (int) DB::table('en_sentence_meaning_matches as esm')
            ->join('ru_sentence_meaning_matches as rsm', 'esm.en_ru_meaning_match_id', '=', 'rsm.en_ru_meaning_match_id')
            ->join('en_ru_meaning_matches as emm', 'emm.id', '=', 'esm.en_ru_meaning_match_id')
            ->where('emm.en_ru_entity_match_id', $entityMatchId)
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
                ($type === 'skip_en' ? 'en_sentence_id' : 'ru_sentence_id') => $sentenceId,
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
