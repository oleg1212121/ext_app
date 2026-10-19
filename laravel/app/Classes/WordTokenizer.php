<?php

namespace App\Classes;

use App\Support\WordLookupKey;

class WordTokenizer
{
    private const TOKEN_PATTERN = "/[\p{L}\p{M}]+(?:['’\-][\p{L}\p{M}]+)*/u";

    private const MIN_LENGTH = 2;

    /**
     * Split text into unique tokens keyed by the dictionary lookup form:
     * WordLookupKey::normalize (lowercase, combining marks and zero-width
     * format characters stripped, ё→е folded) — the same key `words.l_word`
     * and `entity_words.l_word` carry, so tokens from stressed or ё-spelled
     * text resolve against the dictionary (ADR 0052).
     *
     * @return array<string, array{token: string, count: int}>
     */
    public function tokenize(string $text): array
    {
        $words = [];

        if (preg_match_all(self::TOKEN_PATTERN, $text, $matches) === false) {
            return $words;
        }

        foreach ($matches[0] as $surface) {
            $surface = $this->trimEdgePunctuation($surface);

            if ($surface === '' || mb_strlen($surface) < self::MIN_LENGTH) {
                continue;
            }

            $key = $this->lookupKey($surface);

            if (isset($words[$key])) {
                $words[$key]['count']++;
            } else {
                $words[$key] = ['token' => $surface, 'count' => 1];
            }
        }

        return $words;
    }

    /**
     * The dictionary lookup key of a surface form: WordLookupKey::normalize —
     * trim, lowercase, no combining marks, no zero-width format characters,
     * ё→е folded. Kept in lockstep with the browser tokenizer
     * (wordTokenizer.mjs) via TokenizerParityTest and with the python
     * tokenizer's lookup_key (ai.enrichment.cyrillic_stress) by its own
     * pytest pins — the three carry one formula.
     */
    public function lookupKey(string $surface): string
    {
        return WordLookupKey::normalize($surface);
    }

    /**
     * Split text into tokens with their character spans, per occurrence.
     *
     * Unlike tokenize() this keeps single-character tokens (я, a, I) and does
     * not dedupe — the enrichment pipeline sends every occurrence to the
     * python service with spans into the sentence content. Spans follow the
     * raw TOKEN_PATTERN matches; the python WORD_RE is the same regex, so the
     * services' tokenization stays in lockstep (TokenizerParityTest).
     *
     * @return list<array{surface: string, start: int, end: int}>
     */
    public function tokenizeWithSpans(string $text): array
    {
        $tokens = [];

        if (preg_match_all(self::TOKEN_PATTERN, $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            return $tokens;
        }

        foreach ($matches[0] as [$surface, $byteOffset]) {
            // PREG_OFFSET_CAPTURE reports byte offsets; the python service
            // works on character positions.
            $start = mb_strlen(substr($text, 0, $byteOffset));
            $tokens[] = ['surface' => $surface, 'start' => $start, 'end' => $start + mb_strlen($surface)];
        }

        return $tokens;
    }

    private function trimEdgePunctuation(string $surface): string
    {
        // PHP trim() strips BYTES, so the multi-byte ’ in the mask shears the
        // final byte off words ending e.g. in р (0xD1 0x80) — invalid UTF-8
        // that Postgres rejects. Edge trimming must be multibyte-safe.
        return preg_replace("/\A['’-]+|['’-]+\z/u", '', $surface) ?? $surface;
    }
}
