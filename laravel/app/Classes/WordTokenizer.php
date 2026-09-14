<?php

namespace App\Classes;

class WordTokenizer
{
    private const TOKEN_PATTERN = "/[\p{L}\p{M}]+(?:['’\-][\p{L}\p{M}]+)*/u";

    private const MIN_LENGTH = 2;

    /**
     * Split text into unique lowercase tokens with occurrence counts and the
     * first-seen surface form.
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

            $key = mb_strtolower($surface);

            if (isset($words[$key])) {
                $words[$key]['count']++;
            } else {
                $words[$key] = ['token' => $surface, 'count' => 1];
            }
        }

        return $words;
    }

    private function trimEdgePunctuation(string $surface): string
    {
        // PHP trim() strips BYTES, so the multi-byte ’ in the mask shears the
        // final byte off words ending e.g. in р (0xD1 0x80) — invalid UTF-8
        // that Postgres rejects. Edge trimming must be multibyte-safe.
        return preg_replace("/\A['’-]+|['’-]+\z/u", '', $surface) ?? $surface;
    }
}
