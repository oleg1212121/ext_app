<?php

namespace Database\Factories;

use App\Models\AiProvider;
use App\Models\User;
use App\Models\UserApiKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserApiKey>
 */
class UserApiKeyFactory extends Factory
{
    protected $model = UserApiKey::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'ai_provider_id' => AiProvider::query()->where('key', 'openrouter')->value('id')
                ?? AiProvider::factory()->create(['key' => 'openrouter', 'name' => 'OpenRouter'])->id,
            'api_key' => fake()->sha256(),
        ];
    }
}
