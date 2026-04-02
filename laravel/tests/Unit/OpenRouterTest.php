<?php

uses(Tests\TestCase::class);

describe('OpenRouter Provider', function () {
    it('has models with prices in the display names', function () {
        $openRouter = new \App\Classes\OpenRouter;
        $reflection = new ReflectionClass($openRouter);
        $property = $reflection->getProperty('models');
        $property->setAccessible(true);
        $models = $property->getValue($openRouter);

        expect($models)->toBeArray();
        expect($models)->not->toBeEmpty();

        // Check that all model display names have either prices in parentheses or "(free)"
        foreach ($models as $displayName) {
            expect($displayName)->toMatch('/(\(\$\d+\.?\d*\/\$\d+\.?\d*\)|\(free\))/');
        }
    });

    it('contains specific model identifiers with correct pricing', function () {
        $openRouter = new \App\Classes\OpenRouter;
        $reflection = new ReflectionClass($openRouter);
        $property = $reflection->getProperty('models');
        $property->setAccessible(true);
        $models = $property->getValue($openRouter);

        // Test a few specific models to ensure they have correct prices
        expect($models['openai/gpt-4o-mini'] ?? null)->toBe('OpenAI: GPT-4o-mini ($0.15/$0.60)');
        expect($models['google/gemini-2.5-flash'] ?? null)->toBe('Google: Gemini 2.5 Flash ($0.30/$2.50)');
        expect($models['anthropic/claude-haiku-4.5'] ?? null)->toBe('Anthropic: Claude Haiku 4.5 ($1/$5)');
    });

    it('includes all models from the identifiers list', function () {
        $openRouter = new \App\Classes\OpenRouter;
        $reflection = new ReflectionClass($openRouter);
        $property = $reflection->getProperty('models');
        $property->setAccessible(true);
        $models = $property->getValue($openRouter);

        $expectedIdentifiers = [
            'nvidia/nemotron-3-nano-30b-a3b:free',
            'google/gemma-3n-e4b-it',
            'openai/gpt-oss-20b',
            'sao10k/l3-lunaris-8b',
            'qwen/qwen-turbo',
            'google/gemma-3-12b-it',
            'openai/gpt-oss-120b',
            'qwen/qwen3.5-9b',
            'qwen/qwen3-235b-a22b-instruct-2507',
            'qwen/qwen3.5-flash-02-23',
            'google/gemma-3-27b-it',
            'google/gemini-2.0-flash-lite-001',
            'google/gemini-2.5-flash-lite-preview-09-2025',
            'google/gemini-2.5-flash-lite',
            'google/gemini-2.0-flash-001',
            'qwen/qwen3-next-80b-a3b-thinking',
            'openai/gpt-4o-mini',
            'deepseek/deepseek-chat-v3.1',
            'minimax/minimax-m2.5',
            'x-ai/grok-4.1-fast',
            'x-ai/grok-4-fast',
            'deepseek/deepseek-chat-v3-0324',
            'deepseek/deepseek-v3.1-terminus',
            'deepseek/deepseek-v3.2',
            'openai/gpt-5.4-nano',
            'google/gemini-3.1-flash-lite-preview',
            'deepseek/deepseek-chat',
            'minimax/minimax-m2.7',
            'openai/gpt-5-mini',
            'google/gemini-2.5-flash',
            'openai/gpt-4.1-mini',
            'moonshotai/kimi-k2.5',
            'google/gemini-3-flash-preview',
            'openai/gpt-5.4-mini',
            'anthropic/claude-haiku-4.5',
            'openai/o3-mini',
            'z-ai/glm-5',
            'openai/gpt-5.4-mini',
            'anthropic/claude-haiku-4.5',
            'openai/o3-mini',
            'z-ai/glm-5-turbo',
            'google/gemini-2.5-pro',
            'openai/gpt-4.1',
            'openai/gpt-5.2',
            'google/gemini-3.1-pro-preview',
            'openai/gpt-4o',
            'openai/gpt-5.4',
            'anthropic/claude-sonnet-4.6',
            'anthropic/claude-sonnet-4.5',
            'anthropic/claude-opus-4.6',
        ];

        foreach ($expectedIdentifiers as $identifier) {
            expect(array_key_exists($identifier, $models))->toBeTrue("Missing model: $identifier");
        }
    });
});
