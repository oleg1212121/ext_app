<?php

use App\Classes\WordTokenizer;
use Tests\TestCase;

uses(TestCase::class);

it('splits english text into lowercase tokens with counts', function () {
    $tokenizer = new WordTokenizer;

    $words = $tokenizer->tokenize('The quick brown fox. The fox, the quick!');

    expect($words)->toBe([
        'the' => ['token' => 'The', 'count' => 3],
        'quick' => ['token' => 'quick', 'count' => 2],
        'brown' => ['token' => 'brown', 'count' => 1],
        'fox' => ['token' => 'fox', 'count' => 2],
    ]);
});

it('keeps cyrillic tokens', function () {
    $tokenizer = new WordTokenizer;

    $words = $tokenizer->tokenize('Мама мыла раму, и Мама улыбалась.');

    expect($words['мама']['count'])->toBe(2);
    expect($words['мыла']['token'])->toBe('мыла');
    expect($words['раму'])->toBe(['token' => 'раму', 'count' => 1]);
    expect($words['улыбалась'])->toBe(['token' => 'улыбалась', 'count' => 1]);
});

it('keeps multibyte word endings valid utf-8', function () {
    $tokenizer = new WordTokenizer;

    // Words ending in р (0xD1 0x80) lose their final byte to the byte-wise
    // trim mask and crash the Postgres insert with SQLSTATE 22021.
    $words = $tokenizer->tokenize('Ампер умер.');

    expect(array_keys($words))->toBe(['ампер', 'умер']);
    foreach ($words as $key => $entry) {
        expect(mb_check_encoding($key, 'UTF-8'))->toBeTrue();
        expect(mb_check_encoding($entry['token'], 'UTF-8'))->toBeTrue();
    }
    expect($words['ампер']['token'])->toBe('Ампер');
    expect($words['умер']['token'])->toBe('умер');
});

it('preserves curly apostrophes inside words', function () {
    $tokenizer = new WordTokenizer;

    $words = $tokenizer->tokenize('He said don’t twice.');

    expect($words['don’t'])->toBe(['token' => 'don’t', 'count' => 1]);
});

it('keeps hyphenated and apostrophized words as one token', function () {
    $tokenizer = new WordTokenizer;

    $words = $tokenizer->tokenize("His mother-in-law's dog — well, MOTHER-IN-LAW again.");

    expect($words["mother-in-law's"])->toBe(['token' => "mother-in-law's", 'count' => 1]);
    expect($words['mother-in-law'])->toBe(['token' => 'MOTHER-IN-LAW', 'count' => 1]);
    expect($words['dog'])->toBe(['token' => 'dog', 'count' => 1]);
    expect($words)->toHaveKey('well');
});

it('strips punctuation and digits and skips short words', function () {
    $tokenizer = new WordTokenizer;

    $words = $tokenizer->tokenize("It's a 42 «word» — I... go 'a' b -c-");

    expect($words)->toHaveKey("it's");
    expect($words)->toHaveKey('word');
    expect($words)->toHaveKey('go');
    expect($words)->not->toHaveKey('a');
    expect($words)->not->toHaveKey('b');
    expect($words)->not->toHaveKey('c');
    expect($words)->not->toHaveKey('42');
    expect($words)->not->toHaveKey('i');
});

it('returns an empty array for text without letters', function () {
    $tokenizer = new WordTokenizer;

    expect($tokenizer->tokenize('123 ... «» --'))->toBe([]);
});
