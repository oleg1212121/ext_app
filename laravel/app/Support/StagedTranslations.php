<?php

namespace App\Support;

/**
 * The staged-translations JSON semantics shared by every translation writer
 * (GoldenDict bilingual import, popup auto-fetch, wiktionary link pass):
 * `words.translations` holds the flat, deduplicated list of raw candidate
 * strings — the Wiktionary shape. New candidates UNION into it (a re-import
 * or a second source never clobbers what is staged, never duplicates it),
 * a link pass prunes exactly the strings it converted into
 * `word_translations` rows, and an empty list stores as null — the column's
 * "nothing staged" shape. The helpers never normalize: callers own
 * normalization and pass the exact stored strings.
 */
final class StagedTranslations
{
    /**
     * Union the candidates into the staged JSON, deduplicated, first
     * occurrence order kept (staged strings keep their positions, new ones
     * append). Null (nothing staged yet) behaves like an empty list.
     *
     * @param  list<string>|null  $staged
     * @param  list<string>  $candidates
     * @return list<string>|null null when nothing remains — the empty shape
     */
    public static function merge(?array $staged, array $candidates): ?array
    {
        $merged = array_values(array_unique(array_merge($staged ?? [], $candidates)));

        return $merged === [] ? null : $merged;
    }

    /**
     * Remove exactly the given candidate strings (every occurrence, no
     * normalization, no case folding) from the staged JSON, remaining
     * order kept.
     *
     * @param  list<string>|null  $staged
     * @param  list<string>  $candidates
     * @return list<string>|null null when nothing remains — the empty shape
     */
    public static function prune(?array $staged, array $candidates): ?array
    {
        if ($staged === null || $staged === []) {
            return null;
        }

        $remove = array_fill_keys($candidates, true);
        $kept = array_values(array_filter(
            $staged,
            fn (string $candidate): bool => ! isset($remove[$candidate]),
        ));

        return $kept === [] ? null : $kept;
    }
}
