<?php

use App\Models\User;
use App\Support\UiStrings;
use Database\Seeders\UiStringSeeder;

beforeEach(function () {
    createLanguages();
});

it('shares the native language code with authenticated pages', function () {
    $langs = createLanguages();
    $user = User::factory()->create();
    $user->settings()->updateOrCreate(['user_id' => $user->id], ['native_language_id' => $langs['ru']->id]);

    $props = $this->actingAs($user)->get('/profile')->assertOk()->inertiaPage()['props'];

    // The pronunciation reference modal keys its default tab off this prop.
    expect($props['auth']['user']['native_language'])->toBe('ru');
});

it('shares a null native language when none is set', function () {
    $user = User::factory()->create();
    // The factory defaults the native language to English; clear it and
    // drop the pre-loaded relation so the request sees the updated row.
    $user->settings()->updateOrCreate(['user_id' => $user->id], ['native_language_id' => null]);
    $user->unsetRelation('settings');

    $props = $this->actingAs($user)->get('/profile')->assertOk()->inertiaPage()['props'];

    expect($props['auth']['user']['native_language'])->toBeNull();
});

it('serves the pronunciation reference UI strings in both locales', function () {
    $this->seed(UiStringSeeder::class);

    $props = $this->get('/')->assertOk()->inertiaPage()['props'];

    expect($props['uiStrings']['sounds.title'])->toBe('Pronunciation guide')
        ->and($props['uiStrings']['nav.pronunciation_reference'])->toBe('Pronunciation guide')
        ->and(UiStrings::mapFor('ru')['sounds.title'])->toBe('Справочник произношения')
        ->and(UiStrings::mapFor('ru')['nav.pronunciation_reference'])->toBe('Справочник произношения');
});
