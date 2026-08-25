<?php

namespace App\Classes;

use App\Models\EnEntity;
use App\Models\RuEntity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

class TextSignatureService
{
    private const SIMILARITY_THRESHOLD = 0.95;

    private const RETRY_DELAYS_MS = [500, 1_500, 3_000];

    /** UTF-8 character counts sent to the python service (head + tail when over the combined limit). */
    private const SIGNATURE_HEAD_CHARS = 10_000;

    private const SIGNATURE_TAIL_CHARS = 10_000;

    private const SIGNATURE_SAMPLE_SEPARATOR = "\n\n…\n\n";

    private const LANG_MODELS = [
        'en' => EnEntity::class,
        'ru' => RuEntity::class,
    ];

    public function __construct(
        private readonly string $apiUrl,
        private readonly int $timeout,
    ) {}

    public static function create(): self
    {
        return new self(
            apiUrl: config('services.python.url', 'http://ext_python:8000'),
            timeout: (int) config('services.python.timeout', 30),
        );
    }

    public static function readFileFromLocalPath(string $relativeFilePath): string
    {
        $fullPath = Storage::disk('local')->path($relativeFilePath);
        if (! file_exists($fullPath)) {
            throw new \RuntimeException("File not found: {$fullPath}");
        }

        $content = file_get_contents($fullPath);
        if ($content === false) {
            throw new \RuntimeException("Cannot read file: {$fullPath}");
        }

        return $content;
    }

    public function generateSignature(string $text): ?array
    {
        $textForEmbed = $this->textSampleForSignatureEmbedding($text);

        $response = Http::timeout($this->timeout)
            ->retry(
                self::RETRY_DELAYS_MS,
                0,
                fn (Throwable $exception, PendingRequest $request): bool => $exception instanceof ConnectionException,
                false,
            )
            ->post("{$this->apiUrl}/embed", [
                'text' => $textForEmbed,
            ]);

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();

        return $data['vector'] ?? null;
    }

