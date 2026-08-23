<?php

use App\Classes\AIModelResolver;
use App\Models\AiProvider;
use Database\Seeders\AiProviderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('seeds a row for every registered provider', function () {
    app(AiProviderSeeder::class)->run();

    expect(AiProvider::query()->count())->toBe(6);
    expect(AiProvider::query()->where('key', 'openrouter')->exists())->toBeTrue();
    expect(AiProvider::query()->where('key', 'gemini')->exists())->toBeTrue();
});

it('seeds providers as enabled by default', function () {
    app(AiProviderSeeder::class)->run();

    expect(AiProvider::query()->where('is_enabled', false)->count())->toBe(0);
});

it('is idempotent', function () {
    app(AiProviderSeeder::class)->run();
    app(AiProviderSeeder::class)->run();

    expect(AiProvider::query()->count())->toBe(6);
});

it('exposes enabled and forKey scopes', function () {
    AiProvider::factory()->disabled()->create(['key' => 'off', 'name' => 'Off']);
    AiProvider::factory()->create(['key' => 'on', 'name' => 'On']);

    expect(AiProvider::query()->enabled()->count())->toBe(1);
    expect(AiProvider::query()->forKey('on')->exists())->toBeTrue();
    expect(AiProvider::query()->forKey('missing')->exists())->toBeFalse();
});

it('reports is_enabled from the database row', function () {
    $provider = AiProvider::factory()->disabled()->create(['key' => 'openrouter', 'name' => 'OpenRouter']);

    $resolver = app(AIModelResolver::class);
    $class = $resolver->providerClass('openrouter');

    expect($class::getProviderKey())->toBe('openrouter');

    $instance = new $class;
    expect($instance->isEnabled())->toBeFalse();

    $provider->update(['is_enabled' => true]);
    expect((new $class)->isEnabled())->toBeTrue();
});
