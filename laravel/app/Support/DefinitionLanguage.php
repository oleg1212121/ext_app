<?php

namespace App\Support;

/**
 * The majority-script definition guard: a definition is only imported when
 * it is written in the word language's own script — the user's rule that a
 * dictionary article defines its word in that word's language (en-en,
 * ru-ru), enforced at import time on both importers (the kaikki definition
 * flush and the GoldenDict definition-segment path). This is what keeps a
 * ru entry's English glosses (the en-Wiktionary edition's shape) and the
 * bilingual GoldenDict dictionaries' other-language article prose (Oxford
 * en-ru, Mostitsky ru-en) out of the definitions table.
 *
 * Etymologies are deliberately NOT guarded — an etymology regularly cites
 * the source language's material in its own script.
 */
final class DefinitionLanguage
{
    /**
     * Language code → the Unicode script its definitions must be written
     * in. A code absent from the map has no guard: everything passes.
     */
    private const SCRIPTS = [
        'en' => 'Latin',
        'ru' => 'Cyrillic',
        'be' => 'Cyrillic',
        'uk' => 'Cyrillic',
    ];

    /**
     * The language's definition script, or null when the language is not
     * mapped (no guard applies).
     */
    public static function scriptFor(string $code): ?string
    {
        return self::SCRIPTS[$code] ?? null;
    }

    /**
     * The import decision for one definition string: an unmapped language
     * keeps everything, a letterless string (digits/symbols only) has no
     * script to contradict, and otherwise the word language's own script
     * must hold a STRICT majority of the text's letters — an exact tie
     * drops.
     */
    public static function keeps(string $code, string $text): bool
    {
        $script = self::scriptFor($code);
        if ($script === null) {
            return true;
        }

        $total = (int) preg_match_all('/\p{L}/u', $text);
        if ($total === 0) {
            return true;
        }

        $own = (int) preg_match_all('/\p{'.$script.'}/u', $text);

        return $own * 2 > $total;
    }
}
