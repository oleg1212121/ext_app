<?php

namespace App\Support;

/**
 * The shared lemma-form rule plus the form-row hygiene both dictionary
 * importers apply to their forms upserts. Every word carries itself as a
 * form (the lemma form — the forms table alone must resolve lookups), and
 * every form row is capped to the forms.form column length, stripped of
 * the combining stress marks for the StressMarks languages (ru/be/uk),
 * dropped when nothing survives the strip (a marks-only artifact), keyed
 * through WordLookupKey and deduped on the (form, word_id) identity the
 * forms table enforces — so batch upserts stay idempotent and a stressed
 * dump variant never sits beside its plain twin.
 *
 * Every lemma/form containing ё additionally emits its е-folded ALIAS row
 * (ёлка carries ёлка AND елка, both keyed `елка`): texts and dictionaries
 * that drop ё still resolve. The alias dedupes through the same seen-map,
 * so a source already listing both spellings stores two rows, not three.
 */
final class LemmaForms
{
    /** The forms.form column cap. */
    public const CAP = 256;

    /**
     * Form rows for one word: its own (stripped) display headword as the
     * lemma form first, then the dictionary's inflected forms.
     *
     * @param  list<string>  $forms  display form strings straight from the source
     * @param  bool  $stripsMarks  true for the StressMarks languages (ru/be/uk)
     * @return list<array{form: string, l_word: string, word_id: int}>
     */
    public static function rows(string $headword, array $forms, int $wordId, bool $stripsMarks): array
    {
        $rows = [];
        $seen = [];
        foreach ([$headword, ...$forms] as $display) {
            $form = mb_substr($display, 0, self::CAP);
            if ($stripsMarks) {
                $form = StressMarks::strip($form);
            }
            // Stripping can empty a marks-only form: store nothing.
            if ($form === '') {
                continue;
            }
            self::collectRow($rows, $seen, $form, $wordId);

            // The е-folded alias of a ё-form (display folded too, ё→е);
            // already listed spellings dedupe through the same seen-map.
            $alias = WordLookupKey::foldYo($form);
            if ($alias !== $form) {
                self::collectRow($rows, $seen, $alias, $wordId);
            }
        }

        return $rows;
    }

    /**
     * Appends one form row unless this word already claims the exact
     * display string.
     *
     * @param  list<array{form: string, l_word: string, word_id: int}>  $rows
     * @param  array<string, true>  $seen
     */
    private static function collectRow(array &$rows, array &$seen, string $form, int $wordId): void
    {
        $key = $form.'|'.$wordId;
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $rows[] = [
            'form' => $form,
            'l_word' => WordLookupKey::normalize($form),
            'word_id' => $wordId,
        ];
    }
}
