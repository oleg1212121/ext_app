<?php

namespace App\Support;

/**
 * The `l_word` lookup-key normalization shared by the dictionary importers
 * (Wiktionary/kaikki and GoldenDict): lowercase, then strip the combining
 * marks (the Cyrillic stress acutes) through the shared StressMarks rule.
 * Matching always compares against this key; the stored display words are
 * plain letters on every import path for the stress-mark languages
 * (ru/be/uk) too.
 */
final class WordLookupKey
{
    public static function normalize(string $word): string
    {
        return StressMarks::strip(mb_strtolower($word));
    }
}
