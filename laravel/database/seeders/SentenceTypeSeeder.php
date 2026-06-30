<?php

namespace Database\Seeders;

use App\Models\SentenceType;
use Illuminate\Database\Seeder;

class SentenceTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'sentence', 'description' => 'A standard sentence'],
            ['name' => 'title', 'description' => 'A title or heading'],
            ['name' => 'quote', 'description' => 'A quoted passage'],
            ['name' => 'subtitle', 'description' => 'A secondary heading below the main title'],
            ['name' => 'footnote', 'description' => 'An explanatory note at the bottom of a page'],
            ['name' => 'caption', 'description' => 'Text describing an image or illustration'],
        ];

        SentenceType::upsert($types, ['name'], ['description']);
    }
}
