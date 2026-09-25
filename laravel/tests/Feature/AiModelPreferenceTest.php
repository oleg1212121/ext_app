<?php

use App\Classes\AIModelResolver;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\User;
use App\Models\UserApiKey;

it('lists model choices only for keyed enabled providers, identified by ai_models.id', function () {
    $user = User::factory()->create();

    $provider = AiProvider::factory()->create(['key' => 'openrouter', 'name' => 'OpenRouter', 'is_enabled' => true]);
    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $provider->id]);
    AiModel::factory()->create(['ai_provider_id' => $provider->id, 'external_id' => 'pricy', 'name' => 'Pricy', 'pricing_prompt' => '0.000002', 'pricing_completion' => '0.000002', 'is_enabled' => true]);
    $cheap = AiModel::factory()->create(['ai_provider_id' => $provider->id, 'external_id' => 'cheap', 'name' => 'Cheap', 'pricing_prompt' => '0', 'pricing_completion' => '0', 'is_enabled' => true]);
    AiModel::factory()->create(['ai_provider_id' => $provider->id, 'external_id' => 'off', 'name' => 'Off', 'is_enabled' => false]);
    AiModel::factory()->create(['ai_provider_id' => $provider->id, 'external_id' => 'expired', 'name' => 'Expired', 'expiration_date' => now()->subDay(), 'is_enabled' => true]);

    // Enabled provider, but the user has no key for it.
    $unkeyed = AiProvider::factory()->create(['key' => 'gemini', 'name' => 'Gemini', 'is_enabled' => true]);
    AiModel::factory()->create(['ai_provider_id' => $unkeyed->id, 'external_id' => 'gem', 'name' => 'Gem', 'is_enabled' => true]);

    $this->actingAs($user);

    $choices = (new AIModelResolver)->getGroupedModelChoices();

    expect($choices)->toHaveKey('OpenRouter')
        ->and($choices)->not->toHaveKey('Gemini')
        ->and($choices['OpenRouter'])->toHaveCount(2)
        ->and($choices['OpenRouter'][0]['id'])->toBe($cheap->id)
        ->and($choices['OpenRouter'][0]['key'])->toBe('openrouter:cheap')
        ->and($choices['OpenRouter'][0]['label'])->toBe('Cheap (free)');
});

it('resolveAnswerModel returns null while nothing is picked, even with models available', function () {
    $user = User::factory()->create();
    $provider = AiProvider::factory()->create(['key' => 'openrouter', 'name' => 'OpenRouter', 'is_enabled' => true]);
    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $provider->id]);
    AiModel::factory()->create(['ai_provider_id' => $provider->id, 'external_id' => 'm', 'is_enabled' => true]);

    $this->actingAs($user);

    expect((new AIModelResolver)->resolveAnswerModel())->toBeNull();
});

it('resolveAnswerModel returns the stored pick when it is still available', function () {
    $user = User::factory()->create();
    $provider = AiProvider::factory()->create(['key' => 'openrouter', 'name' => 'OpenRouter', 'is_enabled' => true]);
    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $provider->id]);
    $picked = AiModel::factory()->create(['ai_provider_id' => $provider->id, 'external_id' => 'picked', 'is_enabled' => true]);
    AiModel::factory()->create(['ai_provider_id' => $provider->id, 'external_id' => 'cheap', 'pricing_prompt' => '0', 'pricing_completion' => '0', 'is_enabled' => true]);
    $user->settings()->updateOrCreate(['user_id' => $user->id], ['ai_model_id' => $picked->id]);

    $this->actingAs($user);

    expect((new AIModelResolver)->resolveAnswerModel()['id'])->toBe($picked->id);
});

it('resolveAnswerModel silently falls back to the cheapest available model when the pick is stale', function () {
    $user = User::factory()->create();
    $provider = AiProvider::factory()->create(['key' => 'openrouter', 'name' => 'OpenRouter', 'is_enabled' => true]);
    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $provider->id]);
    $stale = AiModel::factory()->create(['ai_provider_id' => $provider->id, 'external_id' => 'stale', 'is_enabled' => false]);
    $cheap = AiModel::factory()->create(['ai_provider_id' => $provider->id, 'external_id' => 'cheap', 'pricing_prompt' => '0', 'pricing_completion' => '0', 'is_enabled' => true]);
    $user->settings()->updateOrCreate(['user_id' => $user->id], ['ai_model_id' => $stale->id]);

    $this->actingAs($user);

    expect((new AIModelResolver)->resolveAnswerModel()['id'])->toBe($cheap->id);
});

