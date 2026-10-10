<?php

namespace App\Support;

/**
 * The Cyrillic target languages whose dictionary text carries combining
 * stress marks, and the one stripping rule for them: Wiktionary headwords
 * and dictionary-API candidates arrive stressed (combining acute U+0301,
 * "до́м"), while matching and storage compare against unstressed text.
 */
final class StressMarks
{
    /**
     * True when the language's dictionary text carries combining stress
     * marks that must be stripped before comparison.
     */
    public static function stripsFor(string $code): bool
    {
        return in_array($code, ['ru', 'be', 'uk'], true);
    }

    /**
     * Remove the combining marks (the stress acutes) from the text.
     */
    public static function strip(string $text): string
    {
        return (string) preg_replace('/\p{M}/u', '', $text);
    }
}
