<?php

use App\Models\WordTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('stores a reversed pair in canonical order via the creating hook', function () {
    $low = createWord('ru', 'ааа');
    $high = createWord('en', 'zzz');

    expect($low->id)->toBeLessThan($high->id);

    $link = WordTranslation::query()->create([
        'word_a_id' => $high->id,
        'word_b_id' => $low->id,
    ]);

    expect($link->fresh())
        ->word_a_id->toBe($low->id)
        ->word_b_id->toBe($high->id);
});

it('canonicalize returns the lower id first', function () {
    expect(WordTranslation::canonicalize(7, 3))->toBe([3, 7])
        ->and(WordTranslation::canonicalize(3, 7))->toBe([3, 7])
        ->and(WordTranslation::canonicalize(5, 5))->toBe([5, 5]);
});

it('isLinked detects a pair regardless of orientation', function () {
    $a = createWord('en', 'cat');
    $b = createWord('ru', 'кошка');

    expect(WordTranslation::isLinked($a->id, $b->id))->toBeFalse();

    WordTranslation::link($b->id, $a->id);

    expect(WordTranslation::isLinked($a->id, $b->id))->toBeTrue()
        ->and(WordTranslation::isLinked($b->id, $a->id))->toBeTrue();
});