    public function cosineSimilarity(array $a, array $b): float
    {
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        $count = count($a);
        for ($i = 0; $i < $count; $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $normA = sqrt($normA);
        $normB = sqrt($normB);

        if ($normA == 0.0 || $normB == 0.0) {
            return 0.0;
        }

        return $dotProduct / ($normA * $normB);
    }

    /**
     * Whether another entity in the same language already has a similar-enough signature.
     * Buffers rows and uses the python service for batched cosine similarity, with a PHP fallback.
     */
    public function hasSimilar(mixed $entity, ?string $lang = null): bool
    {
        $signature = json_decode($entity->signature, true);
        if (! is_array($signature)) {
            return false;
        }

        $dim = count($signature);
        if ($dim === 0) {
            return false;
        }

        $lang ??= $entity instanceof EnEntity ? 'en' : 'ru';
        $modelClass = self::LANG_MODELS[$lang];
        $batchSize = max(1, (int) config('services.python.has_similar_batch_size', 200));
        $buffer = [];

        foreach ($modelClass::query()
            ->whereNotNull('signature')
            ->where('id', '!=', $entity->id)
            ->orderBy('id')
            ->select(['id', 'signature'])
            ->cursor() as $other) {
            $otherSignature = json_decode($other->signature, true);
            if (! is_array($otherSignature) || count($otherSignature) !== $dim) {
                continue;
            }

            $buffer[] = $otherSignature;
            if (count($buffer) < $batchSize) {
                continue;
            }

            if ($this->candidatesExceedSimilarityThreshold($signature, $buffer)) {
                return true;
            }

            $buffer = [];
        }

        if ($buffer !== [] && $this->candidatesExceedSimilarityThreshold($signature, $buffer)) {
            return true;
        }

        return false;
    }

    /**
     * @param  list<float|int>  $query
     * @param  list<list<float|int>>  $candidates
     */
    private function candidatesExceedSimilarityThreshold(array $query, array $candidates): bool
    {
        $response = Http::timeout($this->timeout)
            ->post("{$this->apiUrl}/cosine/batch", [
                'query' => $query,
                'candidates' => $candidates,
            ]);

        if (! $response->successful()) {
            return $this->candidatesExceedThresholdPhp($query, $candidates);
        }

        $similarities = $response->json('similarities');
        if (! is_array($similarities) || count($similarities) !== count($candidates)) {
            return $this->candidatesExceedThresholdPhp($query, $candidates);
        }

        foreach ($similarities as $sim) {
            if (is_numeric($sim) && (float) $sim >= self::SIMILARITY_THRESHOLD) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<float|int>  $query
     * @param  list<list<float|int>>  $candidates
     */
    private function candidatesExceedThresholdPhp(array $query, array $candidates): bool
    {
        foreach ($candidates as $b) {
            if ($this->cosineSimilarity($query, $b) >= self::SIMILARITY_THRESHOLD) {
                return true;
            }
        }

        return false;
    }

    public function findCrossLanguage(mixed $entity): Collection
    {
        $signature = json_decode($entity->signature, true);
        if (! is_array($signature)) {
            return new Collection;
        }

        $isEnglish = $entity instanceof EnEntity;
        $otherLang = $isEnglish ? 'ru' : 'en';
        $modelClass = self::LANG_MODELS[$otherLang];

        $similar = new Collection;

        foreach ($modelClass::query()
            ->whereNotNull('signature')
            ->select(['id', 'name', 'signature'])
            ->cursor() as $other) {
            $otherSignature = json_decode($other->signature, true);
            if (! is_array($otherSignature)) {
                continue;
            }

            $similarity = $this->cosineSimilarity($signature, $otherSignature);

            if ($similarity >= self::SIMILARITY_THRESHOLD) {
                $similar->push([
                    'entity' => $other,
                    'similarity' => round($similarity, 4),
                ]);
            }
        }

        return $similar->sortByDesc('similarity');
    }

    /**
     * Generate a signature for the given text and look for an existing entity in
     * the same language that is a near-duplicate of it.
     *
     * Returns an array with three keys:
     *  - `entity`: the matching existing entity, or null when none is similar enough
     *  - `similarity`: the cosine similarity to the matched entity (0.0 when unmatched)
     *  - `signature`: the generated embedding, or null when the embedding service failed
     *
     * The caller uses `signature` to persist the embedding on a newly created
     * entity and `entity` to instead link the uploader to the existing one.
     *
     * @return array{entity: EnEntity|RuEntity|null, similarity: float, signature: array|null}
     */
    public function findSimilarExisting(string $text, string $lang): array
    {
        $signature = $this->generateSignature($text);

        if ($signature === null) {
            return ['entity' => null, 'similarity' => 0.0, 'signature' => null];
        }

        $match = $this->bestSimilarExisting($signature, $lang);

        return [
            'entity' => $match['entity'] ?? null,
            'similarity' => $match['similarity'] ?? 0.0,
            'signature' => $signature,
        ];
    }

    /**
     * Find the most similar existing entity to an already-persisted entity (used
     * by the defense-in-depth duplicate resolver in ProcessEntityFile).
     *
     * @return array{entity: EnEntity|RuEntity, similarity: float}|null
     */
    public function findSimilarToEntity(EnEntity|RuEntity $entity): ?array
    {
        $signature = json_decode($entity->signature, true);
        if (! is_array($signature)) {
            return null;
        }

        $lang = $entity instanceof EnEntity ? 'en' : 'ru';

        return $this->bestSimilarExisting($signature, $lang, $entity->getKey());
    }

    /**
     * Scan same-language entities with a signature for the closest match at or
     * above the similarity threshold, excluding a given entity id.
     *
     * @return array{entity: EnEntity|RuEntity, similarity: float}|null
     */
    private function bestSimilarExisting(array $signature, string $lang, ?int $excludeId = null): ?array
    {
        $dim = count($signature);
        if ($dim === 0) {
            return null;
        }

        $modelClass = self::LANG_MODELS[$lang];
        $batchSize = max(1, (int) config('services.python.has_similar_batch_size', 200));

        $candidates = [];
        $best = null;

        foreach ($modelClass::query()
            ->whereNotNull('signature')
            ->where('id', '!=', $excludeId ?? 0)
            ->select(['id', 'name', 'signature'])
            ->orderBy('id')
            ->cursor() as $other) {
            $otherSignature = json_decode($other->signature, true);
            if (! is_array($otherSignature) || count($otherSignature) !== $dim) {
                continue;
            }

            $candidates[] = ['entity' => $other, 'signature' => $otherSignature];

            if (count($candidates) < $batchSize) {
                continue;
            }

            $found = $this->bestMatchInBatch($signature, $candidates);
            if ($found !== null && ($best === null || $found['similarity'] > $best['similarity'])) {
                $best = $found;
            }

            $candidates = [];
        }

        if ($candidates !== [] && $best === null) {
            $found = $this->bestMatchInBatch($signature, $candidates);
            if ($found !== null) {
                $best = $found;
            }
        }

        return $best;
    }

    /**
     * @param  list<array{entity: EnEntity|RuEntity, signature: list<float|int>}>  $candidates
     * @return array{entity: EnEntity|RuEntity, similarity: float}|null
     */
    private function bestMatchInBatch(array $query, array $candidates): ?array
    {
        $signatures = array_map(fn (array $candidate): array => $candidate['signature'], $candidates);

        $response = Http::timeout($this->timeout)
            ->post("{$this->apiUrl}/cosine/batch", [
                'query' => $query,
                'candidates' => $signatures,
            ]);

        if ($response->successful()) {
            $similarities = $response->json('similarities');
            $valid = is_array($similarities) && count($similarities) === count($signatures);
        } else {
            $similarities = null;
            $valid = false;
        }

        if (! $valid) {
            $similarities = array_map(fn (array $candidate): float => $this->cosineSimilarity($query, $candidate['signature']), $candidates);
        }

        $best = null;
        foreach ($candidates as $index => $candidate) {
            $similarity = (float) ($similarities[$index] ?? 0);
            if ($similarity >= self::SIMILARITY_THRESHOLD
                && ($best === null || $similarity > $best['similarity'])) {
                $best = ['entity' => $candidate['entity'], 'similarity' => $similarity];
            }
        }

        return $best;
    }

    /**
     * Reduces embedding work for long texts while keeping start and end content.
     * Signatures produced before this sampling existed will not compare identically until regenerated.
     */
    private function textSampleForSignatureEmbedding(string $text): string
    {
        $encoding = 'UTF-8';
        $length = mb_strlen($text, $encoding);
        $combinedLimit = self::SIGNATURE_HEAD_CHARS + self::SIGNATURE_TAIL_CHARS;

        if ($length <= $combinedLimit) {
            return $text;
        }

        $head = mb_substr($text, 0, self::SIGNATURE_HEAD_CHARS, $encoding);
        $tail = mb_substr($text, -self::SIGNATURE_TAIL_CHARS, null, $encoding);

        return $head.self::SIGNATURE_SAMPLE_SEPARATOR.$tail;
    }
}
