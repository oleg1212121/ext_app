<?php

use App\Support\PosAliases;

it('maps dictionary abbreviations to canonical class slugs', function () {
    expect(PosAliases::canonical('n'))->toBe('noun')
        ->and(PosAliases::canonical('v'))->toBe('verb')
        ->and(PosAliases::canonical('adj'))->toBe('adjective')
        ->and(PosAliases::canonical('adv'))->toBe('adverb')
        ->and(PosAliases::canonical('int'))->toBe('interjection')
        ->and(PosAliases::canonical('num'))->toBe('numeral');
});

it('maps the Cyrillic aliases to the same canonical slugs', function () {
    expect(PosAliases::canonical('сущ'))->toBe('noun')
        ->and(PosAliases::canonical('гл'))->toBe('verb')
        ->and(PosAliases::canonical('прил'))->toBe('adjective')
        ->and(PosAliases::canonical('нареч'))->toBe('adverb')
        ->and(PosAliases::canonical('предл'))->toBe('preposition')
        ->and(PosAliases::canonical('союз'))->toBe('conjunction')
        ->and(PosAliases::canonical('мест'))->toBe('pronoun')
        ->and(PosAliases::canonical('числ'))->toBe('numeral')
        ->and(PosAliases::canonical('межд'))->toBe('interjection')
        ->and(PosAliases::canonical('част'))->toBe('particle');
});

it('maps spelled-out labels', function () {
    expect(PosAliases::canonical('noun'))->toBe('noun')
        ->and(PosAliases::canonical('transitive verb'))->toBe('verb')
        ->and(PosAliases::canonical('intransitive verb'))->toBe('verb')
        ->and(PosAliases::canonical('auxiliary verb'))->toBe('verb');
});

it('is case-insensitive and trims whitespace and wrapping punctuation', function () {
    expect(PosAliases::canonical(' Adj '))->toBe('adjective')
        ->and(PosAliases::canonical('(adj)'))->toBe('adjective')
        ->and(PosAliases::canonical('Noun,'))->toBe('noun')
        ->and(PosAliases::canonical('VERB'))->toBe('verb');
});

it('returns null for slugs outside the table so the caller keeps its fallback', function () {
    expect(PosAliases::canonical('unknown'))->toBeNull()
        ->and(PosAliases::canonical('name'))->toBeNull()
        ->and(PosAliases::canonical('unsupported_pos'))->toBeNull()
        ->and(PosAliases::canonical(''))->toBeNull();
});

it('returns null for sentence-length text', function () {
    expect(PosAliases::canonical('the quick brown fox jumps over the lazy dog'))->toBeNull();
});
