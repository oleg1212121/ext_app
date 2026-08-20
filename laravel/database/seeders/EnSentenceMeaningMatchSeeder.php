<?php

namespace Database\Seeders;

use App\Models\EnEntity;
use App\Models\EnEntitySentence;
use App\Models\EnRuEntityMatch;
use App\Models\EnRuMeaningMatch;
use App\Models\EnSentenceMeaningMatch;
use App\Models\RuEntity;
use Illuminate\Database\Seeder;

class EnSentenceMeaningMatchSeeder extends Seeder
{
    public function run(): void
    {
        $linkSets = [
            [
                'en_entity_name' => EnEntitySeeder::ENTITY_NAME,
                'ru_entity_name' => RuEntitySeeder::ENTITY_NAME,
                'links' => [
                    ['sentence_order' => 1, 'meaning_order' => 1],
                    ['sentence_order' => 2, 'meaning_order' => 2],
                    ['sentence_order' => 3, 'meaning_order' => 3],
                    ['sentence_order' => 4, 'meaning_order' => 4],
                    ['sentence_order' => 5, 'meaning_order' => 5],
                    ['sentence_order' => 6, 'meaning_order' => 6],
                ],
            ],
            [
                'en_entity_name' => EnEntitySeeder::SECOND_ENTITY_NAME,
                'ru_entity_name' => RuEntitySeeder::SECOND_ENTITY_NAME,
                'links' => [
                    ['sentence_order' => 1, 'meaning_order' => 1],
                    ['sentence_order' => 2, 'meaning_order' => 2],
                    ['sentence_order' => 3, 'meaning_order' => 3],
                    ['sentence_order' => 4, 'meaning_order' => 4],
                ],
            ],
        ];

        foreach ($linkSets as $linkSet) {
            $entityMatch = $this->resolveEntityMatch(
                $linkSet['en_entity_name'],
                $linkSet['ru_entity_name'],
            );
            $enEntity = EnEntity::query()->where('name', $linkSet['en_entity_name'])->firstOrFail();

            $enSentences = EnEntitySentence::query()
                ->where('en_entity_id', $enEntity->id)
                ->orderBy('order')
                ->get()
                ->keyBy('order');

            $meaningMatches = EnRuMeaningMatch::query()
                ->where('en_ru_entity_match_id', $entityMatch->id)
                ->orderBy('order')
                ->get()
                ->keyBy('order');

            foreach ($linkSet['links'] as $link) {
                EnSentenceMeaningMatch::query()->updateOrCreate(
                    [
                        'en_entity_sentence_id' => $enSentences[$link['sentence_order']]->id,
                        'en_ru_meaning_match_id' => $meaningMatches[$link['meaning_order']]->id,
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
