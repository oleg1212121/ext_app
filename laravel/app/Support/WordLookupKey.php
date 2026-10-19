<?php

namespace App\Support;

/**
 * The `l_word` lookup-key normalization shared by the dictionary importers
 * (Wiktionary/kaikki and GoldenDict): trim, lowercase, strip the combining
 * marks (the Cyrillic stress acutes, unconditional — en included), strip
 * the zero-width/format characters (`\p{Cf}`: ZWSP, ZWJ, BOM, soft hyphen —
 * they survive verbatim in source text and fragment lookups), then fold
 * ё→е so ёлка and елка share the key `елка`.
 *
 * Matching always compares against this key. Only the KEY folds and strips:
 * the stored display words keep the source spelling (ёлка stays ёлка) and
 * carry just the StressMarks rule for the ru/be/uk stress-mark languages.
 */
final class WordLookupKey
{
    /** The ё→е fold table, case-preserving for display-side folds. */
    private const YO_FOLD = ['Ё' => 'Е', 'ё' => 'е'];

    public static function normalize(string $word): string
    {
        // Lowercasing first puts Ё into the fold table's reach.
        $key = mb_strtolower(trim($word));
        $key = StressMarks::strip($key);
        $key = (string) preg_replace('/\p{Cf}/u', '', $key);
        // A strip can bare a whitespace edge that sat beside a stripped
        // mark or format character ("до́м \u{200B}"): the key never
        // carries edge whitespace, so trim once more.
        $key = trim($key);

        return self::foldYo($key);
    }

    /**
     * The ё→е fold, shared with the display-side е-alias rows
     * (`App\Support\LemmaForms`): case-preserving, so the alias of "Ёлка"
     * is "Елка".
     */
    public static function foldYo(string $text): string
    {
        return strtr($text, self::YO_FOLD);
    }
}
