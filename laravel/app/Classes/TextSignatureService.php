<?php

namespace App\Classes;

use App\Exceptions\PythonClientException;
use App\Models\Entity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;

class TextSignatureService
{
    private const SIMILARITY_THRESHOLD = 0.95;

    /** UTF-8 character counts sent to the python service (head + tail when over the combined limit). */
    private const SIGNATURE_HEAD_CHARS = 10_000;

    private const SIGNATURE_TAIL_CHARS = 10_000;

    private const SIGNATURE_SAMPLE_SEPARATOR = "\n\n…\n\n";

    public function __construct(private readonly PythonClient $python) {}

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

        try {
            return $this->python->embed($textForEmbed, $languageCode);
        } catch (PythonClientException) {
            // Best-effort: a signature failure must not block entity finalization.
            return null;
        }
    }

    /**
     * Cosine similarity between two signature vectors — the one
     * implementation in the codebase (the alignment pipeline's signature
     * gate uses it too). Vectors are unit-normalized by the embedding
     * service, but the explicit norms keep unnormalized or malformed
     * vectors honest; shorter vectors defensively truncate (a dimension
     * mismatch cannot inflate the score).
     */
    public function cosineSimilarity(array $a, array $b): float
    {
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        $count = min(count($a), count($b));
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
