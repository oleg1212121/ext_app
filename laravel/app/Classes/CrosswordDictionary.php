<?php

namespace App\Classes;

use App\Models\Word;
use Illuminate\Support\Collection;

/**
 * The crossword's clue payload: for every selected word, one section per part
 * of speech across the word's whole family — the word's own `words` rows (one
 * per POS) plus the class-scoped base words its forms map to (ADR 0045, ADR
 * 0047), resolved by {@see WordFamily::resolve}, the same engine behind the
 * reader popup. Each section carries the POS display title (`word_classes.title`),
 * the censored definitions, the linked cross-language translations (native
 * language first) and the staged raw candidates from `words.translations`
 * (ADR 0085) — assembled by the popup's shared helpers on
 * {@see WordPopupPayload}.
 *
 * Spoiler hygiene: the panel must not leak the answer, so every family
 * headword is masked to `****` inside definition texts and staged strings —
 * the pre-existing masking idiom, now covering the whole family, so the base
 * "run" is censored inside the "running" clue too. No headword or form-of
 * pointer ever ships: sections are keyed by POS alone, in the canonical class
 * order (EntityWordLinker::classPriority, never answer-POS-first, which would
 * hint the intended sense).
 *
 * Same-POS entries across the family merge into one section (definitions
 * concatenated, translations/staged deduped case-insensitively) so a
 * homograph shared with a base word reads as a single "(verb)" block. A POS
 * without a title forms a headerless trailing section; fully empty sections
 * are omitted.
 */
final class CrosswordDictionary
{
    /**
     * @param  iterable<int, Word>  $words  the puzzle's selected words
     * @return array<string, array{sections: list<array{word_class: string|null, definitions: list<string>, translations: list<array{word: string, language_code: string|null}>, staged_translations: list<string>}>}>
     */
    public static function build(iterable $words, ?int $nativeLanguageId): array
    {
        $dictionary = [];

        foreach ($words as $word) {
            if (strlen($word->word) < 2) {
                continue;
            }

            $dictionary[$word->word] = ['sections' => self::sections($word, $nativeLanguageId)];
        }

        return $dictionary;
    }

    /**
     * @return list<array{word_class: string|null, definitions: list<string>, translations: list<array{word: string, language_code: string|null}>, staged_translations: list<string>}>
     */
    private static function sections(Word $word, ?int $nativeLanguageId): array
    {
        $entries = WordFamily::resolve($word, $word->word)->entries();

        if ($entries->isEmpty()) {
            return [];
        }

        // Longest headword first: masking "run" before "running" would turn
        // the latter into "****ning" before its own turn comes.
        $masks = $entries
            ->pluck('word')
            ->push($word->word)
            ->filter(fn (string $headword): bool => $headword !== '')
            ->unique()
            ->sortByDesc(fn (string $headword): int => mb_strlen($headword))
            ->values()
            ->all();

        $sections = [];
        $slugs = [];

        foreach ($entries as $entry) {
            $key = $entry->word_class_id === null ? '' : (string) $entry->word_class_id;
            $slugs[$key] ??= $entry->wordClass?->slug ?? '';

            if (! isset($sections[$key])) {
                $sections[$key] = [
                    'word_class' => $entry->wordClass?->title,
                    'definitions' => [],
                    'translations' => [],
                    'staged_translations' => [],
                ];
            }

            foreach ($entry->definitions as $definition) {
                $sections[$key]['definitions'][] = self::censor($definition->definition, $masks);
            }

            $linked = WordPopupPayload::translations($entry, $nativeLanguageId);
            $sections[$key]['translations'] = self::mergeTranslations($sections[$key]['translations'], $linked);
            $sections[$key]['staged_translations'] = self::mergeStaged(
                $sections[$key]['staged_translations'],
                array_map(fn (string $candidate): string => self::censor($candidate, $masks), WordPopupPayload::stagedTranslations($entry, $linked)),
                $sections[$key]['translations'],
            );
        }

        return collect($sections)
            ->filter(fn (array $section): bool => $section['definitions'] !== []
                || $section['translations'] !== []
                || $section['staged_translations'] !== [])
            ->sortBy(fn (array $section, string $key): array => [
                EntityWordLinker::classPriority($slugs[$key]),
                $section['word_class'] ?? '',
            ])
            ->values()
            ->all();
    }

    /**
     * Case-insensitive dedup keeping first occurrence — each entry's list is
     * already native-first, so the merge preserves that ordering.
     *
     * @param  list<array{word: string, language_code: string|null}>  $merged
     * @param  Collection<int, array{id: int, word: string, language_code: string|null}>  $translations
     * @return list<array{word: string, language_code: string|null}>
     */
    private static function mergeTranslations(array $merged, Collection $translations): array
    {
        $known = collect($merged)
            ->map(fn (array $translation): string => mb_strtolower($translation['word']))
            ->all();

        foreach ($translations as $translation) {
            $key = mb_strtolower($translation['word']);

            if (! in_array($key, $known, true)) {
                $known[] = $key;
                $merged[] = ['word' => $translation['word'], 'language_code' => $translation['language_code']];
            }
        }

        return $merged;
    }

    /**
     * Case-insensitive dedup against earlier staged strings and against the
     * section's merged linked words (ADR 0085's rejection, applied across the
     * whole section rather than a single entry).
     *
     * @param  list<string>  $merged
     * @param  list<string>  $candidates
     * @param  list<array{word: string, language_code: string|null}>  $linked
     * @return list<string>
     */
    private static function mergeStaged(array $merged, array $candidates, array $linked): array
    {
        $known = collect($merged)
            ->map(fn (string $candidate): string => mb_strtolower($candidate))
            ->all();
        $linkedWords = collect($linked)
            ->map(fn (array $translation): string => mb_strtolower($translation['word']))
            ->all();

        foreach ($candidates as $candidate) {
            $key = mb_strtolower($candidate);

            if (in_array($key, $known, true) || in_array($key, $linkedWords, true)) {
                continue;
            }

            $known[] = $key;
            $merged[] = $candidate;
        }

        return $merged;
    }

    /**
     * @param  list<string>  $masks  family headwords, longest first
     */
    private static function censor(string $text, array $masks): string
    {
        foreach ($masks as $mask) {
            $text = str_ireplace($mask, '****', $text);
        }

        return $text;
    }
}
