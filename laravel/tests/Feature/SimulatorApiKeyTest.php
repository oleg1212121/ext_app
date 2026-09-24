<?php

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\User;
use App\Models\UserApiKey;

it('shows no answer model while the user has not picked one', function () {
    $user = User::factory()->create();
    $match = createSimulatorMatch();
    $provider = AiProvider::factory()->enabled()->create(['key' => 'openrouter', 'name' => 'OpenRouter']);
    AiModel::factory()->enabled()->create(['ai_provider_id' => $provider->id, 'external_id' => 'expensive', 'name' => 'Expensive', 'pricing_prompt' => '0.01', 'pricing_completion' => '0.01']);
    AiModel::factory()->enabled()->create(['ai_provider_id' => $provider->id, 'external_id' => 'cheap', 'name' => 'Cheap', 'pricing_prompt' => '0', 'pricing_completion' => '0']);
    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $provider->id]);

    $this->actingAs($user)
        ->get("/bilinguals/simulator/{$match->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('answerModel', null)
            ->where('canUseAi', true)
            ->where('showAI', true)
            ->missing('aiModels')
            ->missing('currentModel')
        );
});

it('shows the stored answer model and explanation model id', function () {
    $user = User::factory()->create();
    $match = createSimulatorMatch();
    $provider = AiProvider::factory()->enabled()->create(['key' => 'openrouter', 'name' => 'OpenRouter']);
    $cheap = AiModel::factory()->enabled()->create(['ai_provider_id' => $provider->id, 'external_id' => 'cheap', 'name' => 'Cheap', 'pricing_prompt' => '0', 'pricing_completion' => '0']);
    $fancy = AiModel::factory()->enabled()->create(['ai_provider_id' => $provider->id, 'external_id' => 'fancy', 'name' => 'Fancy', 'pricing_prompt' => '0.5', 'pricing_completion' => '0.5']);
    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $provider->id]);
    $user->settings()->updateOrCreate(['user_id' => $user->id], [
        'ai_model_id' => $cheap->id,
        'explanation_model_id' => $fancy->id,
    ]);

    $this->actingAs($user)
        ->get("/bilinguals/simulator/{$match->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('answerModel.id', $cheap->id)
            ->where('answerModel.label', 'Cheap (free)')
            ->where('explanationModelKey', $fancy->id)
        );
});

it('shows the fallback model when the stored pick is no longer available', function () {
    $user = User::factory()->create();
    $match = createSimulatorMatch();
    $provider = AiProvider::factory()->enabled()->create(['key' => 'openrouter', 'name' => 'OpenRouter']);
    $stale = AiModel::factory()->enabled()->create(['ai_provider_id' => $provider->id, 'external_id' => 'stale', 'name' => 'Stale', 'pricing_prompt' => '0.5', 'pricing_completion' => '0.5']);
    $cheap = AiModel::factory()->enabled()->create(['ai_provider_id' => $provider->id, 'external_id' => 'cheap', 'name' => 'Cheap', 'pricing_prompt' => '0', 'pricing_completion' => '0']);
    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $provider->id]);
    $user->settings()->updateOrCreate(['user_id' => $user->id], ['ai_model_id' => $stale->id]);
    $stale->update(['is_enabled' => false]);

    $this->actingAs($user)
        ->get("/bilinguals/simulator/{$match->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('answerModel.id', $cheap->id)
            ->where('explanationModelKey', $cheap->id)
        );
});

it('renders an empty state when the user has no keys', function () {
    $user = User::factory()->create();
    $match = createSimulatorMatch();
    $provider = AiProvider::factory()->enabled()->create(['key' => 'openrouter', 'name' => 'OpenRouter']);
    AiModel::factory()->enabled()->create(['ai_provider_id' => $provider->id, 'external_id' => 'x', 'name' => 'X']);

    $this->actingAs($user)
        ->get("/bilinguals/simulator/{$match->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('answerModel', null)
            ->where('explanationModelKey', null)
            ->where('canUseAi', false)
            // The panel itself is not AI-gated anymore: keyless users see it
            // with the add-an-API-key call to action in its header.
            ->where('showAI', true)
        );
});
