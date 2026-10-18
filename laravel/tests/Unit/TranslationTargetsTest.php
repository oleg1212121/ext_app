<?php

use App\Support\TranslationTargets;

/**
 * Plain row stand-ins shaped like the models the resolver's query returns
 * (id, l_word, word_class_id), so the decision logic tests without a DB.
 */
function targetRow(int $id, string $lWord, int $classId): object
{
    return (object) ['id' => $id, 'l_word' => $lWord, 'word_class_id' => $classId];
}

it('prefers the row whose class equals the wanted class over a lower-id decoy', function () {
    // noun=11, verb=22: the noun-class row wins for a noun candidate even
    // though the verb row carries the lower id.
    $rows = [
        targetRow(101, 'katze', 22),
        targetRow(102, 'katze', 11),
    ];

    expect(TranslationTargets::pick(['katze' => 11], $rows))->toBe(['katze' => 102]);
});

it('prefers the phrase class for a multi-word candidate over a lower-id decoy', function () {
    // The caller maps multi-word candidates onto the phrase class; the
    // resolver's class preference then lifts the phrase row over the
    // lower-id same-l_word noun decoy ("aufhören mit" noun AND phrase).
    $rows = [
        targetRow(30, 'aufhören mit', 11),  // noun decoy
        targetRow(31, 'aufhören mit', 13),  // phrase
    ];

    expect(TranslationTargets::pick(['aufhören mit' => 13], $rows))->toBe(['aufhören mit' => 31]);
});

it('falls back to the lowest-id row with that l_word when no class matches', function () {
    $rows = [
        targetRow(7, 'katze', 22),
        targetRow(3, 'katze', 22),
        targetRow(9, 'katze', 11),
    ];

    // Null wanted class: any row qualifies — the lowest id wins.
    expect(TranslationTargets::pick(['katze' => null], $rows))->toBe(['katze' => 3])
        // A wanted class none of the rows carries degrades to the same
        // lowest-id fallback rather than dropping the candidate.
        ->and(TranslationTargets::pick(['katze' => 13], $rows))->toBe(['katze' => 3]);
});

it('is deterministic under l_word collisions — the first class match in id order wins', function () {
    // Same l_word AND same class several times over: the id-ascending row
    // order decides, so repeated runs (and the DB-backed caller, which
    // orders by id) always resolve to the same row.
    $rows = [
        targetRow(5, 'машины', 11),
        targetRow(6, 'машины', 11),
        targetRow(8, 'машины', 12),
    ];

    $picked = TranslationTargets::pick(['машины' => 11], $rows);

    expect($picked)->toBe(['машины' => 5])
        ->and(TranslationTargets::pick(['машины' => 11], $rows))->toBe($picked)
        // Any-class fallback picks the same lowest-id row both times.
        ->and(TranslationTargets::pick(['машины' => null], $rows))->toBe(['машины' => 5]);
});

it('resolves each wanted l_word independently and drops unmatched normals', function () {
    $rows = [
        targetRow(1, 'hund', 11),
        targetRow(2, 'katze', 22),
        targetRow(3, 'katze', 11),
    ];

    expect(TranslationTargets::pick([
        'katze' => 11,
        'hund' => null,
        'vogel' => 11,
    ], $rows))->toBe([
        'katze' => 3,
        'hund' => 1,
    ]);
});

it('resolves nothing without wanted normals or rows', function () {
    expect(TranslationTargets::pick([], [targetRow(1, 'hund', 11)]))->toBe([])
        ->and(TranslationTargets::pick(['hund' => 11], []))->toBe([])
        ->and(TranslationTargets::pick(['hund' => null], []))->toBe([]);
});

it('builds the wanted-class map: multi-word wants phrase, single word the mappable pos class', function () {
    // noun=11, verb=22, phrase=13 — the TARGET language's class ids by slug.
    $classes = ['noun' => 11, 'verb' => 22, 'phrase' => 13];

    // A case variant of the first candidate dedupes onto its key.
    expect(TranslationTargets::wantedClasses(
        [['text' => 'aufgeben', 'pos' => 'verb'], ['text' => 'aufgeben mit'], ['text' => 'Aufgeben']],
        $classes,
        'noun',
    ))->toBe([
        'aufgeben' => 22,
        'aufgeben mit' => 13,
    ]);
});

it('falls back to the source word class for a single word with no mappable pos', function () {
    $classes = ['noun' => 11];

    expect(TranslationTargets::wantedClasses(
        [['text' => 'katze', 'pos' => 'femnoun'], ['text' => 'hund', 'pos' => null], 'vogel'],
        $classes,
        'noun',
    ))->toBe([
        'katze' => 11, // unmappable pos → the source word's class
        'hund' => 11, // no pos → the source word's class
        'vogel' => 11, // staged string (no pos) → the source word's class
    ]);
});

it('resolves the wanted class to null when neither pos nor source class maps', function () {
    expect(TranslationTargets::wantedClasses([['text' => 'katze', 'pos' => 'femnoun']], ['noun' => 11], null))
        ->toBe(['katze' => null])
        ->and(TranslationTargets::wantedClasses(['katze'], ['noun' => 11], 'femnoun'))
        ->toBe(['katze' => null]);
});

it('wants the phrase class only when the target language carries it', function () {
    expect(TranslationTargets::wantedClasses(['aufgeben mit'], ['noun' => 11], 'noun'))
        ->toBe(['aufgeben mit' => null]);
});
