<?php

use App\Support\WordLookupKey;

it('lowercases the headword for lookup', function () {
    expect(WordLookupKey::normalize('Hello'))->toBe('hello');
});

it('strips combining stress marks from the lookup key only', function () {
    expect(WordLookupKey::normalize('приве́т'))->toBe('привет');
    expect('приве́т')->not->toBe('привет');
});
