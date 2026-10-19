<?php

use App\Support\DefinitionLanguage;

it('maps the word languages to their definition scripts', function () {
    expect(DefinitionLanguage::scriptFor('ru'))->toBe('Cyrillic')
        ->and(DefinitionLanguage::scriptFor('be'))->toBe('Cyrillic')
        ->and(DefinitionLanguage::scriptFor('uk'))->toBe('Cyrillic')
        ->and(DefinitionLanguage::scriptFor('en'))->toBe('Latin')
        // An unmapped code has no script and therefore no guard.
        ->and(DefinitionLanguage::scriptFor('de'))->toBeNull()
        ->and(DefinitionLanguage::scriptFor('fr'))->toBeNull();
});

it('drops a Cyrillic-majority definition of an en word', function () {
    expect(DefinitionLanguage::keeps('en', 'домашнее животное семейства псовых'))->toBeFalse();
});

it('drops a Latin-majority definition of a ru word', function () {
    expect(DefinitionLanguage::keeps('ru', 'a domesticated canid of the genus Canis'))->toBeFalse();
});

it('keeps a definition whose own script holds the strict majority', function () {
    expect(DefinitionLanguage::keeps('ru', 'домашнее животное семейства псовых'))->toBeTrue()
        ->and(DefinitionLanguage::keeps('en', 'a domesticated canid'))->toBeTrue()
        // The majority counts over ALL letters: a few foreign loanwords in
        // the gloss do not sink an own-script definition...
        ->and(DefinitionLanguage::keeps('ru', 'вид компьютерной мыши (mouse)'))->toBeTrue()
        ->and(DefinitionLanguage::keeps('en', 'a kind of dacha (дача) in the countryside'))->toBeTrue();
});

it('drops an exact tie', function () {
    // Three Cyrillic letters against three Latin ones — no strict majority,
    // so the definition drops on either side.
    expect(DefinitionLanguage::keeps('ru', 'кот cat'))->toBeFalse()
        ->and(DefinitionLanguage::keeps('en', 'кот cat'))->toBeFalse();
});

it('keeps a letterless definition', function () {
    // Digits, symbols and whitespace carry no letters at all: no script can
    // claim them, so nothing is foreign about them.
    expect(DefinitionLanguage::keeps('ru', '1 000 000 ?!'))->toBeTrue()
        ->and(DefinitionLanguage::keeps('en', '®™ §4-5'))->toBeTrue();
});

it('keeps everything for an unmapped language code', function () {
    // The guard is opt-in per language: German definitions are in Latin
    // letters, but nothing is enforced there yet.
    expect(DefinitionLanguage::keeps('de', 'ein domestiziertes Tier'))->toBeTrue()
        ->and(DefinitionLanguage::keeps('de', 'домашнее животное'))->toBeTrue()
        ->and(DefinitionLanguage::keeps('fr', 'chien'))->toBeTrue();
});
