<?php

namespace App\Classes\Wiktionary;

use App\Support\WordLookupKey;

/**
 * The parser-agnostic kaikki form-of verdict, shared by the importer (a
 * relay entry imports as a FORM of its base, not a word row) and the demote
 * command (already-imported relay word rows re-point the same way). Takes a
 * decoded dump entry, returns whether it is a relay and its base word.
 *
 * Wiktionary marks inflected variants as standalone entries whose EVERY
 * sense carries the sense-level `form_of` list (`[{word: ...}]`), nearly
 * always with the `form-of` tag: "четвереньках — prepositional of
 * четвере́ньки". Mixed entries (some relay senses + some real glosses) are
 * genuine words and stay words. The base is the first sense's
 * `form_of[0].word`, returned verbatim — stress-mark stripping stays the
 * consumer's call, since only the StressMarks languages (ru/be/uk) need it.
 *
 * A relay verdict always carries a usable base: senses tagged form-of
 * without any `form_of` word give nothing to attach to, and a base that
 * collapses into the entry's own lookup key (the stressed spelling of the
 * plain headword itself) would demote the entry onto itself — both stay
 * words (`relay: false`).
 */
final class FormOfClassifier
{
    /**
     * @return array{relay: bool, base: ?string}
     */
    public static function classify(object $entry): array
    {
        // The entry-level `form_of` signal is essentially absent in both
        // dumps; handled trivially when a dump emits it.
        $entryLevel = self::baseWordFrom($entry->form_of ?? null);
        if ($entryLevel !== null) {
            return self::verdict($entry, $entryLevel);
        }

        $senses = is_array($entry->senses ?? null) ? $entry->senses : [];
        if ($senses === []) {
            // No senses, no sense-level signal: nothing to classify on.
            return ['relay' => false, 'base' => null];
        }

        $base = null;
        foreach ($senses as $sense) {
            $senseBase = self::baseWordFrom($sense->form_of ?? null);
            if ($senseBase !== null) {
                $base ??= $senseBase;

                continue;
            }

            $tags = is_array($sense->tags ?? null) ? $sense->tags : [];
            if (! in_array('form-of', $tags, true)) {
                // A real gloss: the union of senses is mixed.
                return ['relay' => false, 'base' => null];
            }
        }

        return self::verdict($entry, $base);
    }

    /**
     * The final gate: a relay must carry a base distinct from itself.
     *
     * @return array{relay: bool, base: ?string}
     */
    private static function verdict(object $entry, ?string $base): array
    {
        if ($base === null) {
            return ['relay' => false, 'base' => null];
        }

        $word = is_string($entry->word ?? null) ? $entry->word : '';
        if (WordLookupKey::normalize($base) === WordLookupKey::normalize($word)) {
            return ['relay' => false, 'base' => null];
        }

        return ['relay' => true, 'base' => $base];
    }

    /**
     * The base word of a form_of list — `[0].word`, when it is a non-empty
     * string. Anything else (missing, empty, wordless) reads as no base.
     */
    private static function baseWordFrom(mixed $formOf): ?string
    {
        if (! is_array($formOf) || $formOf === []) {
            return null;
        }

        $word = $formOf[0]->word ?? null;
        if (! is_string($word) || trim($word) === '') {
            return null;
        }

        return $word;
    }
}
