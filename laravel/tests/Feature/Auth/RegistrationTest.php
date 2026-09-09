<?php

use App\Models\Language;
use App\Models\User;

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('new users can register', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect('/');
});

test('registration creates user settings with English native language by default', function () {
    $english = Language::create(['code' => 'en', 'name' => 'English', 'is_enabled' => true, 'sort_order' => 0]);

    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::query()->where('email', 'test@example.com')->first();

    expect($user->settings)->not->toBeNull();
    expect($user->settings->native_language_id)->toBe($english->id);
    expect($user->nativeLanguage()->code)->toBe('en');
});

test('registration honours the chosen native language', function () {
    $english = Language::create(['code' => 'en', 'name' => 'English', 'is_enabled' => true, 'sort_order' => 0]);
    $russian = Language::create(['code' => 'ru', 'name' => 'Russian', 'is_enabled' => true, 'sort_order' => 1]);

    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'native_language_id' => $russian->id,
    ]);

    $user = User::query()->where('email', 'test@example.com')->first();

    expect($user->settings->native_language_id)->toBe($russian->id);
});

test('registration rejects a disabled native language', function () {
    Language::create(['code' => 'en', 'name' => 'English', 'is_enabled' => true, 'sort_order' => 0]);
    $disabled = Language::create(['code' => 'fr', 'name' => 'French', 'is_enabled' => false, 'sort_order' => 2]);

    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'native_language_id' => $disabled->id,
    ]);

    $response->assertSessionHasErrors('native_language_id');
    expect(User::query()->where('email', 'test@example.com')->exists())->toBeFalse();
});
