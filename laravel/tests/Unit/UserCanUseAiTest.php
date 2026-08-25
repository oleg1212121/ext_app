<?php

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\User;
use App\Models\UserApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('can use ai with a key for an enabled provider that has an enabled model', function () {
    $user = User::factory()->create();
    $provider = AiProvider::factory()->enabled()->create(['key' => 'openrouter', 'name' => 'OpenRouter']);
    AiModel::factory()->enabled()->create([
        'ai_provider_id' => $provider->id,
        'external_id' => 'x',
        'name' => 'X',
        'pricing_prompt' => '0',
        'pricing_completion' => '0',
    ]);
    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $provider->id]);

    expect($user->canUseAi())->toBeTrue();
});

it('cannot use ai with a key only for a disabled provider', function () {
    $user = User::factory()->create();
    $provider = AiProvider::factory()->create(['key' => 'openrouter', 'name' => 'OpenRouter', 'is_enabled' => false]);
    AiModel::factory()->enabled()->create([
        'ai_provider_id' => $provider->id,
        'external_id' => 'x',
        'name' => 'X',
        'pricing_prompt' => '0',
        'pricing_completion' => '0',
    ]);
    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $provider->id]);

    expect($user->canUseAi())->toBeFalse();
});

it('cannot use ai with a key for an enabled provider whose models are all disabled', function () {
    $user = User::factory()->create();
    $provider = AiProvider::factory()->enabled()->create(['key' => 'openrouter', 'name' => 'OpenRouter']);
    AiModel::factory()->create([
        'ai_provider_id' => $provider->id,
        'external_id' => 'x',
        'name' => 'X',
        'pricing_prompt' => '0',
        'pricing_completion' => '0',
        'is_enabled' => false,
    ]);
    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $provider->id]);

    expect($user->canUseAi())->toBeFalse();
});

it('cannot use ai without any stored key', function () {
    $user = User::factory()->create();
    $provider = AiProvider::factory()->enabled()->create(['key' => 'openrouter', 'name' => 'OpenRouter']);
    AiModel::factory()->enabled()->create([
        'ai_provider_id' => $provider->id,
        'external_id' => 'x',
        'name' => 'X',
        'pricing_prompt' => '0',
        'pricing_completion' => '0',
    ]);

    expect($user->canUseAi())->toBeFalse();
});

it('can use ai when at least one of several keyed providers is usable', function () {
    $user = User::factory()->create();
    $disabled = AiProvider::factory()->create(['key' => 'gemini', 'name' => 'Gemini', 'is_enabled' => false]);
    $enabled = AiProvider::factory()->enabled()->create(['key' => 'openrouter', 'name' => 'OpenRouter']);
    AiModel::factory()->enabled()->create([
        'ai_provider_id' => $enabled->id,
        'external_id' => 'x',
        'name' => 'X',
        'pricing_prompt' => '0',
        'pricing_completion' => '0',
    ]);
    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $disabled->id]);
    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $enabled->id]);

    expect($user->canUseAi())->toBeTrue();
});
