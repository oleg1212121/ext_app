<?php

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Services\OpenRouterModelSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('writes ai_provider_id and ignores the provider is_enabled flag', function () {
    Http::fake([
        'https://example.com/models' => Http::response(['data' => [['id' => 'm1', 'name' => 'Model 1']]]),
    ]);

    config(['services.openrouter.models_url' => 'https://example.com/models']);

    $provider = AiProvider::factory()->disabled()->create(['key' => 'openrouter', 'name' => 'OpenRouter']);

    (new OpenRouterModelSync)->sync();

    $model = AiModel::where('external_id', 'm1')->first();

    expect($model)->not->toBeNull();
    expect($model->ai_provider_id)->toBe($provider->id);
    expect($model->is_enabled)->toBeFalse();
});

it('leaves ai_provider_id null when no provider row exists yet', function () {
    Http::fake([
        'https://example.com/models' => Http::response(['data' => [['id' => 'm1', 'name' => 'Model 1']]]),
    ]);

    config(['services.openrouter.models_url' => 'https://example.com/models']);

    (new OpenRouterModelSync)->sync();

    $model = AiModel::where('external_id', 'm1')->first();

    expect($model)->not->toBeNull();
    expect($model->ai_provider_id)->toBeNull();
});

it('adopts pre-migration orphan rows and preserves their is_enabled', function () {
    Http::fake([
        'https://example.com/models' => Http::response(['data' => [['id' => 'm1', 'name' => 'Model 1']]]),
    ]);

    config(['services.openrouter.models_url' => 'https://example.com/models']);

    $provider = AiProvider::factory()->create(['key' => 'openrouter', 'name' => 'OpenRouter']);

    // Row left behind by the migration with a NULL FK but previously enabled.
    AiModel::create([
        'ai_provider_id' => null,
        'external_id' => 'm1',
        'name' => 'Model 1',
        'is_enabled' => true,
    ]);

    (new OpenRouterModelSync)->sync();

    $model = AiModel::where('external_id', 'm1')->first();

    expect(AiModel::where('external_id', 'm1')->count())->toBe(1);
    expect($model->ai_provider_id)->toBe($provider->id);
    expect($model->is_enabled)->toBeTrue();
});

it('carries is_enabled from a duplicate orphan onto the live row', function () {
    Http::fake([
        'https://example.com/models' => Http::response(['data' => [['id' => 'm1', 'name' => 'Model 1']]]),
    ]);

    config(['services.openrouter.models_url' => 'https://example.com/models']);

    $provider = AiProvider::factory()->create(['key' => 'openrouter', 'name' => 'OpenRouter']);

    // Live row created by a prior (broken) sync plus the original enabled orphan.
    AiModel::create([
        'ai_provider_id' => $provider->id,
        'external_id' => 'm1',
        'name' => 'Model 1',
        'is_enabled' => false,
    ]);
    AiModel::create([
        'ai_provider_id' => null,
        'external_id' => 'm1',
        'name' => 'Model 1',
        'is_enabled' => true,
    ]);

    (new OpenRouterModelSync)->sync();

    expect(AiModel::where('external_id', 'm1')->count())->toBe(1);

    $model = AiModel::where('external_id', 'm1')->first();

    expect($model->ai_provider_id)->toBe($provider->id);
    expect($model->is_enabled)->toBeTrue();
});
