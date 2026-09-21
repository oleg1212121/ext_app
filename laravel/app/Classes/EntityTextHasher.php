<?php

namespace App\Classes;

use App\Models\Entity;
use Illuminate\Support\Facades\Storage;

/**
 * Computes and maintains the entity text hash: a sha256 over the entity's
 * sentence contents in document order, each sentence whitespace-normalized.
 * Two entities with the same text_hash are exact copies of the same text, and
 * a completed alignment between one exact-copy pair can be reused for another.
 *
 * The hash is cheap (a local sha256, no external service), so it is refreshed
 * eagerly by the refresh scheduler and, as a safety net, synchronously wherever
 * a fresh hash is required (alignment-copy lookup).
 */
class EntityTextHasher
{
    /**
     * Collapse every whitespace run (including Unicode whitespace such as
     * NBSP) to a single ASCII space and trim the ends. Case and punctuation
     * are preserved — this stays an *exact* copy check.
     */
    public static function normalize(string $sentence): string
    {
        return trim((string) preg_replace('/(*UCP)\s+/u', ' ', $sentence));
    }

    /**
     * sha256 of the entity's sentences, normalized and joined in document
     * order with a newline separator.
     */
    public function hash(Entity $entity): string
    {
        $content = $entity->sentences()
            ->orderBy('order')
            ->pluck('content')
            ->map(fn (string $sentence): string => self::normalize($sentence))
            ->implode("\n");

        return hash('sha256', $content);
    }

    public static function hashFile(string $absolutePath): string
    {
        return hash_file('sha256', $absolutePath);
    }

    /**
     * sha256 of a file stored on the `local` disk (entity uploads).
     */
    public static function hashStoredFile(string $relativePath): string
    {
        return self::hashFile(Storage::disk('local')->path($relativePath));
    }

    /**
     * The stored hash is stale when it was never computed or any sentence
     * mutation happened after it was recorded.
     */
    public function isStale(Entity $entity): bool
    {
        if ($entity->text_hash === null || $entity->text_hashed_at === null) {
            return true;
        }

        return $entity->sentences_updated_at !== null
            && $entity->sentences_updated_at->isAfter($entity->text_hashed_at);
    }

    /**
     * Recompute and persist the hash when stale; return the current hash in
     * any case. text_hashed_at is pinned to the sentence-set timestamp
     * observed *before* hashing, so a mutation committed mid-hash leaves the
     * entity stale rather than silently fresh.
     */
    public function refreshIfStale(Entity $entity): string
    {
        if (! $this->isStale($entity)) {
            return $entity->text_hash;
        }

        $observedAt = $entity->sentences_updated_at ?? now();

        $entity->forceFill([
            'text_hash' => $this->hash($entity),
            'text_hashed_at' => $observedAt,
        ])->save();

        return $entity->text_hash;
    }
}
