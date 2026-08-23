<?php

namespace Database\Seeders;

use App\Classes\AIModelResolver;
use App\Models\AiProvider;
use Illuminate\Database\Seeder;

class AiProviderSeeder extends Seeder
{
    public function run(): void
    {
        $resolver = app(AIModelResolver::class);

        foreach ($resolver->keys() as $key) {
            $class = $resolver->providerClass($key);

            AiProvider::query()->updateOrCreate(
                ['key' => $key],
                [
                    'name' => $class::getProviderName(),
                    'is_enabled' => true,
                ],
            );
        }
    }
}
