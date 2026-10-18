<?php

namespace App\Support;

/**
 * The one parts-of-speech alias table every importer shares: dictionary
 * abbreviations ("adj", "прил"), spelled-out labels ("transitive verb") and
 * raw kaikki pos slugs map onto the canonical word-class slugs, so no
 * writer mints a parallel taxonomy ("adj" beside "adjective"). Unknown
 * tokens map to null — the caller keeps its own fallback (GoldenDict
 * leaves the article classless, Wiktionary keeps the raw slug).
 */
final class PosAliases
{
    /** Spelled-out labels up to this length still map (Oxford's "transitive
     * verb" partOfSpeech divs); longer text is a sentence, not a pos. */
    public const MAX_LENGTH = 24;

    /** POS abbreviations dictionary articles and kaikki dumps use, mapped
     * to canonical word-class slugs. */
    private const MAP = [
        'n' => 'noun', 'noun' => 'noun', 'сущ' => 'noun',
        'v' => 'verb', 'vb' => 'verb', 'verb' => 'verb', 'гл' => 'verb',
        'transitive verb' => 'verb', 'intransitive verb' => 'verb',
        'modal verb' => 'verb', 'auxiliary verb' => 'verb',
        'adj' => 'adjective', 'adjective' => 'adjective', 'прил' => 'adjective',
        'adv' => 'adverb', 'adverb' => 'adverb', 'нареч' => 'adverb',
        'prep' => 'preposition', 'preposition' => 'preposition', 'предл' => 'preposition',
        'conj' => 'conjunction', 'conjunction' => 'conjunction', 'союз' => 'conjunction',
        'pron' => 'pronoun', 'pronoun' => 'pronoun', 'мест' => 'pronoun',
        'num' => 'numeral', 'numeral' => 'numeral', 'числ' => 'numeral',
        'interj' => 'interjection', 'int' => 'interjection', 'interjection' => 'interjection', 'межд' => 'interjection',
        'part' => 'particle', 'particle' => 'particle', 'част' => 'particle',
        'art' => 'article', 'article' => 'article',
    ];

    /**
     * Canonical word-class slug for a pos token, null when the table does
     * not know it. Lowercases, trims whitespace and the punctuation
     * dictionaries wrap labels in, and applies the MAX_LENGTH guard so
     * sentence text never maps.
     */
    public static function canonical(string $pos): ?string
    {
        $normalized = mb_strtolower(trim($pos));
        $normalized = trim($normalized, '.,;:()[]{}');
        if ($normalized === '' || mb_strlen($normalized) > self::MAX_LENGTH) {
            return null;
        }

        return self::MAP[$normalized] ?? null;
    }
}
