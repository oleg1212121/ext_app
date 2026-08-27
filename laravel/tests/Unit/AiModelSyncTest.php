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

it('throws when the provider row does not exist', function () {
    Http::fake([
        'https://example.com/models' => Http::response(['data' => [['id' => 'm1', 'name' => 'Model 1']]]),
    ]);

    config(['services.openrouter.models_url' => 'https://example.com/models']);

    expect(fn () => (new OpenRouterModelSync)->sync())
        ->toThrow(RuntimeException::class, 'no AiProvider row exists');
});

it('preserves the admin is_enabled choice across re-syncs', function () {
    Http::fake([
        'https://example.com/models' => Http::response(['data' => [['id' => 'm1', 'name' => 'Model 1']]]),
    ]);

    config(['services.openrouter.models_url' => 'https://example.com/models']);

    $provider = AiProvider::factory()->create(['key' => 'openrouter', 'name' => 'OpenRouter']);

    // Admin enables the model out of band.
    AiModel::create([
        'ai_provider_id' => $provider->id,
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

it('deletes catalog-missing rows for the provider', function () {
    Http::fake([
        'https://example.com/models' => Http::response(['data' => [['id' => 'm1', 'name' => 'Model 1']]]),
    ]);

    config(['services.openrouter.models_url' => 'https://example.com/models']);

    $provider = AiProvider::factory()->create(['key' => 'openrouter', 'name' => 'OpenRouter']);

    AiModel::create([
        'ai_provider_id' => $provider->id,
        'external_id' => 'stale',
        'name' => 'Stale Model',
        'is_enabled' => true,
    ]);

    (new OpenRouterModelSync)->sync();

    expect(AiModel::where('external_id', 'stale')->exists())->toBeFalse();
    expect(AiModel::where('external_id', 'm1')->exists())->toBeTrue();
});
