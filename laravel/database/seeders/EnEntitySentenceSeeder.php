<?php

namespace Database\Seeders;

use App\Models\EnEntity;
use App\Models\EnEntitySentence;
use App\Models\SentenceType;
use Illuminate\Database\Seeder;

class EnEntitySentenceSeeder extends Seeder
{
    public function run(): void
    {
        $sentenceType = SentenceType::query()->where('name', 'sentence')->firstOrFail();
        $titleType = SentenceType::query()->where('name', 'title')->firstOrFail();
        $quoteType = SentenceType::query()->where('name', 'quote')->firstOrFail();

        $entities = [
            EnEntitySeeder::ENTITY_NAME => [
                ['content' => 'The Little Prince', 'order' => 0, 'sentence_type_id' => $titleType->id],
                ['content' => 'Once when I was six years old I saw a magnificent picture in a book.', 'order' => 1, 'sentence_type_id' => $sentenceType->id],
                ['content' => 'It showed a boa constrictor swallowing an animal.', 'order' => 2, 'sentence_type_id' => $sentenceType->id],
                ['content' => 'Here is a copy of the drawing.', 'order' => 3, 'sentence_type_id' => $sentenceType->id],
                ['content' => 'In the book it said: "Boa constrictors swallow their prey whole, without chewing it."', 'order' => 4, 'sentence_type_id' => $quoteType->id],
                ['content' => 'I pondered deeply, then, over the adventures of the jungle.', 'order' => 5, 'sentence_type_id' => $sentenceType->id],
                ['content' => 'And after some work with a colored pencil I succeeded in making my first drawing.', 'order' => 6, 'sentence_type_id' => $sentenceType->id],
            ],
            EnEntitySeeder::SECOND_ENTITY_NAME => [
                ['content' => 'The Old Man and the Sea', 'order' => 0, 'sentence_type_id' => $titleType->id],
                ['content' => 'He was an old man who fished alone in a skiff in the Gulf Stream.', 'order' => 1, 'sentence_type_id' => $sentenceType->id],
                ['content' => 'He had gone eighty-four days now without taking a fish.', 'order' => 2, 'sentence_type_id' => $sentenceType->id],
                ['content' => 'In the first forty days a boy had been with him.', 'order' => 3, 'sentence_type_id' => $sentenceType->id],
                ['content' => 'But after forty days without a fish the boy\'s parents had told him that the old man was now definitely and finally salao.', 'order' => 4, 'sentence_type_id' => $sentenceType->id],
            ],
            EnEntitySeeder::THIRD_ENTITY_NAME => [
                ['content' => 'Alice was beginning to get very tired of sitting by her sister on the bank.', 'order' => 1, 'sentence_type_id' => $sentenceType->id],
                ['content' => 'Once or twice she had peeped into the book her sister was reading.', 'order' => 2, 'sentence_type_id' => $sentenceType->id],
                ['content' => 'Suddenly a White Rabbit with pink eyes ran close by her.', 'order' => 3, 'sentence_type_id' => $sentenceType->id],
            ],
        ];

        foreach ($entities as $entityName => $sentences) {
            $entity = EnEntity::query()->where('name', $entityName)->firstOrFail();

            foreach ($sentences as $sentence) {
                EnEntitySentence::query()->updateOrCreate(
                    [
                        'en_entity_id' => $entity->id,
                        'order' => $sentence['order'],
                    ],
                    [
                        'sentence_type_id' => $sentence['sentence_type_id'],
                        'content' => $sentence['content'],
                    ],
                );
            }
        }
    }
}
