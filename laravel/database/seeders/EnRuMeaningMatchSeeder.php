<?php

namespace Database\Seeders;

use App\Models\EnEntity;
use App\Models\EnRuEntityMatch;
use App\Models\EnRuMeaningMatch;
use App\Models\RuEntity;
use Illuminate\Database\Seeder;

class EnRuMeaningMatchSeeder extends Seeder
{
    public function run(): void
    {
        $matchSets = [
            [
                'en_entity_name' => EnEntitySeeder::ENTITY_NAME,
                'ru_entity_name' => RuEntitySeeder::ENTITY_NAME,
                'meaning_matches' => [
                    ['order' => 1, 'similarity' => 0.9345, 'alignment_chunk' => 0],
                    ['order' => 2, 'similarity' => 0.9210, 'alignment_chunk' => 0],
                    ['order' => 3, 'similarity' => 0.9088, 'alignment_chunk' => 0],
                    ['order' => 4, 'similarity' => 0.9156, 'alignment_chunk' => 0],
                    ['order' => 5, 'similarity' => 0.9021, 'alignment_chunk' => 0],
                    ['order' => 6, 'similarity' => 0.8974, 'alignment_chunk' => 0],
                ],
            ],
            [
                'en_entity_name' => EnEntitySeeder::SECOND_ENTITY_NAME,
                'ru_entity_name' => RuEntitySeeder::SECOND_ENTITY_NAME,
                'meaning_matches' => [
                    ['order' => 1, 'similarity' => 0.9288, 'alignment_chunk' => 0],
                    ['order' => 2, 'similarity' => 0.9163, 'alignment_chunk' => 0],
                    ['order' => 3, 'similarity' => 0.9047, 'alignment_chunk' => 0],
                    ['order' => 4, 'similarity' => 0.8912, 'alignment_chunk' => 0],
                ],
            ],
        ];

        foreach ($matchSets as $matchSet) {
            $entityMatch = $this->resolveEntityMatch(
                $matchSet['en_entity_name'],
                $matchSet['ru_entity_name'],
            );

            foreach ($matchSet['meaning_matches'] as $meaningMatch) {
                EnRuMeaningMatch::query()->updateOrCreate(
                    [
                        'en_ru_entity_match_id' => $entityMatch->id,
                        'order' => $meaningMatch['order'],
                    ],
                    [
                        'similarity' => $meaningMatch['similarity'],
                        'alignment_chunk' => $meaningMatch['alignment_chunk'],
                    ],
                );
            }
        }
    }

    private function resolveEntityMatch(string $enEntityName, string $ruEntityName): EnRuEntityMatch
    {
        $enEntity = EnEntity::query()->where('name', $enEntityName)->firstOrFail();
        $ruEntity = RuEntity::query()->where('name', $ruEntityName)->firstOrFail();

        return EnRuEntityMatch::query()
            ->where('en_entity_id', $enEntity->id)
            ->where('ru_entity_id', $ruEntity->id)
            ->firstOrFail();
    }
}
