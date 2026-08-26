<?php

use App\Classes\AIModelResolver;
use App\Models\User;

// `throttle:20,1` (routes/web.php) caps the AI question endpoint at 20
// requests per minute per authenticated user. The 21st must be refused with
// 429 before the controller runs again (the limiter short-circuits).

it('rate-limits the AI question endpoint after 20 requests per minute', function () {
    $user = User::factory()->create();

    $mock = mock(AIModelResolver::class);
    $mock->shouldReceive('isValidModel')->andReturn(true);
    // Allow unlimited calls — the first 20 run the controller; the 21st is
    // blocked by the limiter before reaching the resolver.
    $mock->shouldReceive('ask')->andReturn('Test answer');

    $this->app->instance(AIModelResolver::class, $mock);

    $payload = [
        'data' => "Russian line\nEnglish line",
        'question' => '',
        'model' => 'openrouter:google/gemini-3-flash-preview',
    ];

    // First 20 requests succeed.
    for ($i = 1; $i <= 20; $i++) {
        $this->actingAs($user)
            ->postJson('/ai/question', $payload)
            ->assertOk();
    }

    // 21st is refused with 429 (Laravel's throttle response, not the controller's).
    $this->actingAs($user)
        ->postJson('/ai/question', $payload)
        ->assertStatus(429);
});
