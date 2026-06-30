<?php

use App\Classes\AIModelResolver;
use App\Models\User;

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
