<?php

use App\Support\UsageLabels;

/**
 * The closed usage-label set behind definitions.labels (popup-ai-enrich
 * ticket 09): the kaikki sense tags and the AI reply's labels both filter
 * through the same intersect, and only the [obsolete, archaic] subset
 * demotes (ticket 10's completeness check).
 */
it('keeps known tags in input order and drops everything else', function () {
    expect(UsageLabels::fromKaikkiTags(['obsolete', 'masculine', 'dated', 'singular']))
        ->toBe(['obsolete', 'dated']);
});

it('dedupes tags and ignores non-strings', function () {
    expect(UsageLabels::fromKaikkiTags(['rare', 'rare', 42, null, ['dialectal'], 'archaic', true]))
        ->toBe(['rare', 'archaic']);
});

it('answers an empty list for empty, noise-only or unknown-only tags', function () {
    expect(UsageLabels::fromKaikkiTags([]))->toBe([]);
    expect(UsageLabels::fromKaikkiTags(['masculine', 'singular', 'form-of']))->toBe([]);
    expect(UsageLabels::fromKaikkiTags(['outdated', 'old-fashioned']))->toBe([]);
});

it('pins the closed set and the demoting subset as the constants', function () {
    expect(UsageLabels::SLUGS)->toBe(['obsolete', 'archaic', 'dated', 'colloquial', 'rare', 'dialectal']);
    expect(UsageLabels::DEMOTING)->toBe(['obsolete', 'archaic']);
    foreach (UsageLabels::DEMOTING as $slug) {
        expect(UsageLabels::SLUGS)->toContain($slug);
    }
});

it('demotes only on the obsolete/archaic subset', function () {
    expect(UsageLabels::demoting(['obsolete']))->toBeTrue();
    expect(UsageLabels::demoting(['dated', 'archaic']))->toBeTrue();
    expect(UsageLabels::demoting(['dated', 'colloquial', 'rare', 'dialectal']))->toBeFalse();
    expect(UsageLabels::demoting([]))->toBeFalse();
    expect(UsageLabels::demoting(null))->toBeFalse();
});
