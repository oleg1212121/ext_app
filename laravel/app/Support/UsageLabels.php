<?php

namespace App\Support;

/**
 * The usage/register labels a definition sense can carry (popup-ai-enrich
 * ticket 09), and the single source of truth for them: the kaikki importer
 * maps `senses[].tags` through {@see fromKaikkiTags}, the ask-AI enrichment
 * reply's `{text, labels}` definition objects filter their labels through
 * the same helper, the merge stores the result on `definitions.labels`
 * (null when nothing remains), and ticket 10's completeness scoring demotes
 * a word whose own headword mark (`words.labels`) or whose definitions all
 * carry the {@see DEMOTING} subset. The set is
 * closed on purpose: the popup can render the labels and ticket 10's SQL
 * can mirror the demotion check only while the slugs stay enumerable.
 * Labels were filled going forward only until 2026-10-23: since then a
 * kaikki re-import refreshes them on same-text rows (ADR 0083 amendment),
 * so the backfill is the re-import itself. GoldenDict labels are
 * unrecoverable: the HTML conversion flattened the headwords' usage
 * markers away before this column existed.
 */
final class UsageLabels
{
    /**
     * The canonical closed set. Order matters only for reading; storage
     * keeps the order the source listed the tags in.
     */
    public const SLUGS = ['obsolete', 'archaic', 'dated', 'colloquial', 'rare', 'dialectal'];

    /**
     * The subset that demotes a definition (and, ticket 10, the word's
     * completeness when the word's own headword mark or EVERY definition
     * carries one of these).
     */
    public const DEMOTING = ['obsolete', 'archaic'];

    /**
     * Intersect the given tags with the closed set: input order preserved,
     * duplicates collapsed, and everything else — unknown slugs plus the
     * grammar/gender noise a kaikki sense tags array carries ("masculine",
     * "singular", "form-of") — dropped. Doubles as the AI reply's label
     * filter: the lenient coercion keeps only known slugs there too.
     *
     * @param  list<mixed>  $tags
     * @return list<string>
     */
    public static function fromKaikkiTags(array $tags): array
    {
        $known = array_fill_keys(self::SLUGS, true);

        $labels = [];
        foreach ($tags as $tag) {
            if (is_string($tag) && isset($known[$tag]) && ! isset($labels[$tag])) {
                $labels[$tag] = true;
            }
        }

        return array_keys($labels);
    }

    /**
     * Whether any of the labels demotes the definition — a member of the
     * {@see DEMOTING} subset is present. Null (the unlabelled column value)
     * and the empty list never demote.
     *
     * @param  list<string>|null  $labels
     */
    public static function demoting(?array $labels): bool
    {
        return $labels !== null && array_intersect($labels, self::DEMOTING) !== [];
    }
}
