<?php

namespace App\Support;

/**
 * The lenient JSON parser behind POST /ai/word-enrich (popup-ai-enrich
 * ticket 04): the model's strict-JSON reply arrives fenced, prose-wrapped or
 * merely sloppy, and still has to land as five string-array sections —
 * translations / definitions / transcriptions / examples / etymologies.
 * Coercion rules: code fences and any prose around the outermost `{...}` are
 * stripped, a bare string becomes a one-element list, numbers stringify,
 * booleans / nulls / nested arrays and empties are dropped, duplicates
 * collapse. Counts cap at 12 translations / 8 definitions / 4 each of the
 * rest; definitions / transcriptions / examples / etymologies truncate to
 * their column limits (500 / 100 / 500 / 1000), while translations — lookup
 * candidates that must remain whole words — are DROPPED past 256 chars, the
 * same ceiling the provider fetch applies.
 */
final class EnrichmentReply
{
    /** @var array<string, int> section => maximum item count */
    public const CAPS = [
        'translations' => 12,
        'definitions' => 8,
        'transcriptions' => 4,
        'examples' => 4,
        'etymologies' => 4,
    ];

    /** @var array<string, int> section => stored-text column limit */
    public const LIMITS = [
        'translations' => 256,
        'definitions' => 500,
        'transcriptions' => 100,
        'examples' => 500,
        'etymologies' => 1000,
    ];

    /**
     * @return array<string, list<string>>|null null when no decodable JSON
     *                                          object is found — the endpoint's retryable-failure case
     */
    public static function parse(string $raw): ?array
    {
        $json = self::extractJsonObject($raw);

        if ($json === null) {
            return null;
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return null;
        }

        $parsed = [];
        foreach (array_keys(self::CAPS) as $section) {
            $parsed[$section] = self::stringList($decoded[$section] ?? [], $section);
        }

        return $parsed;
    }

    /**
     * The outermost JSON object of the reply: markdown fences stripped, then
     * everything between the first `{` and the last `}` — tolerant of both
     * prose wrappers ("Here is the JSON: {...} — hope it helps!") and
     * trailing commentary inside the object's own braces.
     */
    private static function extractJsonObject(string $raw): ?string
    {
        $text = trim($raw);

        if (preg_match('/^```[\w-]*\s*(.*?)\s*```$/s', $text, $fences) === 1) {
            $text = trim($fences[1]);
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        return substr($text, $start, $end - $start + 1);
    }

    /**
     * One section's list, from its key: the cap and column limit (and the
     * drop-vs-truncate rule below) read off the section itself, never
     * inferred from a numeric limit — a future section sharing another
     * section's limit must not silently inherit its behavior.
     *
     * @return list<string>
     */
    private static function stringList(mixed $value, string $section): array
    {
        $cap = self::CAPS[$section];
        $limit = self::LIMITS[$section];

        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            // Booleans stringify to "1"/"" — never real content; nested
            // arrays are shapes the model invented, not strings.
            if (is_bool($item) || $item === null || is_array($item)) {
                continue;
            }

            $text = trim((string) $item);

            if ($text === '') {
                continue;
            }

            $items[] = $text;
        }

        $items = array_values(array_unique($items));

        $kept = [];
        foreach ($items as $text) {
            if (mb_strlen($text) > $limit) {
                // Translations are translation-TARGET candidates (they resolve
                // against words.l_word and stage verbatim): a truncated one is
                // no word, so it drops — the others truncate to fit their
                // columns.
                if ($section === 'translations') {
                    continue;
                }

                $text = mb_substr($text, 0, $limit);
            }

            $kept[] = $text;

            if (count($kept) >= $cap) {
                break;
            }
        }

        return $kept;
    }
}
