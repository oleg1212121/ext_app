<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('guests are redirected from the simulator picker', function () {
    $this->get('/simulator')->assertRedirect(route('login'));
});

test('the practice simulator ships the work-grouped picker', function () {
    $user = User::factory()->create();
    $work = createWork(['title' => 'Alice in Wonderland', 'author' => 'Lewis Carroll']);
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
            ->where('workGroups.0.id', $work->id)
            ->where('workGroups.0.label', 'Alice in Wonderland — Lewis Carroll')
            ->where('workGroups.0.options.0.id', $match->id)
            ->where('workGroups.0.options.0.text', 'Picker EN / Picker RU')
            // The first option preselects in the picker.
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
            ->where('workGroups', []));
});

test('the picker lists only completed and stale matches', function () {
    $user = User::factory()->create();
    $work = createWork();
    $completed = createEntityMatch(
        createEntity('en', $work, ['name' => 'Done EN']),
        createEntity('ru', $work, ['name' => 'Done RU']),
        ['status' => 'completed'],
    );
    $stale = createEntityMatch(
        createEntity('en', $work, ['name' => 'Stale EN']),
        createEntity('ru', $work, ['name' => 'Stale RU']),
        ['status' => 'stale'],
    );
    createEntityMatch(
        createEntity('en', $work, ['name' => 'Running EN']),
        createEntity('ru', $work, ['name' => 'Running RU']),
        ['status' => 'pending'],
    );
    createEntityMatch(
        createEntity('en', $work, ['name' => 'Broken EN']),
        createEntity('ru', $work, ['name' => 'Broken RU']),
        ['status' => 'failed'],
    );

    $this->actingAs($user)
        ->get('/simulator')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // Newest qualifying match leads within the work.
            ->where('workGroups.0.options.0.id', $stale->id)
            ->where('workGroups.0.options.1.id', $completed->id)
            ->where('currentText', (string) $stale->id));
});

test('the picker groups matches under their works, sorted A to Z', function () {
    $user = User::factory()->create();
    $zebra = createWork(['title' => 'Zebra', 'author' => 'Leo Tolstoy']);
    createEntityMatch(
        createEntity('en', $zebra, ['name' => 'Zebra EN']),
        createEntity('ru', $zebra, ['name' => 'Zebra RU']),
        ['status' => 'completed'],
    );
    // Created later but sorts first by title.
    $aardvark = createWork(['title' => 'Aardvark']);
    $aardvarkMatch = createEntityMatch(
        createEntity('en', $aardvark, ['name' => 'Aardvark EN']),
        createEntity('ru', $aardvark, ['name' => 'Aardvark RU']),
        ['status' => 'completed'],
    );
    // A work holding no readable, completed/stale alignment never shows.
    $hollow = createWork(['title' => 'Hollow']);
    createEntityMatch(
        createEntity('en', $hollow, ['name' => 'Hollow EN']),
        createEntity('ru', $hollow, ['name' => 'Hollow RU']),
        ['status' => 'aligning'],
    );

    $this->actingAs($user)
        ->get('/simulator')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('workGroups.0.id', $aardvark->id)
            ->where('workGroups.0.label', 'Aardvark')
            ->where('workGroups.0.options.0.id', $aardvarkMatch->id)
            ->where('workGroups.1.label', 'Zebra — Leo Tolstoy')
            ->where('currentText', (string) $aardvarkMatch->id));
});
