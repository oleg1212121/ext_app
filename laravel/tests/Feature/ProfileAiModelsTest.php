<?php

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\User;
use App\Models\UserApiKey;

function aiModelPreferencesUser(): array
{
    $user = User::factory()->create();
    $provider = AiProvider::factory()->enabled()->create(['key' => 'openrouter', 'name' => 'OpenRouter']);
    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $provider->id]);
    $answer = AiModel::factory()->create(['ai_provider_id' => $provider->id, 'external_id' => 'answer-model', 'name' => 'Answer Model', 'is_enabled' => true]);
    $explanation = AiModel::factory()->create(['ai_provider_id' => $provider->id, 'external_id' => 'explain-model', 'name' => 'Explain Model', 'is_enabled' => true]);

    return [$user, $answer, $explanation];
}

it('saves the answer and explanation model preferences', function () {
    [$user, $answer, $explanation] = aiModelPreferencesUser();

    $this
        ->actingAs($user)
        ->patch('/profile/ai-models', [
            'ai_model_id' => $answer->id,
            'explanation_model_id' => $explanation->id,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile?tab=ai');

    expect($user->settings->refresh()->ai_model_id)->toBe($answer->id)
        ->and($user->settings->refresh()->explanation_model_id)->toBe($explanation->id);
});

it('clears a model preference with null', function () {
    [$user, $answer] = aiModelPreferencesUser();
    $user->settings()->updateOrCreate(['user_id' => $user->id], ['ai_model_id' => $answer->id]);

    $this
        ->actingAs($user)
        ->patch('/profile/ai-models', [
            'ai_model_id' => null,
            'explanation_model_id' => null,
        ])
        ->assertSessionHasNoErrors();

    expect($user->settings->refresh()->ai_model_id)->toBeNull()
        ->and($user->settings->refresh()->explanation_model_id)->toBeNull();
});

it('rejects a disabled model id', function () {
    [$user] = aiModelPreferencesUser();
    $disabled = AiModel::factory()->create(['external_id' => 'disabled-model', 'is_enabled' => false]);

    $this
        ->actingAs($user)
        ->patch('/profile/ai-models', [
            'ai_model_id' => $disabled->id,
            'explanation_model_id' => null,
        ])
        ->assertSessionHasErrors('ai_model_id');

    expect($user->settings->refresh()->ai_model_id)->toBeNull();
});

it('rejects an unknown model id', function () {
    [$user] = aiModelPreferencesUser();

    $this
        ->actingAs($user)
        ->patch('/profile/ai-models', [
            'ai_model_id' => 999999,
            'explanation_model_id' => null,
        ])
        ->assertSessionHasErrors('ai_model_id');
});

it('leaves language settings untouched when saving model preferences', function () {
    [$user, $answer] = aiModelPreferencesUser();
    $originalNativeLanguageId = $user->settings->native_language_id;

    $this
        ->actingAs($user)
        ->patch('/profile/ai-models', [
            'ai_model_id' => $answer->id,
            'explanation_model_id' => null,
        ])
        ->assertSessionHasNoErrors();

    expect($user->settings->refresh()->native_language_id)->toBe($originalNativeLanguageId);
});

it('requires authentication', function () {
    $this->patch('/profile/ai-models', ['ai_model_id' => null])->assertRedirect('/login');
});

it('passes model choices and stored ids to the profile page', function () {
    [$user, $answer] = aiModelPreferencesUser();
    $user->settings()->updateOrCreate(['user_id' => $user->id], ['ai_model_id' => $answer->id]);

    $this
        ->actingAs($user)
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('aiModelId', $answer->id)
            ->where('explanationModelId', null)
            ->where('aiModelChoices.OpenRouter.0.id', $answer->id)
            ->where('aiModelChoices.OpenRouter.0.key', 'openrouter:answer-model')
        );
});
