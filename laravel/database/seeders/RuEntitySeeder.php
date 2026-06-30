<?php

namespace Database\Seeders;

use App\Models\RuEntity;
use Illuminate\Database\Seeder;

class RuEntitySeeder extends Seeder
{
    public const ENTITY_NAME = 'Маленький принц (ru)';

    public const SECOND_ENTITY_NAME = 'Старик и море (ru)';

    public const THIRD_ENTITY_NAME = 'Алиса в стране чудес (ru)';

    public function run(): void
    {
        $entities = [
            [
                'name' => self::ENTITY_NAME,
                'description' => 'Sample Russian text paired with the English Little Prince excerpt.',
                'signature' => json_encode([0.9187, 0.3911, 0.1498, 0.0712]),
                'file_path' => 'texts/simulator/the_little_prince_ru.txt',
            ],
            [
                'name' => self::SECOND_ENTITY_NAME,
                'description' => 'Russian translation of the Hemingway excerpt.',
                'signature' => json_encode([0.8842, 0.4178, 0.1756, 0.0589]),
                'file_path' => 'texts/simulator/the_old_man_and_the_sea_ru.txt',
            ],
            [
                'name' => self::THIRD_ENTITY_NAME,
                'description' => 'Russian translation of the Alice excerpt.',
                'signature' => json_encode([0.8621, 0.4015, 0.1987, 0.0654]),
                'file_path' => 'texts/simulator/alice_in_wonderland_ru.txt',
            ],
        ];

        foreach ($entities as $entity) {
            RuEntity::query()->updateOrCreate(
                ['name' => $entity['name']],
                [
                    'description' => $entity['description'],
                    'signature' => $entity['signature'],
                    'file_path' => $entity['file_path'],
                ],
            );
        }
    }
}
