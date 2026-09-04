<?php

use App\Models\AiProvider;
use App\Services\AiModelSyncRegistry;
use Illuminate\Support\Facades\Http;

it('syncs only the chosen provider with --provider', function () {
    Http::fake();
    Http::preventStrayRequests();

    AiProvider::factory()->create(['key' => 'groq', 'name' => 'Groq']);

    $this->artisan('ai:sync-models', ['--provider' => 'groq'])
        ->assertSuccessful()
        ->expectsOutputToContain('Synced 0 groq models.')
        ->expectsOutputToContain('Done: 1 synced, 0 failed.');
});

it('syncs every registered provider by default', function () {
    Http::fake();
    Http::preventStrayRequests();

    foreach (array_keys(app(AiModelSyncRegistry::class)->all()) as $key) {
        AiProvider::factory()->create(['key' => $key, 'name' => ucfirst($key)]);
    }

    $this->artisan('ai:sync-models')
        ->assertSuccessful()
        ->expectsOutputToContain('Done: 6 synced, 0 failed.');
});

it('reports an unknown provider without running', function () {
    $this->artisan('ai:sync-models', ['--provider' => 'nope'])
        ->assertFailed()
        ->expectsOutputToContain('Unknown provider: nope');
});
