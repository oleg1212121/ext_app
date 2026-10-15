<?php

use App\Models\Language;
use App\Models\User;
use Database\Seeders\LanguageSeeder;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('/profile');

    $response->assertOk();
});

test('profile page shares the popups tab and all-visible defaults', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Profile/Edit')
            ->where('popupVisibility.familiarity', true)
            ->where('popupVisibility.progress_actions', true)
            ->where('popupVisibility.form_of', true)
            ->where('popupVisibility.word_family', true)
            ->where('popupVisibility.frequency', true)
            ->where('popupVisibility.transcriptions', true)
            ->where('popupVisibility.definitions', true)
            ->where('popupVisibility.translations', true)
            ->where('popupVisibility.examples', true)
            ->where('popupVisibility.etymologies', true)
            ->where('popupVisibility.explanation', true));
});

test('profile page shares anki settings with every card field on by default', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('ankiSettings.url', 'http://127.0.0.1')
            ->where('ankiSettings.port', 8765)
            ->where('ankiSettings.deck', 'ExtApp')
            ->where('ankiSettings.fields.word', true)
            ->where('ankiSettings.fields.transcription', true)
            ->where('ankiSettings.fields.part_of_speech', true)
            ->where('ankiSettings.fields.frequency', true)
            ->where('ankiSettings.fields.definitions', true)
            ->where('ankiSettings.fields.translations', true)
            ->where('ankiSettings.fields.examples', true)
            ->where('ankiSettings.fields.etymology', true)
            ->where('ankiSettings.fields.word_family', true)
            ->where('ankiSettings.fields.tags', true)
            ->where('ankiSettings.fields.familiarity', true)
            ->where('ankiSettings.fields.language', true)
            ->where('ankiSettings.fields.body', true));
});

test('anki settings prop reflects the saved connection and field opt-outs', function () {
    $user = User::factory()->create();
    $user->settings()->updateOrCreate([], [
        'ui_settings' => ['anki' => [
            'url' => 'http://localhost',
            'port' => 8766,
            'fields' => ['etymology' => false],
        ]],
    ]);

    $this
        ->actingAs($user)
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('ankiSettings.url', 'http://localhost')
            ->where('ankiSettings.port', 8766)
            // Keys never touched keep their defaults.
            ->where('ankiSettings.deck', 'ExtApp')
            ->where('ankiSettings.fields.etymology', false)
            ->where('ankiSettings.fields.word', true));
});

test('popup visibility prop reflects the saved opt-outs', function () {
    $user = User::factory()->create();
    $user->settings()->updateOrCreate([], [
        'ui_settings' => ['popup' => ['frequency' => false, 'examples' => false]],
    ]);

    $this
        ->actingAs($user)
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('popupVisibility.frequency', false)
            ->where('popupVisibility.examples', false)
            ->where('popupVisibility.definitions', true));
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

test('user settings accepts belarusian as the interface language', function () {
    // The seeded registry makes be an interface language; the validation rule
    // reads the registry, so the same PATCH must succeed for the be row.
    app(LanguageSeeder::class)->run();
    $be = Language::query()->where('code', 'be')->first();
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->patch('/profile/settings', ['interface_language_id' => $be->id])
        ->assertSessionHasNoErrors();

    expect($user->settings->refresh()->interface_language_id)->toBe($be->id);
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
