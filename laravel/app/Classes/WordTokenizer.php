<?php

namespace App\Classes;

class WordTokenizer
{
    private const TOKEN_PATTERN = "/[\p{L}\p{M}]+(?:['’\-][\p{L}\p{M}]+)*/u";

    private const MIN_LENGTH = 2;

    /**
     * Split text into unique tokens keyed by the dictionary lookup form:
     * lowercased and stripped of combining marks (U+0301 stress marks) —
     * the same normalization as WiktionaryParser::normalizeLookupKey, so
     * tokens from stressed text resolve against l_word keys (ADR 0052).
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
     * The dictionary lookup key of a surface form: lowercase + no combining
     * marks. Kept in lockstep with the browser tokenizer (wordTokenizer.mjs)
     * via TokenizerParityTest.
     */
    public function lookupKey(string $surface): string
    {
        return preg_replace('/\p{M}/u', '', mb_strtolower($surface)) ?? mb_strtolower($surface);
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
