<?php

namespace App\Classes;

use App\Models\Entity;
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

    public function generateSignature(string $text, string $languageCode = 'en'): ?array
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
                'language' => $languageCode,
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
     * Entities (in any language, including the entity's own — e.g. an
     * exercises/answers pair) whose signature is similar enough to the given
     * entity's — candidates for the same work.
     *
     * @return Collection<int, array{entity: Entity, similarity: float}>
     */
    public function findCrossLanguage(Entity $entity): Collection
    {
        $signature = json_decode($entity->signature, true);
        if (! is_array($signature)) {
            return new Collection;
        }

        $similar = new Collection;

        foreach (Entity::query()
            ->where('id', '!=', $entity->id)
            ->whereNotNull('signature')
            ->with('language')
            ->select(['id', 'name', 'language_id', 'work_id', 'signature'])
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
