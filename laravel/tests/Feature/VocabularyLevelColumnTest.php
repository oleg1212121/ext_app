<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Behavioral coverage for the users.vocabulary_level column (ticket:
 * cefr-word-test 01): fresh users start at 0, assignments persist, and mass
 * assignment works through $fillable. The raise-only submit rule that writes
 * the column lands with the WordTestService rework (ticket 02).
 */
it('defaults a fresh user\'s vocabulary level to 0', function () {
    $user = User::factory()->create();

    expect($user->refresh()->vocabulary_level)->toBe(0);
});

it('persists an assigned vocabulary level', function () {
    $user = User::factory()->create();

    $user->vocabulary_level = 12345;
    $user->save();

    expect($user->refresh()->vocabulary_level)->toBe(12345)
        ->and(User::query()->findOrFail($user->id)->vocabulary_level)->toBe(12345);
});

it('accepts the vocabulary level through mass assignment', function () {
    $user = User::factory()->create(['vocabulary_level' => 20000]);

    expect($user->refresh()->vocabulary_level)->toBe(20000);
});
