<?php

use App\Classes\AIModelResolver;
use App\Exceptions\AiProviderException;
use App\Models\PromptTemplate;
use App\Models\User;
use App\Support\PromptTemplates;

const AI_ANSWER_MODEL_MOCK = ['id' => 7, 'key' => 'openrouter:google/gemini-3-flash-preview', 'label' => 'Gemini Flash'];

it('accepts an empty task list and assembles the default instruction', function () {
    $user = User::factory()->create();

    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('resolveAnswerModel')
        ->once()
        ->andReturn(AI_ANSWER_MODEL_MOCK);
    $mock->shouldReceive('ask')
        ->once()
        ->with(
            'openrouter:google/gemini-3-flash-preview',
            str_replace([':base', ':learning'], ['', ''], PromptTemplates::FORMAT_FALLBACK).' '.PromptTemplates::TASKS_FALLBACK,
            "Russian line\nEnglish line",
        )
        ->andReturn('Test answer');

    $this->app->instance(AIModelResolver::class, $mock);

    $response = $this->actingAs($user)->postJson('/ai/question', [
        'data' => "Russian line\nEnglish line",
        'tasks' => '',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.code', 200)
        ->assertJsonPath('data.answer', 'Test answer');
});

it('assembles the instruction from the admin-edited template row and the sent task list', function () {
    $user = User::factory()->create();
    createLanguages();
    PromptTemplate::query()->updateOrCreate(
        ['key' => PromptTemplates::FORMAT_KEY],
        ['text' => 'Judge :base against :learning.'],
    );

    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('resolveAnswerModel')
        ->once()
        ->andReturn(AI_ANSWER_MODEL_MOCK);
    $mock->shouldReceive('ask')
        ->once()
        ->with(
            'openrouter:google/gemini-3-flash-preview',
            'Judge English against Russian. My tasks.',
            'prompt',
        )
        ->andReturn('Test answer');

    $this->app->instance(AIModelResolver::class, $mock);

    $response = $this->actingAs($user)->postJson('/ai/question', [
        'data' => 'prompt',
        'tasks' => 'My tasks.',
        'base' => 'en',
        'learning' => 'ru',
    ]);

    $response->assertOk()->assertJsonPath('data.answer', 'Test answer');
});

it('asks with the user\'s stored answer model, ignoring any client-sent model field', function () {
    $user = User::factory()->create();

    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('resolveAnswerModel')
        ->once()
        ->andReturn(AI_ANSWER_MODEL_MOCK);
    $mock->shouldReceive('ask')
        ->once()
        ->with('openrouter:google/gemini-3-flash-preview', PromptTemplates::assemble('Grade it.', 'en', 'ru'), 'prompt')
        ->andReturn('Test answer');

    $this->app->instance(AIModelResolver::class, $mock);

    $response = $this->actingAs($user)->postJson('/ai/question', [
        'data' => 'prompt',
        'tasks' => 'Grade it.',
        'base' => 'en',
        'learning' => 'ru',
        'model' => 'openrouter:some/other-model',
    ]);

    $response->assertOk()->assertJsonPath('data.answer', 'Test answer');
});

it('refuses the question when the user has not chosen an answer model', function () {
    $user = User::factory()->create();

    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('resolveAnswerModel')
        ->once()
        ->andReturnNull();
    $mock->shouldReceive('ask')->never();

    $this->app->instance(AIModelResolver::class, $mock);

    $response = $this->actingAs($user)->postJson('/ai/question', [
        'data' => "Russian line\nEnglish line",
        'tasks' => '',
    ]);

    $response->assertStatus(400)
        ->assertJsonPath('data.code', 400)
        ->assertJsonPath('data.data.error', 'Choose an AI model in your profile settings.');
});

it('surfaces a provider error as a friendly message without leaking internals', function () {
    $user = User::factory()->create();

    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('resolveAnswerModel')
        ->once()
        ->andReturn(AI_ANSWER_MODEL_MOCK);
    $mock->shouldReceive('ask')
        ->once()
        ->andThrow(new AiProviderException(
            'The AI service is busy. Please try again in a moment.',
            429,
        ));

    $this->app->instance(AIModelResolver::class, $mock);

    $response = $this->actingAs($user)->postJson('/ai/question', [
        'data' => "Russian line\nEnglish line",
        'tasks' => '',
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
    $mock->shouldReceive('resolveAnswerModel')
        ->once()
        ->andReturn(AI_ANSWER_MODEL_MOCK);
    $mock->shouldReceive('ask')
        ->once()
        ->andThrow(new InvalidArgumentException('Unknown provider: foo'));

    $this->app->instance(AIModelResolver::class, $mock);

    $response = $this->actingAs($user)->postJson('/ai/question', [
        'data' => "Russian line\nEnglish line",
        'tasks' => '',
    ]);

    $response->assertStatus(400)
        ->assertJsonPath('data.code', 400)
        ->assertJsonPath('data.data.error', 'Invalid model selection.');
});
