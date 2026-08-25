<?php

use App\Classes\AIModelResolver;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\User;
use App\Models\UserApiKey;

it('canUseAi agrees with the simulator model picker across capability scenarios', function (array $providers, bool $expected) {
    $user = User::factory()->create();

    foreach ($providers as $p) {
        $provider = AiProvider::factory()->create([
            'key' => $p['key'],
            'name' => $p['name'],
            'is_enabled' => $p['enabled'],
        ]);
        foreach ($p['models'] as $m) {
            AiModel::factory()->create([
                'ai_provider_id' => $provider->id,
                'external_id' => $m['external_id'],
                'name' => $m['name'],
                'pricing_prompt' => '0',
                'pricing_completion' => '0',
                'is_enabled' => $m['enabled'],
            ]);
        }
        if ($p['keyed']) {
            UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $provider->id]);
        }
    }

    $this->actingAs($user);

    $pickerUsable = ! empty((new AIModelResolver)->getGroupedModels());

    expect($user->canUseAi())->toBe($expected)
        ->and($pickerUsable)->toBe($expected);
})->with([
    'key + enabled provider + enabled model' => [
        [['key' => 'openrouter', 'name' => 'OpenRouter', 'enabled' => true, 'keyed' => true, 'models' => [['external_id' => 'x', 'name' => 'X', 'enabled' => true]]]],
        true,
    ],
    'key + disabled provider' => [
        [['key' => 'openrouter', 'name' => 'OpenRouter', 'enabled' => false, 'keyed' => true, 'models' => [['external_id' => 'x', 'name' => 'X', 'enabled' => true]]]],
        false,
    ],
    'key + enabled provider with all models disabled' => [
        [['key' => 'openrouter', 'name' => 'OpenRouter', 'enabled' => true, 'keyed' => true, 'models' => [['external_id' => 'x', 'name' => 'X', 'enabled' => false]]]],
        false,
    ],
    'no key' => [
        [['key' => 'openrouter', 'name' => 'OpenRouter', 'enabled' => true, 'keyed' => false, 'models' => [['external_id' => 'x', 'name' => 'X', 'enabled' => true]]]],
        false,
    ],
    'mixed: one usable of two keyed providers' => [
        [
            ['key' => 'openrouter', 'name' => 'OpenRouter', 'enabled' => true, 'keyed' => true, 'models' => [['external_id' => 'x', 'name' => 'X', 'enabled' => true]]],
            ['key' => 'gemini', 'name' => 'Gemini', 'enabled' => false, 'keyed' => true, 'models' => [['external_id' => 'g', 'name' => 'G', 'enabled' => true]]],
        ],
        true,
    ],
]);
