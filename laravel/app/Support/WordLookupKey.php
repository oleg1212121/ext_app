<?php

namespace App\Support;

/**
 * The `l_word` lookup-key normalization shared by the dictionary importers
 * (Wiktionary/kaikki and GoldenDict): lowercase, then strip the combining
 * marks (the Cyrillic stress acutes) through the shared StressMarks rule.
 * Matching always compares against this key; Wiktionary keeps stressed
 * display headwords (its ru/be/uk inflected forms are stripped too), the
 * GoldenDict parser stores plain display letters for ru/be/uk.
 */
final class WordLookupKey
{
    public static function normalize(string $word): string
    {
        return StressMarks::strip(mb_strtolower($word));
    }
}
