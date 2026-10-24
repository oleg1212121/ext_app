<?php

use App\Models\Word;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(function () {
    createWordClasses();
});

it('forbids a second same-class row sharing the l_word — the March/march case pair', function () {
    // Display spellings differ, the lookup key does not: under the
    // (l_word, language_id, word_class_id) unique the pair is one word —
    // the second spelling cannot land as its own row.
    $march = createWord('en', 'March', 'noun');

    expect(fn () => Word::query()->insert([
        'word' => 'march',
        'l_word' => 'march',
        'language_id' => $march->language_id,
        'word_class_id' => $march->word_class_id,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('forbids a second same-class row sharing the l_word — the ёлка/елка fold pair', function () {
    $elka = createWord('ru', 'ёлка', 'noun', ['l_word' => 'елка']);

    expect(fn () => Word::query()->insert([
        'word' => 'елка',
        'l_word' => 'елка',
        'language_id' => $elka->language_id,
        'word_class_id' => $elka->word_class_id,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('keeps cross-class rows of one l_word legal', function () {
    createWord('en', 'die', 'noun');
    createWord('en', 'die', 'verb');
    createWord('en', 'die', 'unknown');

    expect(Word::query()->where('l_word', 'die')->count())->toBe(3);
});

it('leaves NULL l_word rows unconstrained', function () {
    // l_word is nullable and Postgres NULLs never conflict: unkeyed rows
    // (a hand-typed admin row, legacy data) coexist whatever their display.
    createWord('en', 'Zzz', 'noun', ['l_word' => null]);
    createWord('en', 'zzz', 'noun', ['l_word' => null]);

    expect(Word::query()->whereNull('l_word')->count())->toBe(2);
});
