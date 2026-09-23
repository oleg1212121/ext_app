<?php

use App\Http\Controllers\Bilinguals\SimulatorController;
use App\Models\User;
use App\Models\UserSettings;
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

test('the simulator ships both sides languages and the side-rule default', function () {
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
            ->where('languages.a.code', 'en')
            ->where('languages.a.name', 'English')
            ->where('languages.b.code', 'ru')
            ->where('languages.b.name', 'Russian')
            // Factory users are native English speakers: the EN side is the
            // native one, so the rule reads the RU (B) side by default.
            ->where('defaultLearningSide', 'b')
            // The default question ships as a :base template, unsubstituted.
            ->where('questionTemplate', SimulatorController::DEFAULT_QUESTION)
            ->where('currentQuestion', null));
});

test('a customized question ships verbatim', function () {
    $user = User::factory()->create();
    $work = createWork();
    $match = createEntityMatch(
        createEntity('en', $work, ['name' => 'Pinned EN']),
        createEntity('ru', $work, ['name' => 'Pinned RU']),
        ['status' => 'completed'],
    );

    UserSettings::query()->updateOrCreate(
        ['user_id' => $user->id],
        ['ui_settings' => ['simulator' => ['question' => 'Grade my translation, be harsh.']]],
    );

    $this->actingAs($user)
        ->get("/bilinguals/simulator/{$match->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('currentQuestion', 'Grade my translation, be harsh.'));
});

test('a saved copy of the pre-template default question counts as not customized', function () {
    $user = User::factory()->create();
    $work = createWork();
    $match = createEntityMatch(
        createEntity('en', $work, ['name' => 'Pinned EN']),
        createEntity('ru', $work, ['name' => 'Pinned RU']),
        ['status' => 'completed'],
    );

    UserSettings::query()->updateOrCreate(
        ['user_id' => $user->id],
        ['ui_settings' => ['simulator' => ['question' => SimulatorController::LEGACY_DEFAULT_QUESTION]]],
    );

    $this->actingAs($user)
        ->get("/bilinguals/simulator/{$match->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('currentQuestion', null));
});
