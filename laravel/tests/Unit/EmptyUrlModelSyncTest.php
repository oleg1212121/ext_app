<?php

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Services\AiModelSyncRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('returns 0 and wipes that provider catalog when models_url is blank', function (string $provider) {
    Http::fake();
    Http::preventStrayRequests();

    $providerRecord = AiProvider::factory()->create(['key' => $provider, 'name' => ucfirst($provider)]);

    AiModel::factory()->count(2)->for($providerRecord)->create();

    $count = app(AiModelSyncRegistry::class)->for($provider)->sync();

    expect($count)->toBe(0);
    expect(AiModel::query()->forProvider($providerRecord->id)->count())->toBe(0);
})->with(['groq', 'gemini', 'huggingface', 'cohere', 'perplexity']);
