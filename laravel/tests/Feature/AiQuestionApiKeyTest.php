<?php

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\User;

it('rejects an AI question when the user has no key for the provider', function () {
    $user = User::factory()->create();
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
            'model' => 'openrouter:google/gemini-3.1-flash-lite-preview',
        ])
        ->assertForbidden()
        ->assertJsonPath('data.data.error', 'You have not added an API key for this provider.');
});
