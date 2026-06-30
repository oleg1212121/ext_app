<?php

namespace Database\Seeders;

use App\Models\EnEntity;
use Illuminate\Database\Seeder;

class EnEntitySeeder extends Seeder
{
    public const ENTITY_NAME = 'The Little Prince (en)';

    public const SECOND_ENTITY_NAME = 'The Old Man and the Sea (en)';

    public const THIRD_ENTITY_NAME = 'Alice in Wonderland (en)';

    public function run(): void
    {
        $entities = [
            [
                'name' => self::ENTITY_NAME,
                'description' => 'Sample English text for bilingual alignment development.',
                'signature' => json_encode([0.9214, 0.3842, 0.1523, 0.0678]),
                'file_path' => 'texts/simulator/the_little_prince_en.txt',
            ],
            [
                'name' => self::SECOND_ENTITY_NAME,
                'description' => 'Hemingway excerpt paired with a Russian translation.',
                'signature' => json_encode([0.8871, 0.4123, 0.1789, 0.0541]),
                'file_path' => 'texts/simulator/the_old_man_and_the_sea_en.txt',
            ],
            [
                'name' => self::THIRD_ENTITY_NAME,
                'description' => 'Carroll excerpt awaiting alignment with its Russian counterpart.',
                'signature' => json_encode([0.8654, 0.3987, 0.2012, 0.0623]),
                'file_path' => 'texts/simulator/alice_in_wonderland_en.txt',
            ],
        ];

        foreach ($entities as $entity) {
            EnEntity::query()->updateOrCreate(
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
