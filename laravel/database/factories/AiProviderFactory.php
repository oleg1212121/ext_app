<?php

namespace Database\Factories;

use App\Models\AiProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiProvider>
 */
class AiProviderFactory extends Factory
{
    protected $model = AiProvider::class;

    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(),
            'name' => fake()->company(),
            'is_enabled' => true,
            'description' => fake()->sentence(),
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_enabled' => false,
        ]);
    }

    public function enabled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_enabled' => true,
        ]);
    }
}
