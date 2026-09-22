<?php

use App\Classes\AIModelResolver;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\User;

it('refuses an AI question when the user has no available answer model', function () {
    $user = User::factory()->create();

    // An enabled provider with an enabled model exists, but the user has no
    // key for it — no model is available, so the request is refused with the
    // choose-a-model guidance (real resolver, no provider call happens).
    $provider = AiProvider::factory()->enabled()->create(['key' => 'openrouter', 'name' => 'OpenRouter']);
    AiModel::factory()->enabled()->create([
        'ai_provider_id' => $provider->id,
        'external_id' => 'google/gemini-3.1-flash-lite-preview',
        'name' => 'Gemini Flash',
    ]);

    $this->actingAs($user)
        ->postJson('/ai/question', [
            'data' => 'Russian line',
            'question' => '',
        ])
        ->assertStatus(400)
        ->assertJsonPath('data.data.error', 'Choose an AI model in your profile settings.');
});

it('asks with the answer model the resolver fell back to after the picked provider key was removed', function () {
    $user = User::factory()->create();

    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('resolveAnswerModel')
        ->once()
        ->andReturn(['id' => 3, 'key' => 'gemini:cheap-model', 'label' => 'Cheap Model']);
    $mock->shouldReceive('ask')
        ->once()
        ->with('gemini:cheap-model', '', 'Russian line')
        ->andReturn('Fallback answer');

    $this->app->instance(AIModelResolver::class, $mock);

    // A stored pick whose provider key is gone is stale; the controller must
    // use whatever resolveAnswerModel returns (the fallback), never a
    // client-supplied model.
    $stale = AiModel::factory()->enabled()->create(['external_id' => 'stale-model', 'name' => 'Stale Model']);
    $user->settings()->updateOrCreate(['user_id' => $user->id], ['ai_model_id' => $stale->id]);
    $stale->update(['is_enabled' => false]);

    $this->actingAs($user)
        ->postJson('/ai/question', [
            'data' => 'Russian line',
            'question' => '',
        ])
        ->assertOk()
        ->assertJsonPath('data.answer', 'Fallback answer');
});
