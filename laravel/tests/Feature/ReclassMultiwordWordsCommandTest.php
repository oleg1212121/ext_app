<?php

use App\Models\Word;
use App\Models\WordClass;

it('reports the reclass candidates without writing during a dry run', function () {
    $junk = createWord('en', 'do it', 'verb');
    $valid = createWord('en', 'give up', 'verb');

    $this->artisan('words:reclass-multiword', ['--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('do it');

    expect($junk->refresh()->wordClass->slug)->toBe('verb')
        ->and($valid->refresh()->wordClass->slug)->toBe('verb');
});

it('reclasses english multi-word rows outside the phrasal shape to phrase', function () {
    $junk = [
        createWord('en', 'do it', 'verb'),
        createWord('en', 'could have', 'verb'),
        createWord('en', 'kick the bucket', 'verb'),
    ];
    $valid = [
        createWord('en', 'give up', 'verb'),
        createWord('en', 'put up with', 'verb'),
    ];
    // Other languages' multi-word verbs are legitimate verbs the English
    // shape has no opinion about (ADR 0059).
    $russian = createWord('ru', 'выдавать себя за', 'verb');

    $this->artisan('words:reclass-multiword')->assertSuccessful();

    foreach ($junk as $word) {
        expect($word->refresh()->wordClass->slug)->toBe('phrase');
    }
    foreach ($valid as $word) {
        expect($word->refresh()->wordClass->slug)->toBe('verb');
    }
    expect($russian->refresh()->wordClass->slug)->toBe('verb');
});

it('skips a row whose headword already exists in the phrase class', function () {
    $junk = createWord('en', 'do it', 'verb');
    createWord('en', 'do it', 'phrase');

    $this->artisan('words:reclass-multiword')->assertSuccessful();

    // The move would collide with the words table's unique key; the verb row
    // stays until the duplicate is resolved by hand.
    expect($junk->refresh()->wordClass->slug)->toBe('verb')
        ->and(Word::query()->where('l_word', 'do it')->count())->toBe(2)
        ->and(WordClass::query()->where('slug', 'phrase')->whereHas('language', fn ($q) => $q->where('code', 'en'))->count())->toBe(1);
});
