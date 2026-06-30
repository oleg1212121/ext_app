<?php

namespace Database\Factories;

use App\Models\Info;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Info>
 */
class InfoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'word' => fake()->word(),
            'info' => json_encode([
                'type' => fake()->word(),
                'value' => fake()->sentence(),
                'source' => fake()->url(),
            ]),
        ];
    }
}
