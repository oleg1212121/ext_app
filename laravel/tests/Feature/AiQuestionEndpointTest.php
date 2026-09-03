<?php

use App\Classes\AIModelResolver;
use App\Exceptions\AiProviderException;
use App\Models\User;
use InvalidArgumentException;

it('accepts an empty question without server error', function () {
    $user = User::factory()->create();

    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('isValidModel')
        ->andReturn(true);
    $mock->shouldReceive('ask')
        ->once()
        ->with(
            'openrouter:google/gemini-3-flash-preview',
            '',
            "Russian line\nEnglish line",
        )
        ->andReturn('Test answer');

    $this->app->instance(AIModelResolver::class, $mock);

    $response = $this->actingAs($user)->postJson('/ai/question', [
        'data' => "Russian line\nEnglish line",
        'question' => '',
        'model' => 'openrouter:google/gemini-3-flash-preview',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.code', 200)
        ->assertJsonPath('data.answer', 'Test answer');
});

it('surfaces a provider error as a friendly message without leaking internals', function () {
    $user = User::factory()->create();

    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('isValidModel')
        ->andReturn(true);
    $mock->shouldReceive('ask')
        ->once()
        ->andThrow(new AiProviderException(
            'The AI service is busy. Please try again in a moment.',
            429,
        ));

    $this->app->instance(AIModelResolver::class, $mock);

    $response = $this->actingAs($user)->postJson('/ai/question', [
        'data' => "Russian line\nEnglish line",
        'question' => '',
        'model' => 'openrouter:google/gemini-3-flash-preview',
    ]);

    $response->assertStatus(429)
        ->assertJsonPath('data.code', 429)
        ->assertJsonPath('data.data.error', 'The AI service is busy. Please try again in a moment.');

    // Raw provider internals must never reach the client.
    $payload = json_encode($response->json());
    expect($payload)->not->toContain('user_id')
        ->and($payload)->not->toContain('openrouter.ai')
        ->and($payload)->not->toContain('retry_after');
});

it('returns a friendly message when the model resolver rejects the model', function () {
    $user = User::factory()->create();

    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('isValidModel')
        ->andReturn(true);
    $mock->shouldReceive('ask')
        ->once()
        ->andThrow(new InvalidArgumentException('Unknown provider: foo'));

    $this->app->instance(AIModelResolver::class, $mock);

    $response = $this->actingAs($user)->postJson('/ai/question', [
        'data' => "Russian line\nEnglish line",
        'question' => '',
        'model' => 'openrouter:google/gemini-3-flash-preview',
    ]);

    $response->assertStatus(400)
        ->assertJsonPath('data.code', 400)
        ->assertJsonPath('data.data.error', 'Invalid model selection.');
});
