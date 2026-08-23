<?php

use App\Classes\AIModelResolver;
use App\Exceptions\AiProviderException;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;

/**
 * Capture the body of a StreamedResponse by running its callback
 * inside nested output buffers. The inner buffer absorbs @ob_flush()
 * calls from the controller; the outer buffer collects the flushed content.
 */
function captureStreamedContent(TestResponse $response): string
{
    ob_start();
    ob_start();
    $response->baseResponse->sendContent();
    ob_end_flush();

    return (string) ob_get_clean();
}

it('streams text chunks as SSE events', function () {
    $user = User::factory()->create();

    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('isValidModel')->andReturn(true);
    $mock->shouldReceive('askStreamed')
        ->once()
        ->andReturnUsing(function ($model, $instruction, $question, $callback) {
            $callback('Hello ');
            $callback('world');
        });

    $this->app->instance(AIModelResolver::class, $mock);

    $response = $this->actingAs($user)->postJson('/ai/question/stream', [
        'data' => "Russian line\nEnglish line",
        'question' => '',
        'model' => 'openrouter:google/gemini-3-flash-preview',
    ]);

    $response->assertOk();

    $content = captureStreamedContent($response);
    expect($content)->toContain('data: {"text":"Hello "}')
        ->and($content)->toContain('data: {"text":"world"}')
        ->and($content)->toContain('data: [DONE]');
});

it('returns the SSE content type and no-buffering headers', function () {
    $user = User::factory()->create();

    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('isValidModel')->andReturn(true);
    $mock->shouldReceive('askStreamed')
        ->once()
        ->andReturnUsing(function ($model, $instruction, $question, $callback) {
            $callback('test');
        });

    $this->app->instance(AIModelResolver::class, $mock);

    $response = $this->actingAs($user)->postJson('/ai/question/stream', [
        'data' => "Russian line\nEnglish line",
        'question' => '',
        'model' => 'openrouter:google/gemini-3-flash-preview',
    ]);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/event-stream')
        ->and($response->headers->get('X-Accel-Buffering'))->toBe('no')
        ->and($response->headers->get('Cache-Control'))->toContain('no-cache');

    // Run the callback so the mock's askStreamed expectation is satisfied.
    captureStreamedContent($response);
});

it('streams a provider error as an SSE error event followed by DONE', function () {
    $user = User::factory()->create();

    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('isValidModel')->andReturn(true);
    $mock->shouldReceive('askStreamed')
        ->once()
        ->andThrow(new AiProviderException(
            'The AI service is busy. Please try again in a moment.',
            429,
        ));

    $this->app->instance(AIModelResolver::class, $mock);

    $response = $this->actingAs($user)->postJson('/ai/question/stream', [
        'data' => "Russian line\nEnglish line",
        'question' => '',
        'model' => 'openrouter:google/gemini-3-flash-preview',
    ]);

    $content = captureStreamedContent($response);
    expect($content)->toContain('data: {"error":"The AI service is busy. Please try again in a moment."}')
        ->and($content)->toContain('data: [DONE]');

    // Raw provider internals must never reach the client.
    expect($content)->not->toContain('user_id')
        ->and($content)->not->toContain('openrouter.ai');
});

it('streams an invalid-model error as an SSE error event', function () {
    $user = User::factory()->create();

    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('isValidModel')->andReturn(true);
    $mock->shouldReceive('askStreamed')
        ->once()
        ->andThrow(new InvalidArgumentException('Unknown provider: foo'));

    $this->app->instance(AIModelResolver::class, $mock);

    $response = $this->actingAs($user)->postJson('/ai/question/stream', [
        'data' => "Russian line\nEnglish line",
        'question' => '',
        'model' => 'openrouter:google/gemini-3-flash-preview',
    ]);

    $content = captureStreamedContent($response);
    expect($content)->toContain('data: {"error":"Invalid model selection."}')
        ->and($content)->toContain('data: [DONE]');
});
