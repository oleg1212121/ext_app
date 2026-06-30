<?php

namespace Database\Seeders;

use App\Models\EnEntity;
use App\Models\EnRuEntityMatch;
use App\Models\RuEntity;
use Illuminate\Database\Seeder;

class EnRuEntityMatchSeeder extends Seeder
{
    public function run(): void
    {
        $matches = [
            [
                'en_entity_name' => EnEntitySeeder::ENTITY_NAME,
                'ru_entity_name' => RuEntitySeeder::ENTITY_NAME,
                'status' => 'completed',
                'entity_similarity' => 0.9123,
                'en_total_sentences' => 7,
                'ru_total_sentences' => 7,
                'linked_count' => 6,
                'chunk_size' => 75,
                'max_n' => 6,
                'dp_path' => [
                    ['en' => 1, 'ru' => 1],
                    ['en' => 2, 'ru' => 2],
                    ['en' => 3, 'ru' => 3],
                    ['en' => 4, 'ru' => 4],
                    ['en' => 5, 'ru' => 5],
                    ['en' => 6, 'ru' => 6],
                ],
                'started_at' => now()->subMinutes(12),
                'completed_at' => now()->subMinutes(10),
            ],
            [
                'en_entity_name' => EnEntitySeeder::SECOND_ENTITY_NAME,
                'ru_entity_name' => RuEntitySeeder::SECOND_ENTITY_NAME,
                'status' => 'completed',
                'entity_similarity' => 0.8956,
                'en_total_sentences' => 5,
                'ru_total_sentences' => 5,
                'linked_count' => 4,
                'chunk_size' => 75,
                'max_n' => 6,
                'dp_path' => [
                    ['en' => 1, 'ru' => 1],
                    ['en' => 2, 'ru' => 2],
                    ['en' => 3, 'ru' => 3],
                    ['en' => 4, 'ru' => 4],
                ],
                'started_at' => now()->subMinutes(8),
                'completed_at' => now()->subMinutes(6),
            ],
            [
                'en_entity_name' => EnEntitySeeder::THIRD_ENTITY_NAME,
                'ru_entity_name' => RuEntitySeeder::THIRD_ENTITY_NAME,
                'status' => 'pending',
                'entity_similarity' => null,
                'en_total_sentences' => 3,
                'ru_total_sentences' => 3,
                'linked_count' => 0,
                'chunk_size' => 75,
                'max_n' => 6,
                'dp_path' => null,
                'started_at' => null,
                'completed_at' => null,
            ],
        ];

        foreach ($matches as $match) {
            $enEntity = EnEntity::query()->where('name', $match['en_entity_name'])->firstOrFail();
            $ruEntity = RuEntity::query()->where('name', $match['ru_entity_name'])->firstOrFail();

            EnRuEntityMatch::query()->updateOrCreate(
                [
                    'en_entity_id' => $enEntity->id,
                    'ru_entity_id' => $ruEntity->id,
                ],
                [
                    'status' => $match['status'],
                    'entity_similarity' => $match['entity_similarity'],
                    'en_total_sentences' => $match['en_total_sentences'],
                    'ru_total_sentences' => $match['ru_total_sentences'],
                    'linked_count' => $match['linked_count'],
                    'chunk_size' => $match['chunk_size'],
                    'max_n' => $match['max_n'],
                    'dp_path' => $match['dp_path'],
                    'started_at' => $match['started_at'],
                    'completed_at' => $match['completed_at'],
                ],
            );
        }
    }
}