it('resolveAnswerModel returns null when the user has no usable provider', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    expect((new AIModelResolver)->resolveAnswerModel())->toBeNull();
});

it('resolveExplanationModel follows the answer model when unset', function () {
    $user = User::factory()->create();
    $provider = AiProvider::factory()->create(['key' => 'openrouter', 'name' => 'OpenRouter', 'is_enabled' => true]);
    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $provider->id]);
    $answer = AiModel::factory()->create(['ai_provider_id' => $provider->id, 'external_id' => 'answer', 'pricing_prompt' => '0', 'pricing_completion' => '0', 'is_enabled' => true]);
    $user->settings()->updateOrCreate(['user_id' => $user->id], ['ai_model_id' => $answer->id]);

    $this->actingAs($user);

    expect((new AIModelResolver)->resolveExplanationModel()['id'])->toBe($answer->id);
    expect((new AIModelResolver)->explanationModelFollowsAnswer())->toBeTrue();
});

it('resolveExplanationModel returns the stored explanation pick', function () {
    $user = User::factory()->create();
    $provider = AiProvider::factory()->create(['key' => 'openrouter', 'name' => 'OpenRouter', 'is_enabled' => true]);
    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $provider->id]);
    $answer = AiModel::factory()->create(['ai_provider_id' => $provider->id, 'external_id' => 'answer', 'pricing_prompt' => '0', 'pricing_completion' => '0', 'is_enabled' => true]);
    $explanation = AiModel::factory()->create(['ai_provider_id' => $provider->id, 'external_id' => 'explain', 'pricing_prompt' => '0', 'pricing_completion' => '0', 'is_enabled' => true]);
    $user->settings()->updateOrCreate(['user_id' => $user->id], [
        'ai_model_id' => $answer->id,
        'explanation_model_id' => $explanation->id,
    ]);

    $this->actingAs($user);

    expect((new AIModelResolver)->resolveExplanationModel()['id'])->toBe($explanation->id);
    expect((new AIModelResolver)->explanationModelFollowsAnswer())->toBeFalse();
});

it('resolveExplanationModel follows the answer model when the explanation pick is stale', function () {
    $user = User::factory()->create();
    $provider = AiProvider::factory()->create(['key' => 'openrouter', 'name' => 'OpenRouter', 'is_enabled' => true]);
    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $provider->id]);
    $answer = AiModel::factory()->create(['ai_provider_id' => $provider->id, 'external_id' => 'answer', 'pricing_prompt' => '0', 'pricing_completion' => '0', 'is_enabled' => true]);
    $stale = AiModel::factory()->create(['ai_provider_id' => $provider->id, 'external_id' => 'stale', 'is_enabled' => false]);
    $user->settings()->updateOrCreate(['user_id' => $user->id], [
        'ai_model_id' => $answer->id,
        'explanation_model_id' => $stale->id,
    ]);

    $this->actingAs($user);

    expect((new AIModelResolver)->resolveExplanationModel()['id'])->toBe($answer->id);
    // A stale pick no longer counts as the user's own: it follows again.
    expect((new AIModelResolver)->explanationModelFollowsAnswer())->toBeTrue();
});

it('resolveExplanationModel falls back to the answer model fallback when the user removed the explanation provider key', function () {
    $user = User::factory()->create();

    $openrouter = AiProvider::factory()->create(['key' => 'openrouter', 'name' => 'OpenRouter', 'is_enabled' => true]);
    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $openrouter->id]);
    $cheap = AiModel::factory()->create(['ai_provider_id' => $openrouter->id, 'external_id' => 'cheap', 'pricing_prompt' => '0', 'pricing_completion' => '0', 'is_enabled' => true]);

    // Explanation model lives on a provider whose key the user no longer has.
    $orphaned = AiProvider::factory()->create(['key' => 'gemini', 'name' => 'Gemini', 'is_enabled' => true]);
    $explanation = AiModel::factory()->create(['ai_provider_id' => $orphaned->id, 'external_id' => 'g', 'is_enabled' => true]);

    $user->settings()->updateOrCreate(['user_id' => $user->id], [
        'ai_model_id' => $cheap->id,
        'explanation_model_id' => $explanation->id,
    ]);

    $this->actingAs($user);

    expect((new AIModelResolver)->resolveExplanationModel()['id'])->toBe($cheap->id);
});
