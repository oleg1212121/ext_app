<?php

use App\Classes\AIModelResolver;
use App\Classes\OpenRouter;
use App\Exceptions\AiProviderException;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\User;
use App\Models\UserApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('hides a provider whose ai_providers row is disabled', function () {
    $user = User::factory()->create();
    $provider = AiProvider::factory()->disabled()->create(['key' => 'openrouter', 'name' => 'OpenRouter']);
    AiModel::factory()->enabled()->create(['ai_provider_id' => $provider->id, 'external_id' => 'm/x', 'name' => 'M']);
    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $provider->id]);

    $this->actingAs($user);

    expect(app(AIModelResolver::class)->getGroupedModels())->toBeEmpty();
});

it('shows a provider the user has a key for', function () {
    $user = User::factory()->create();
    $provider = AiProvider::factory()->enabled()->create(['key' => 'openrouter', 'name' => 'OpenRouter']);
    AiModel::factory()->enabled()->create(['ai_provider_id' => $provider->id, 'external_id' => 'm/x', 'name' => 'M']);
    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $provider->id]);

    $this->actingAs($user);

    expect(app(AIModelResolver::class)->getGroupedModels())->toHaveKey('OpenRouter');
});

it('hides a provider the user has not keyed, even when enabled', function () {
    $user = User::factory()->create();
    $provider = AiProvider::factory()->enabled()->create(['key' => 'openrouter', 'name' => 'OpenRouter']);
    AiModel::factory()->enabled()->create(['ai_provider_id' => $provider->id, 'external_id' => 'm/x', 'name' => 'M']);

    $this->actingAs($user);

    expect(app(AIModelResolver::class)->getGroupedModels())->toBeEmpty();
});

it('sorts models within a provider by price ascending', function () {
    $user = User::factory()->create();
    $provider = AiProvider::factory()->enabled()->create(['key' => 'openrouter', 'name' => 'OpenRouter']);
    AiModel::factory()->enabled()->create(['ai_provider_id' => $provider->id, 'external_id' => 'expensive', 'name' => 'Expensive', 'pricing_prompt' => '0.01', 'pricing_completion' => '0.01']);
    AiModel::factory()->enabled()->create(['ai_provider_id' => $provider->id, 'external_id' => 'cheap', 'name' => 'Cheap', 'pricing_prompt' => '0', 'pricing_completion' => '0']);
    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $provider->id]);

    $this->actingAs($user);

    $models = app(AIModelResolver::class)->getGroupedModels()['OpenRouter'];

    expect(array_keys($models))->toBe(['openrouter:cheap', 'openrouter:expensive']);
});

it('orders provider groups by their cheapest model', function () {
    $user = User::factory()->create();

    $cheapProvider = AiProvider::factory()->enabled()->create(['key' => 'openrouter', 'name' => 'OpenRouter']);
    AiModel::factory()->enabled()->create(['ai_provider_id' => $cheapProvider->id, 'external_id' => 'c', 'name' => 'C', 'pricing_prompt' => '0', 'pricing_completion' => '0']);

    $priceyProvider = AiProvider::factory()->enabled()->create(['key' => 'gemini', 'name' => 'Gemini']);
    AiModel::factory()->enabled()->create(['ai_provider_id' => $priceyProvider->id, 'external_id' => 'p', 'name' => 'P', 'pricing_prompt' => '0.01', 'pricing_completion' => '0.01']);

    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $cheapProvider->id]);
    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $priceyProvider->id]);

    $this->actingAs($user);

    expect(array_keys(app(AIModelResolver::class)->getGroupedModels()))
        ->toBe(['OpenRouter', 'Gemini']);
});

it('firstModelKey returns the globally cheapest model', function () {
    $user = User::factory()->create();
    $provider = AiProvider::factory()->enabled()->create(['key' => 'openrouter', 'name' => 'OpenRouter']);
    AiModel::factory()->enabled()->create(['ai_provider_id' => $provider->id, 'external_id' => 'expensive', 'name' => 'Expensive', 'pricing_prompt' => '0.01', 'pricing_completion' => '0.01']);
    AiModel::factory()->enabled()->create(['ai_provider_id' => $provider->id, 'external_id' => 'cheap', 'name' => 'Cheap', 'pricing_prompt' => '0', 'pricing_completion' => '0']);
    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $provider->id]);

    $this->actingAs($user);

    expect(app(AIModelResolver::class)->firstModelKey())->toBe('openrouter:cheap');
});

it('ask throws when the user has no key for the provider', function () {
    $user = User::factory()->create();
    $provider = AiProvider::factory()->enabled()->create(['key' => 'openrouter', 'name' => 'OpenRouter']);
    AiModel::factory()->enabled()->create(['ai_provider_id' => $provider->id, 'external_id' => 'm/x', 'name' => 'M']);

    $this->actingAs($user);

    expect(fn () => app(AIModelResolver::class)->ask('openrouter:m/x', 'instruction', 'question'))
        ->toThrow(AiProviderException::class);
});

it('withApiKey returns a distinct instance carrying the given key', function () {
    $provider = new OpenRouter();
    $clone = $provider->withApiKey('secret');

    expect($clone)->not->toBe($provider);
    expect($clone->isConfigured())->toBeTrue();
    expect($provider->isConfigured())->toBeFalse();
});
