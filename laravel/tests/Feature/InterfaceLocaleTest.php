<?php

use App\Models\Language;
use App\Models\User;
use Illuminate\Support\Facades\App;

beforeEach(function () {
    createLanguages();
    // Tests set locale via the middleware; start each test from a clean slate.
    App::setLocale('en');
});

it('resolves the interface language from the explicit setting', function () {
    $ru = Language::where('code', 'ru')->first();
    $user = User::factory()->create();
    $user->settings()->updateOrCreate(['user_id' => $user->id], ['interface_language_id' => $ru->id]);

    $this->actingAs($user);
    $this->get('/profile');

    expect(App::getLocale())->toBe('ru');
});

it('falls back to the native language when no interface language is set', function () {
    $ru = Language::where('code', 'ru')->first();
    $user = User::factory()->create();
    $user->settings()->updateOrCreate(['user_id' => $user->id], ['native_language_id' => $ru->id]);

    $this->actingAs($user);
    $this->get('/profile');

    expect(App::getLocale())->toBe('ru');
});

it('falls back to English when neither setting resolves', function () {
    $user = User::factory()->create();
    $user->settings()->updateOrCreate(['user_id' => $user->id], []);

    $this->actingAs($user);
    $this->get('/profile');

    expect(App::getLocale())->toBe('en');
});

it('falls back to English when the native language is not interface-enabled', function () {
    $fr = Language::create([
        'code' => 'fr',
        'name' => 'French',
        'native_name' => 'Français',
        'is_enabled' => true,
        'is_interface_enabled' => false,
        'sort_order' => 2,
    ]);
    $user = User::factory()->create();
    $user->settings()->updateOrCreate(['user_id' => $user->id], ['native_language_id' => $fr->id]);

    $this->actingAs($user);
    $this->get('/profile');

    expect(App::getLocale())->toBe('en');
});

it('keeps guests on English', function () {
    $this->get('/login');

    expect(App::getLocale())->toBe('en');
});
