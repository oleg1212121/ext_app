<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('guests are redirected from the simulator picker', function () {
    $this->get('/simulator')->assertRedirect(route('login'));
});

test('the practice simulator ships the alignment picker', function () {
    $user = User::factory()->create();
    $work = createWork();
    $match = createEntityMatch(
        createEntity('en', $work, ['name' => 'Picker EN']),
        createEntity('ru', $work, ['name' => 'Picker RU']),
        ['status' => 'completed'],
    );

    $this->actingAs($user)
        ->get('/simulator')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Bilinguals/Bilinguals')
            ->where('pinnedMatch', null)
            ->where('textList.0.id', $match->id)
            ->where('textList.0.text', 'Picker EN / Picker RU')
            // The first match preselects in the picker.
            ->where('currentText', (string) $match->id));
});

test('the picker lists only matches the user can read', function () {
    $user = User::factory()->create();
    $work = createWork();
    createEntityMatch(
        createEntity('en', $work, ['name' => 'Secret EN', 'is_restricted' => true]),
        createEntity('ru', $work, ['name' => 'Secret RU', 'is_restricted' => true]),
        ['status' => 'completed'],
    );

    $this->actingAs($user)
        ->get('/simulator')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Bilinguals/Bilinguals')
            ->where('textList', []));
});

test('the text endpoint ships languages and the side-rule default for the picker', function () {
    $user = User::factory()->create();
    $work = createWork();
    $match = createEntityMatch(
        createEntity('en', $work, ['name' => 'Picker EN']),
        createEntity('ru', $work, ['name' => 'Picker RU']),
        ['status' => 'completed'],
    );

    $this->actingAs($user)
        ->post('/text', ['entity_match_id' => $match->id, 'per_page' => 50])
        ->assertOk()
        ->assertJsonPath('data.code', 200)
        ->assertJsonPath('data.data.languages.a.code', 'en')
        ->assertJsonPath('data.data.languages.b.code', 'ru')
        // Native-EN factory user: the side rule reads the RU (B) side.
        ->assertJsonPath('data.data.default_learning_side', 'b');
});
