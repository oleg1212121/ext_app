<?php

namespace App\Classes\Enrichment;

use App\Models\Entity;
use App\Models\Word;

/**
 * English phrasal verbs: python matches 2/3-token windows against the
 * dictionary's multi-word verb headwords ("give up", "kick the bucket") —
 * inert rows the single-token linker can never reach (ADR 0052). The word
 * classes and lemmas the matcher needs ride the shared base resolution;
 * only the lexicon is contributed here.
 */
class EnglishPhrasalVerbEnricher implements Enricher
{
    /** Phrasal-verb lexicon rows passed per request (python schema cap). */
    private const LEXICON_LIMIT = 50_000;

    public function key(): string
    {
        return 'en_phrasal';
    }

    public function languages(): array
    {
        return ['en'];
    }

    public function column(): string
    {
        return 'phrasal_verbs';
    }

    public function tokenHints(Entity $entity, array $keys, array $resolved): array
    {
        return [];
    }

    public function requestExtras(Entity $entity): array
    {
        return ['phrasal_lexicon' => $this->lexicon($entity)];
    }

    public function toStorage(mixed $output): mixed
    {
        return is_array($output) ? json_encode($output, JSON_UNESCAPED_UNICODE) : null;
    }

    /**
     * Multi-word verb headwords for phrasal-verb matching.
     *
     * @return list<string>
     */
    private function lexicon(Entity $entity): array
    {
        return Word::query()
            ->where('language_id', $entity->language_id)
            ->where('l_word', 'like', '% %')
            ->whereHas('wordClass', fn ($q) => $q->where('slug', 'verb'))
            ->orderBy('id')
            ->limit(self::LEXICON_LIMIT)
            ->pluck('l_word')
            ->values()
            ->all();
    }
}
