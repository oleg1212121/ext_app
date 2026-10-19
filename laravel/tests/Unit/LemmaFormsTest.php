<?php

use App\Support\LemmaForms;

it('makes the word its own first form (the lemma form)', function () {
    $rows = LemmaForms::rows('cat', ['cats'], 5, false);

    expect($rows)->toBe([
        ['form' => 'cat', 'l_word' => 'cat', 'word_id' => 5],
        ['form' => 'cats', 'l_word' => 'cats', 'word_id' => 5],
    ]);
});

it('strips stress marks only when the language strips them', function () {
    $stripped = LemmaForms::rows('до́м', ['до́ма'], 7, true);
    expect($stripped)->toBe([
        ['form' => 'дом', 'l_word' => 'дом', 'word_id' => 7],
        ['form' => 'дома', 'l_word' => 'дома', 'word_id' => 7],
    ]);

    // A non-stress-mark language keeps its display strings verbatim
    // (diacritics like café are legitimate spelling there).
    $verbatim = LemmaForms::rows('Café', ['cafés'], 8, false);
    expect($verbatim)->toBe([
        ['form' => 'Café', 'l_word' => 'café', 'word_id' => 8],
        ['form' => 'cafés', 'l_word' => 'cafés', 'word_id' => 8],
    ]);
});

it('dedupes the lemma against an inflected form of the same letters', function () {
    // The stripped lemma collapses into the plain form the dump also lists.
    $rows = LemmaForms::rows('до́ма', ['дома', 'дому'], 9, true);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['form'])->toBe('дома')
        ->and($rows[1]['form'])->toBe('дому');
});

it('drops forms that strip to the empty string', function () {
    // A marks-only form (a bare combining acute) stores nothing.
    $rows = LemmaForms::rows("до\u{0301}м", ["\u{0301}", 'дома'], 11, true);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['form'])->toBe('дом')
        ->and($rows[1]['form'])->toBe('дома');
});

it('drops a headword that strips to the empty string and keeps the rest', function () {
    $rows = LemmaForms::rows("\u{0301}", ['дом'], 12, true);

    expect($rows)->toBe([
        ['form' => 'дом', 'l_word' => 'дом', 'word_id' => 12],
    ]);
});

it('returns no rows for a marks-only headword without forms', function () {
    expect(LemmaForms::rows("\u{0301}", [], 13, true))->toBe([]);
});

it('caps every form at the 256-char column length', function () {
    $long = str_repeat('a', 300);
    $rows = LemmaForms::rows($long, [], 14, false);

    expect($rows)->toHaveCount(1)
        ->and(mb_strlen($rows[0]['form']))->toBe(256);
});

it('dedupes repeated identical forms', function () {
    $rows = LemmaForms::rows('cat', ['cat', 'cat', 'cats'], 15, false);

    expect($rows)->toHaveCount(2)
        ->and(collect($rows)->pluck('form')->all())->toBe(['cat', 'cats']);
});

it('emits an е-folded alias row for every ё-form, sharing one l_word', function () {
    // ёлка carries both spellings with the identical folded key, so texts
    // and dictionaries that drop ё still resolve through the forms table.
    $rows = LemmaForms::rows('ёлка', ['ёлки'], 16, false);

    expect($rows)->toBe([
        ['form' => 'ёлка', 'l_word' => 'елка', 'word_id' => 16],
        ['form' => 'елка', 'l_word' => 'елка', 'word_id' => 16],
        ['form' => 'ёлки', 'l_word' => 'елки', 'word_id' => 16],
        ['form' => 'елки', 'l_word' => 'елки', 'word_id' => 16],
    ]);
});

it('folds the alias display case-preserving', function () {
    $rows = LemmaForms::rows('Ёлка', [], 17, false);

    expect($rows)->toBe([
        ['form' => 'Ёлка', 'l_word' => 'елка', 'word_id' => 17],
        ['form' => 'Елка', 'l_word' => 'елка', 'word_id' => 17],
    ]);
});

it('adds no extra rows for forms without ё', function () {
    $rows = LemmaForms::rows('дом', ['дома'], 18, true);

    expect($rows)->toBe([
        ['form' => 'дом', 'l_word' => 'дом', 'word_id' => 18],
        ['form' => 'дома', 'l_word' => 'дома', 'word_id' => 18],
    ]);
});

it('dedupes a source already listing both spellings to two rows, not three', function () {
    $rows = LemmaForms::rows('ёлка', ['елка'], 19, false);

    expect($rows)->toBe([
        ['form' => 'ёлка', 'l_word' => 'елка', 'word_id' => 19],
        ['form' => 'елка', 'l_word' => 'елка', 'word_id' => 19],
    ]);

    // The reverse listing order dedupes the same way.
    $reversed = LemmaForms::rows('елка', ['ёлка'], 20, false);

    expect($reversed)->toBe([
        ['form' => 'елка', 'l_word' => 'елка', 'word_id' => 20],
        ['form' => 'ёлка', 'l_word' => 'елка', 'word_id' => 20],
    ]);
});
