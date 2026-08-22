<?php

use App\Classes\OpenRouter;
use App\Models\AiModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe('OpenRouter Provider (DB-driven models)', function () {
    it('returns enabled, unexpired openrouter models from the database', function () {
        AiModel::factory()->enabled()->create([
            'provider' => 'openrouter',
            'external_id' => 'openai/gpt-oss-120b:free',
            'name' => 'OpenAI: GPT-OSS 120B',
            'pricing_prompt' => '0',
            'pricing_completion' => '0',
        ]);

        AiModel::factory()->enabled()->create([
            'provider' => 'openrouter',
            'external_id' => 'openai/gpt-4o-mini',
            'name' => 'OpenAI: GPT-4o-mini',
            'pricing_prompt' => '0.00000015',
            'pricing_completion' => '0.00000060',
        ]);

        AiModel::factory()->enabled()->create([
            'provider' => 'openrouter',
            'external_id' => 'expired/model',
            'name' => 'Expired Model',
            'expiration_date' => now()->subDay(),
        ]);

        AiModel::factory()->create([
            'provider' => 'openrouter',
            'external_id' => 'disabled/model',
            'name' => 'Disabled Model',
            'is_enabled' => false,
        ]);

        AiModel::factory()->enabled()->create([
            'provider' => 'gemini',
            'external_id' => 'gemini-x',
            'name' => 'Gemini X',
        ]);

        $openRouter = new OpenRouter;
        $models = $openRouter->getModels();

        expect($models)->toBeArray();
        expect($models)->toHaveKey('openai/gpt-oss-120b:free', 'OpenAI: GPT-OSS 120B (free)');
        expect($models)->toHaveKey('openai/gpt-4o-mini', 'OpenAI: GPT-4o-mini ($0.15/$0.60)');
        expect($models)->not->toHaveKey('expired/model');
        expect($models)->not->toHaveKey('disabled/model');
        expect($models)->not->toHaveKey('gemini-x');
    });

    it('returns an empty list when the database has no enabled models', function () {
        $openRouter = new OpenRouter;

        expect($openRouter->getModels())->toBe([]);
    });

    it('reconstructs the legacy pricing display format', function () {
        $model = AiModel::factory()->enabled()->create([
            'provider' => 'openrouter',
            'external_id' => 'openai/gpt-4o-mini',
            'name' => 'OpenAI: GPT-4o-mini',
            'pricing_prompt' => '0.00000015',
            'pricing_completion' => '0.00000060',
        ]);

        $openRouter = new OpenRouter;

        expect($openRouter->getModels())
            ->toHaveKey('openai/gpt-4o-mini', 'OpenAI: GPT-4o-mini ($0.15/$0.60)');
    });
});
