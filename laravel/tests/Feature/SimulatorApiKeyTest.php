<?php

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\User;
use App\Models\UserApiKey;

it('chooses the cheapest available model as the default', function () {
    $user = User::factory()->create();
    $provider = AiProvider::factory()->enabled()->create(['key' => 'openrouter', 'name' => 'OpenRouter']);
    AiModel::factory()->enabled()->create(['ai_provider_id' => $provider->id, 'external_id' => 'expensive', 'name' => 'Expensive', 'pricing_prompt' => '0.01', 'pricing_completion' => '0.01']);
    AiModel::factory()->enabled()->create(['ai_provider_id' => $provider->id, 'external_id' => 'cheap', 'name' => 'Cheap', 'pricing_prompt' => '0', 'pricing_completion' => '0']);
    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $provider->id]);

    $this->actingAs($user)
        ->get('/bilinguals/en/ru/simulator')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('currentModel', 'openrouter:cheap')
            ->has('aiModels.OpenRouter')
        );
});

it('renders an empty state when the user has no keys', function () {
    $user = User::factory()->create();
    $provider = AiProvider::factory()->enabled()->create(['key' => 'openrouter', 'name' => 'OpenRouter']);
    AiModel::factory()->enabled()->create(['ai_provider_id' => $provider->id, 'external_id' => 'x', 'name' => 'X']);

    $this->actingAs($user)
        ->get('/bilinguals/en/ru/simulator')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('currentModel', null)
            ->where('aiModels', [])
        );
});

it('only lists providers the user has a key for', function () {
    $user = User::factory()->create();
    $keyed = AiProvider::factory()->enabled()->create(['key' => 'openrouter', 'name' => 'OpenRouter']);
    $other = AiProvider::factory()->enabled()->create(['key' => 'gemini', 'name' => 'Gemini']);
    AiModel::factory()->enabled()->create(['ai_provider_id' => $keyed->id, 'external_id' => 'c', 'name' => 'C']);
    AiModel::factory()->enabled()->create(['ai_provider_id' => $other->id, 'external_id' => 'g', 'name' => 'G']);
    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $keyed->id]);

    $this->actingAs($user)
        ->get('/bilinguals/en/ru/simulator')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('aiModels.OpenRouter')
            ->missing('aiModels.Gemini')
        );
});
