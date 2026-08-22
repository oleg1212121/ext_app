<?php

use Illuminate\Support\Facades\Http;

it('syncs only the chosen provider with --provider', function () {
    Http::fake();
    Http::preventStrayRequests();

    $this->artisan('ai:sync-models', ['--provider' => 'groq'])
        ->assertSuccessful()
        ->expectsOutputToContain('Synced 0 groq models.')
        ->expectsOutputToContain('Done: 1 synced, 0 failed.');
});

it('syncs every registered provider by default', function () {
    Http::fake();
    Http::preventStrayRequests();

    $this->artisan('ai:sync-models')
        ->assertSuccessful()
        ->expectsOutputToContain('Done: 6 synced, 0 failed.');
});

it('reports an unknown provider without running', function () {
    $this->artisan('ai:sync-models', ['--provider' => 'nope'])
        ->assertFailed()
        ->expectsOutputToContain('Unknown provider: nope');
});
