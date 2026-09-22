<?php

use App\Models\Language;
use App\Models\User;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('/profile');

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $user->refresh();

    $this->assertSame('Test User', $user->name);
    $this->assertSame('test@example.com', $user->email);
    $this->assertNull($user->email_verified_at);
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $this->assertNotNull($user->refresh()->email_verified_at);
});

test('user settings can update the native language', function () {
    $user = User::factory()->create();
    $russian = Language::create(['code' => 'ru', 'name' => 'Russian', 'is_enabled' => true, 'sort_order' => 1]);

    $response = $this
        ->actingAs($user)
        ->patch('/profile/settings', [
            'native_language_id' => $russian->id,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile?tab=preferences');

    expect($user->settings->refresh()->native_language_id)->toBe($russian->id);
});

test('user settings rejects a disabled native language', function () {
    $user = User::factory()->create();
    $disabled = Language::create(['code' => 'fr', 'name' => 'French', 'is_enabled' => false, 'sort_order' => 2]);

    $originalNativeLanguageId = $user->settings->native_language_id;

    $response = $this
        ->actingAs($user)
        ->patch('/profile/settings', [
            'native_language_id' => $disabled->id,
        ]);

    $response->assertSessionHasErrors('native_language_id');
    expect($user->settings->refresh()->native_language_id)->toBe($originalNativeLanguageId);
});

test('user settings can set and clear the interface language', function () {
    $user = User::factory()->create();
    $interface = Language::create([
        'code' => 'de',
        'name' => 'German',
        'is_enabled' => true,
        'is_interface_enabled' => true,
        'sort_order' => 2,
    ]);

    $this
        ->actingAs($user)
        ->patch('/profile/settings', ['interface_language_id' => $interface->id])
        ->assertSessionHasNoErrors();

    expect($user->settings->refresh()->interface_language_id)->toBe($interface->id);

    $this
        ->actingAs($user)
        ->patch('/profile/settings', ['interface_language_id' => null])
        ->assertSessionHasNoErrors();

    expect($user->settings->refresh()->interface_language_id)->toBeNull();
});

test('user settings rejects a language that is not interface-enabled', function () {
    $user = User::factory()->create();
    $contentOnly = Language::create([
        'code' => 'fr',
        'name' => 'French',
        'is_enabled' => true,
        'is_interface_enabled' => false,
        'sort_order' => 2,
    ]);

    $this
        ->actingAs($user)
        ->patch('/profile/settings', ['interface_language_id' => $contentOnly->id])
        ->assertSessionHasErrors('interface_language_id');

    expect($user->settings->refresh()->interface_language_id)->toBeNull();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete('/profile', [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
    $this->assertNull($user->fresh());
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->delete('/profile', [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrorsIn('userDeletion', 'password')
        ->assertRedirect('/profile');

    $this->assertNotNull($user->fresh());
});
