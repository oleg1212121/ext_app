<?php

namespace Database\Factories;

use App\Models\AiModel;
use App\Models\AiProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiModel>
 */
class AiModelFactory extends Factory
{
    protected $model = AiModel::class;

    public function definition(): array
    {
        return [
            'ai_provider_id' => AiProvider::query()->where('key', 'openrouter')->value('id')
                ?? AiProvider::factory()->create(['key' => 'openrouter', 'name' => 'OpenRouter'])->id,
            'external_id' => fake()->unique()->regexify('[a-z]{4,10}/[a-z0-9]{4,12}'),
            'canonical_slug' => fake()->slug(),
            'name' => fake()->company(),
            'description' => fake()->sentence(),
            'context_length' => fake()->numberBetween(8192, 200000),
            'pricing_prompt' => '0.0000001',
            'pricing_completion' => '0.0000002',
            'reasoning' => null,
            'expiration_date' => null,
            'api_created_at' => now(),
            'is_enabled' => false,
        ];
    }

    public function enabled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_enabled' => true,
        ]);
    }

    public function free(): static
    {
        return $this->state(fn (array $attributes): array => [
            'pricing_prompt' => '0',
            'pricing_completion' => '0',
        ]);
    }
}
