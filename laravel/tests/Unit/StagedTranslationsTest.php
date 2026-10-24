<?php

use App\Support\StagedTranslations;

it('unions candidates into the staged JSON without clobbering or duplicating', function () {
    // Existing staged strings keep their positions; new ones append; a
    // candidate already staged never lands twice.
    expect(StagedTranslations::merge(['Katze', 'Kater'], ['Kater', 'Kätzchen']))
        ->toBe(['Katze', 'Kater', 'Kätzchen'])
        // Null staged JSON (the column's "never staged" shape) behaves like empty.
        ->and(StagedTranslations::merge(null, ['Katze']))->toBe(['Katze'])
        // Duplicates inside the incoming candidates dedupe too.
        ->and(StagedTranslations::merge(null, ['кошка', 'кошка']))->toBe(['кошка']);
});

it('yields null when a merge has nothing to stage', function () {
    expect(StagedTranslations::merge(null, []))->toBeNull()
        ->and(StagedTranslations::merge([], []))->toBeNull();
});

it('prunes exactly the given candidate strings and keeps the rest in order', function () {
    expect(StagedTranslations::prune(['Katze', 'Kater', 'Kätzchen'], ['Kater']))
        ->toBe(['Katze', 'Kätzchen'])
        // Several at once; every occurrence of a pruned string leaves.
        ->and(StagedTranslations::prune(['a', 'b', 'a', 'c'], ['a', 'c']))->toBe(['b']);
});

it('matches prune candidates exactly — no normalization, no case folding', function () {
    // The helper never normalizes: the link pass owns normalization and
    // passes the exact staged strings it wants gone.
    expect(StagedTranslations::prune(['Katze'], ['katze']))->toBe(['Katze'])
        ->and(StagedTranslations::prune(['кошка'], ['ко́шка']))->toBe(['кошка']);
});

it('yields null when a prune empties the staged JSON, null stays null', function () {
    expect(StagedTranslations::prune(['Katze'], ['Katze']))->toBeNull()
        ->and(StagedTranslations::prune(null, ['Katze']))->toBeNull();
});

it('passes staged JSON through untouched when nothing prunes', function () {
    expect(StagedTranslations::prune(['Katze'], ['Hund']))->toBe(['Katze'])
        ->and(StagedTranslations::prune(['Katze'], []))->toBe(['Katze']);
});
