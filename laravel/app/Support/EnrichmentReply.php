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
 * collapse on text. Counts cap at 12 translations / 8 definitions / 4 each
 * of the rest; definitions / transcriptions / examples / etymologies
 * truncate to their column limits (500 / 100 / 500 / 1000), while
 * translations — lookup candidates that must remain whole words — are
 * DROPPED past 256 chars, the same ceiling the provider fetch applies.
 *
 * Since ticket 09 the definitions entries may alternatively be usage-label
 * objects, `{"text": ..., "labels": [...]}`: the text coerces exactly like
 * the plain path, the labels filter through {@see UsageLabels}' closed set
 * (order kept, unknown slugs dropped), and the section parses to
 * `['text' => ..., 'labels' => [...]]` entries — the other four sections
 * stay plain strings.
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
     * @return array{
     *     translations: list<string>,
     *     definitions: list<array{text: string, labels: list<string>}>,
     *     transcriptions: list<string>,
     *     examples: list<string>,
     *     etymologies: list<string>
     * }|null null when no decodable JSON object
     *          is found — the endpoint's retryable-failure case
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
     * section's limit must not silently inherit its behavior. Definitions
     * entries may be the labelled object form; the coercion keeps the text
     * stringifying exactly like the plain path, only the labels ride along.
     *
     * @return list<string>|list<array{text: string, labels: list<string>}>
     *                                                                      list<string> for every section but definitions, which is
     *                                                                      always the labelled-pair shape
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
            // A nested array is a shape the model invented, not a string —
            // except the definitions section's labelled entry, which coerces
            // (null = dropped, as junk shapes always were).
            if (is_array($item)) {
                if ($section === 'definitions' && ($entry = self::labelledDefinition($item)) !== null) {
                    $items[] = $entry;
                }

                continue;
            }

            // Booleans stringify to "1"/"" — never real content.
            if (is_bool($item) || $item === null) {
                continue;
            }

            $items[] = trim((string) $item);
        }

        // Dedupe on text BEFORE the cap, first occurrence (and its labels)
        // standing — the same collapse the bare string lists had, so a
        // string entry and an object entry spelling the same text keep only
        // the first.
        $seen = [];
        $kept = [];
        foreach ($items as $item) {
            $text = is_array($item) ? $item['text'] : $item;

            if ($text === '' || isset($seen[$text])) {
                continue;
            }
            $seen[$text] = true;

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

            // The definitions section ALWAYS parses to text/labels pairs —
            // a plain-string entry is an unlabelled one — so the merge
            // consumes one shape whatever the reply mixed in. The other
            // sections keep bare strings.
            $kept[] = $section === 'definitions'
                ? ['text' => $text, 'labels' => is_array($item) ? $item['labels'] : []]
                : $text;

            if (count($kept) >= $cap) {
                break;
            }
        }

        return $kept;
    }

    /**
     * The definitions section's optional object entry: `{"text": ...,
     * "labels": [...]}`. The text stringifies like the plain path (numbers
     * survive, booleans / nulls / nested shapes drop, empties drop); the
     * labels filter through {@see UsageLabels::fromKaikkiTags} — order
     * kept, duplicates collapsed, unknown slugs and non-strings dropped,
     * and a labels field that is not an array at all counts as none.
     *
     * @param  array<string, mixed>  $item
     * @return array{text: string, labels: list<string>}|null null when the
     *                                                        entry carries no usable text
     */
    private static function labelledDefinition(array $item): ?array
    {
        $text = $item['text'] ?? null;

        if (is_bool($text) || $text === null || is_array($text)) {
            return null;
        }

        $text = trim((string) $text);

        if ($text === '') {
            return null;
        }

        $labels = $item['labels'] ?? [];

        return [
            'text' => $text,
            'labels' => UsageLabels::fromKaikkiTags(is_array($labels) ? $labels : []),
        ];
    }
}
