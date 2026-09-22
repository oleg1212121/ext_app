<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('the pinned simulator route preloads the match from the url', function () {
    $user = User::factory()->create();
    $work = createWork();
    $match = createEntityMatch(
        createEntity('en', $work, ['name' => 'Pinned EN']),
        createEntity('ru', $work, ['name' => 'Pinned RU']),
        ['status' => 'completed'],
    );

    $this->actingAs($user)
        ->get("/bilinguals/simulator/{$match->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Bilinguals/Bilinguals')
            ->where('currentText', (string) $match->id)
            ->where('pinnedMatch.id', $match->id)
            ->where('pinnedMatch.text', 'Pinned EN / Pinned RU')
            ->missing('textList'));
});

test('the pinned simulator route is forbidden without access to both sides', function () {
    $user = User::factory()->create();
    $work = createWork();
    $match = createEntityMatch(
        createEntity('en', $work, ['name' => 'Secret EN', 'is_restricted' => true]),
        createEntity('ru', $work, ['name' => 'Secret RU', 'is_restricted' => true]),
        ['status' => 'completed'],
    );

    $this->actingAs($user)
        ->get("/bilinguals/simulator/{$match->id}")
        ->assertForbidden();
});

test('an unknown match id on the pinned simulator route is not found', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/bilinguals/simulator/999999')
        ->assertNotFound();
});

test('the old picker simulator page is gone', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/bilinguals/en/ru/simulator')
        ->assertNotFound();
});
