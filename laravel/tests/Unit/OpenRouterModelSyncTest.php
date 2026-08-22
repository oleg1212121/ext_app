<?php

use App\Models\AiModel;
use App\Services\OpenRouterModelSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe('OpenRouterModelSync', function () {
    it('upserts models from the API and deletes missing ones', function () {
        Http::fake([
            'https://openrouter.ai/api/v1/models' => Http::response([
                'data' => [
                    [
                        'id' => 'openai/gpt-4o-mini',
                        'canonical_slug' => 'openai/gpt-4o-mini-20240101',
                        'name' => 'OpenAI: GPT-4o-mini',
                        'created' => 1700000000,
                        'description' => 'A small model',
                        'context_length' => 128000,
                        'pricing' => ['prompt' => '0.00000015', 'completion' => '0.00000060'],
                        'expiration_date' => null,
                        'reasoning' => ['mandatory' => false, 'supported_efforts' => ['low', 'high']],
                    ],
                ],
                'total_count' => 1,
                'links' => ['next' => null],
            ]),
        ]);

        AiModel::factory()->create([
            'provider' => 'openrouter',
            'external_id' => 'old/model',
            'name' => 'Old Model',
            'is_enabled' => true,
        ]);

        $count = (new OpenRouterModelSync)->sync();

        expect($count)->toBe(1);
        expect(AiModel::where('external_id', 'openai/gpt-4o-mini')->exists())->toBeTrue();
        expect(AiModel::where('external_id', 'old/model')->exists())->toBeFalse();

        $synced = AiModel::where('external_id', 'openai/gpt-4o-mini')->first();
        expect((float) $synced->pricing_prompt)->toBe(0.00000015);
        expect((float) $synced->pricing_completion)->toBe(0.00000060);
        expect($synced->context_length)->toBe(128000);
        expect($synced->reasoning)->toBe(['mandatory' => false, 'supported_efforts' => ['low', 'high']]);
        expect($synced->api_created_at)->not->toBeNull();
    });

    it('preserves is_enabled for existing models and defaults new ones to disabled', function () {
        AiModel::factory()->enabled()->create([
            'provider' => 'openrouter',
            'external_id' => 'openai/gpt-4o-mini',
            'name' => 'OpenAI: GPT-4o-mini',
            'pricing_prompt' => '0.00000015',
            'pricing_completion' => '0.00000060',
        ]);

        Http::fake([
            'https://openrouter.ai/api/v1/models' => Http::response([
                'data' => [
                    [
                        'id' => 'openai/gpt-4o-mini',
                        'name' => 'OpenAI: GPT-4o-mini',
                        'pricing' => ['prompt' => '0.00000015', 'completion' => '0.00000060'],
                    ],
                    [
                        'id' => 'openai/gpt-4o-new',
                        'name' => 'OpenAI: GPT-4o New',
                        'pricing' => ['prompt' => '0.00000050', 'completion' => '0.00000150'],
                    ],
                ],
                'total_count' => 2,
                'links' => ['next' => null],
            ]),
        ]);

        (new OpenRouterModelSync)->sync();

        expect(AiModel::where('external_id', 'openai/gpt-4o-mini')->value('is_enabled'))->toBeTrue();
        expect(AiModel::where('external_id', 'openai/gpt-4o-new')->value('is_enabled'))->toBeFalse();
    });

    it('throws when the API returns a non-successful response', function () {
        Http::fake([
            'https://openrouter.ai/api/v1/models' => Http::response(null, 500),
        ]);

        expect(fn () => (new OpenRouterModelSync)->sync())
            ->toThrow(RuntimeException::class);
    });
});
