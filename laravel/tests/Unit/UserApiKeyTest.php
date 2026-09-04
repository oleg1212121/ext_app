<?php

use App\Models\AiProvider;
use App\Models\User;
use App\Models\UserApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('encrypts the api key at rest and decrypts on read', function () {
    $key = UserApiKey::factory()->create(['api_key' => 'sk-secret']);

    $raw = DB::table('user_api_keys')->where('id', $key->id)->value('api_key');
    expect($raw)->not->toBe('sk-secret');
    expect($raw)->toBeString();

    expect($key->fresh()->api_key)->toBe('sk-secret');
});

it('allows only one key per provider per user', function () {
    $user = User::factory()->create();
    $provider = AiProvider::factory()->enabled()->create(['key' => 'openrouter']);
    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $provider->id]);

    try {
        DB::transaction(function () use ($user, $provider): void {
            UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $provider->id]);
        });
    } catch (Throwable) {
        // expected unique violation
    }

    expect(UserApiKey::where('user_id', $user->id)->where('ai_provider_id', $provider->id)->count())->toBe(1);
});

it('belongs to a user and a provider', function () {
    $user = User::factory()->create();
    $provider = AiProvider::factory()->enabled()->create(['key' => 'openrouter']);
    $key = UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $provider->id]);

    expect($key->user)->toBeInstanceOf(User::class)
        ->and($key->user->id)->toBe($user->id)
        ->and($key->aiProvider)->toBeInstanceOf(AiProvider::class)
        ->and($key->aiProvider->id)->toBe($provider->id);
});

it('cascades when the user is deleted', function () {
    $user = User::factory()->create();
    $provider = AiProvider::factory()->enabled()->create(['key' => 'openrouter']);
    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $provider->id]);

    $user->delete();

    expect(UserApiKey::count())->toBe(0);
});

it('cascades when the provider is deleted', function () {
    $user = User::factory()->create();
    $provider = AiProvider::factory()->enabled()->create(['key' => 'openrouter']);
    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $provider->id]);

    $provider->delete();

    expect(UserApiKey::count())->toBe(0);
});

it('masks the api key to first and last four characters', function () {
    $key = UserApiKey::factory()->create(['api_key' => 'sk-or-v1-my-open-router-key']);

    expect($key->masked())->toBe('sk-o••••-key');
});

it('falls back to a fully masked value for short keys', function () {
    $key = UserApiKey::factory()->create(['api_key' => 'tiny']);

    expect($key->masked())->toBe('••••');
});
