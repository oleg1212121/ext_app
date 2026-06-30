<?php

use App\Models\EnEntity;
use App\Models\RuEntity;
use App\Models\User;

test('guests are redirected from crossword react page', function () {
    $this->get(route('crossword.react', ['lang' => 'en']))
        ->assertRedirect(route('login'));
});

test('crossword react redirects bare path to english route', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/crossword-react')
        ->assertRedirect('/crossword-react/en');
});

test('authenticated users can view the crossword react page with default english entities', function () {
    $user = User::factory()->create();
    $enEntity = EnEntity::query()->create([
        'name' => 'Test EN Entity',
        'file_path' => 'texts/simulator/test_en.txt',
    ]);

    $this->actingAs($user)
        ->get(route('crossword.react', ['lang' => 'en']))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Crossword')
            ->where('lang', 'en')
            ->has('texts', 1)
            ->where('texts.0.id', $enEntity->id)
            ->where('texts.0.name', 'Test EN Entity'));
});

test('authenticated users can view the crossword react page with russian entities', function () {
    $user = User::factory()->create();
    $ruEntity = RuEntity::query()->create([
        'name' => 'Test RU Entity',
        'file_path' => 'texts/simulator/test_ru.txt',
    ]);

    $this->actingAs($user)
        ->get(route('crossword.react', ['lang' => 'ru']))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Crossword')
            ->where('lang', 'ru')
            ->has('texts', 1)
            ->where('texts.0.id', $ruEntity->id)
            ->where('texts.0.name', 'Test RU Entity'));
});

test('unsupported crossword react language returns not found', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/crossword-react/de')
        ->assertNotFound();
});

test('authenticated users can view the blade crossword page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('crossword'))
        ->assertSuccessful()
        ->assertSee('Crossword Puzzle', false);
});
