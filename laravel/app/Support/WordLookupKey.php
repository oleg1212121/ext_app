<?php

namespace App\Support;

/**
 * The `l_word` lookup-key normalization shared by the dictionary importers
 * (Wiktionary/kaikki and GoldenDict): lowercase, then strip the combining
 * marks (the Cyrillic stress acutes) through the shared StressMarks rule.
 * The display `word` keeps the marks; matching compares against this key.
 */
final class WordLookupKey
{
    public static function normalize(string $word): string
    {
        return StressMarks::strip(mb_strtolower($word));
    }
}
