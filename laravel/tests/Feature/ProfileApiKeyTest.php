<?php

use App\Models\AiProvider;
use App\Models\User;
use App\Models\UserApiKey;
use Illuminate\Support\Facades\DB;

it('lists enabled providers with a masked key preview and never echoes the full key', function () {
    $user = User::factory()->create();
    $provider = AiProvider::factory()->enabled()->create(['key' => 'openrouter', 'name' => 'OpenRouter']);
    AiProvider::factory()->disabled()->create(['key' => 'gemini', 'name' => 'Gemini']);
    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $provider->id, 'api_key' => 'super-secret']);

    $this->actingAs($user)
        ->get('/profile')
        ->assertOk()
        ->assertDontSee('super-secret')
        ->assertInertia(fn ($page) => $page
            ->where('apiKeyProviders.0.key', 'openrouter')
            ->where('apiKeyProviders.0.has_key', true)
            ->where('apiKeyProviders.0.masked_key', 'supe••••cret')
            ->missing('apiKeyProviders.1')
        );
});

it('loads the profile even when a stored key can no longer be decrypted', function () {
    $user = User::factory()->create();
    $provider = AiProvider::factory()->enabled()->create(['key' => 'openrouter']);

    // Insert a raw, non-encrypted payload so the `encrypted` cast fails to decrypt it
    // (simulates a row encrypted under a rotated APP_KEY).
    DB::table('user_api_keys')->insert([
        'user_id' => $user->id,
        'ai_provider_id' => $provider->id,
        'api_key' => 'not-a-valid-encrypted-payload',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('apiKeyProviders.0.has_key', true)
            ->where('apiKeyProviders.0.masked_key', '(decryption failed)')
        );
});

it('stores an encrypted api key for an enabled provider', function () {
    $user = User::factory()->create();
    $provider = AiProvider::factory()->enabled()->create(['key' => 'openrouter']);

    $this->actingAs($user)
        ->post('/profile/api-keys', ['provider' => 'openrouter', 'api_key' => 'my-new-key'])
        ->assertRedirect('/profile');

    $stored = UserApiKey::where('user_id', $user->id)->where('ai_provider_id', $provider->id)->first();
    expect($stored)->not->toBeNull();
    expect($stored->api_key)->toBe('my-new-key');
});

it('replaces an existing key on save', function () {
    $user = User::factory()->create();
    $provider = AiProvider::factory()->enabled()->create(['key' => 'openrouter']);
    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $provider->id, 'api_key' => 'old-key-123']);

    $this->actingAs($user)
        ->post('/profile/api-keys', ['provider' => 'openrouter', 'api_key' => 'new-key-123'])
        ->assertRedirect('/profile');

    expect(UserApiKey::where('user_id', $user->id)->count())->toBe(1);
    expect(UserApiKey::where('user_id', $user->id)->first()->api_key)->toBe('new-key-123');
});

it('rejects a key for a disabled provider', function () {
    $user = User::factory()->create();
    AiProvider::factory()->disabled()->create(['key' => 'gemini']);

    $this->actingAs($user)
        ->post('/profile/api-keys', ['provider' => 'gemini', 'api_key' => 'whatever'])
        ->assertSessionHasErrors('provider');
});

it('rejects an empty key', function () {
    $user = User::factory()->create();
    AiProvider::factory()->enabled()->create(['key' => 'openrouter']);

    $this->actingAs($user)
        ->post('/profile/api-keys', ['provider' => 'openrouter', 'api_key' => ''])
        ->assertSessionHasErrors('api_key');
});

it('removes the stored key', function () {
    $user = User::factory()->create();
    $provider = AiProvider::factory()->enabled()->create(['key' => 'openrouter']);
    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $provider->id]);

    $this->actingAs($user)
        ->delete('/profile/api-keys/openrouter')
        ->assertRedirect('/profile');

    expect(UserApiKey::where('user_id', $user->id)->exists())->toBeFalse();
});
