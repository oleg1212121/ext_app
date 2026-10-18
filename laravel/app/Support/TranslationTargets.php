<?php

namespace App\Support;

use App\Models\Word;

/**
 * The shared target-word resolver for link-only translation writers (the
 * GoldenDict bilingual import, the popup auto-fetch, the wiktionary link
 * pass): a translation candidate never creates its target row — it links
 * the EXISTING word of the target language whose `l_word` matches the
 * normalized candidate, preferring the row whose class equals the wanted
 * class (the source word's class for single words, `phrase` for multi-word
 * candidates), else the lowest-id row carrying that `l_word`. Normals the
 * target language does not carry resolve to nothing — their candidates
 * stay staged for a later pass.
 */
final class TranslationTargets
{
    /**
     * Resolve wanted l_words against one target language's existing rows —
     * one query. The pick sorts by id itself; the orderBy keeps the query's
     * own iteration deterministic too.
     *
     * @param  array<string, int|null>  $wanted  l_word => wanted word_class_id (null = any class)
     * @return array<string, int> l_word => word id; unmatched normals are absent
     */
    public static function forLanguage(int $languageId, array $wanted): array
    {
        if ($wanted === []) {
            return [];
        }

        $rows = Word::query()
            ->where('language_id', $languageId)
            ->whereIn('l_word', array_keys($wanted))
            ->orderBy('id')
            ->get(['id', 'l_word', 'word_class_id']);

        return self::pick($wanted, $rows);
    }

    /**
     * The pure decision core: wanted classes plus candidate rows in,
     * resolved ids out. Rows sort ascending by id here — under l_word
     * collisions the id order decides, which makes the resolution
     * deterministic whatever order the caller fetched in. A row whose class
     * equals the wanted class wins; a null wanted class (unknown pos /
     * missing class) skips the preference; a wanted class no row carries
     * degrades to the same lowest-id fallback.
     *
     * @param  array<string, int|null>  $wanted  l_word => wanted word_class_id (null = any class)
     * @param  iterable<object{id: int|string, l_word: string, word_class_id: int|string}>  $rows
     * @return array<string, int> l_word => word id; unmatched normals are absent
     */
    public static function pick(array $wanted, iterable $rows): array
    {
        $ordered = is_array($rows) ? $rows : iterator_to_array($rows);
        usort($ordered, fn (object $a, object $b): int => (int) $a->id <=> (int) $b->id);

        $byLWord = [];
        foreach ($ordered as $row) {
            $byLWord[$row->l_word][] = $row;
        }

        $ids = [];
        foreach ($wanted as $normal => $classId) {
            foreach ($byLWord[$normal] ?? [] as $row) {
                if ($classId !== null && (int) $row->word_class_id === (int) $classId) {
                    $ids[$normal] = (int) $row->id;

                    continue 2;
                }
            }

            if (isset($byLWord[$normal])) {
                $ids[$normal] = (int) $byLWord[$normal][0]->id;
            }
        }

        return $ids;
    }
}
