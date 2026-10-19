<?php

use App\Support\StressMarks;
use App\Support\WordLookupKey;

it('lowercases the headword for lookup', function () {
    expect(WordLookupKey::normalize('Hello'))->toBe('hello');
});

it('strips combining stress marks from the lookup key only', function () {
    expect(WordLookupKey::normalize('приве́т'))->toBe('привет');
    expect('приве́т')->not->toBe('привет');
});

it('trims surrounding whitespace from the key', function () {
    expect(WordLookupKey::normalize("  до\u{0301}м \t"))->toBe('дом');
    expect(WordLookupKey::normalize(' cat '))->toBe('cat');
});

it('strips combining marks unconditionally, en included', function () {
    // The key never carries combining marks, whatever the language: the
    // en "café" spelled with a decomposed é keys like "cafe".
    expect(WordLookupKey::normalize("cafe\u{0301}"))->toBe('cafe');
});

it('strips zero-width and format characters from the key', function () {
    // ZWSP, ZWJ, BOM and the soft hyphen survive in the source text but
    // must never fragment the key.
    expect(WordLookupKey::normalize("ма\u{200B}ши\u{200D}ны"))->toBe('машины');
    expect(WordLookupKey::normalize("\u{FEFF}дом\u{00AD}"))->toBe('дом');
});

it('folds ё and Ё into е in the key', function () {
    expect(WordLookupKey::normalize('ёлка'))->toBe('елка');
    // Ё lowercases to ё first, so the fold catches it too.
    expect(WordLookupKey::normalize('Ёлка'))->toBe('елка');
    expect('ёлка')->not->toBe('елка');
});

it('applies the whole pipeline: trim, marks, format characters, ё fold', function () {
    expect(WordLookupKey::normalize("до\u{0301}м \u{200B}"))->toBe('дом');
    expect(WordLookupKey::normalize(" Ёлка\u{00AD} "))->toBe('елка');
});

it('never folds or strips outside the key: display text keeps ё and its marks rule', function () {
    // The display strip is the StressMarks rule only: combining marks out
    // on the stress-mark languages, ё untouched everywhere.
    expect(StressMarks::strip('ёлка'))->toBe('ёлка');
    expect(StressMarks::strip('Ёлка'))->toBe('Ёлка');
    expect(StressMarks::strip('приве́т'))->toBe('привет');
    // The strip removes combining marks only — it never folds ё and never
    // touches letters (the en diacritics are the callers' language gate).
    expect(StressMarks::strip('ёлка'))->toBe('ёлка');
    expect(StressMarks::strip('café'))->toBe('café');
});
