<?php

namespace Database\Seeders;

use App\Models\RuEntity;
use App\Models\RuEntitySentence;
use App\Models\SentenceType;
use Illuminate\Database\Seeder;

class RuEntitySentenceSeeder extends Seeder
{
    public function run(): void
    {
        $sentenceType = SentenceType::query()->where('name', 'sentence')->firstOrFail();
        $titleType = SentenceType::query()->where('name', 'title')->firstOrFail();
        $quoteType = SentenceType::query()->where('name', 'quote')->firstOrFail();

        $entities = [
            RuEntitySeeder::ENTITY_NAME => [
                ['content' => 'Маленький принц', 'order' => 0, 'sentence_type_id' => $titleType->id],
                ['content' => 'Когда мне было шесть лет, в одной книге про джунгли я увидел удивительную картинку.', 'order' => 1, 'sentence_type_id' => $sentenceType->id],
                ['content' => 'На рисунке змея-удав глотала хищного зверя.', 'order' => 2, 'sentence_type_id' => $sentenceType->id],
                ['content' => 'Вот копия этого рисунка.', 'order' => 3, 'sentence_type_id' => $sentenceType->id],
                ['content' => 'В книге говорилось: «Змеи-удавы глотают свою добычу целиком, не пережёвывая её».', 'order' => 4, 'sentence_type_id' => $quoteType->id],
                ['content' => 'Я долго раздумывал над приключениями джунглей.', 'order' => 5, 'sentence_type_id' => $sentenceType->id],
                ['content' => 'И после нескольких попыток, сделанных цветным карандашом, я сумел нарисовать свою первую картинку.', 'order' => 6, 'sentence_type_id' => $sentenceType->id],
            ],
            RuEntitySeeder::SECOND_ENTITY_NAME => [
                ['content' => 'Старик и море', 'order' => 0, 'sentence_type_id' => $titleType->id],
                ['content' => 'Это был старик, который ловил рыбу в одиночку на лодке в течении Гольфстрима.', 'order' => 1, 'sentence_type_id' => $sentenceType->id],
                ['content' => 'Уже восемьдесят четыре дня он выходил в море и не поймал ни одной рыбы.', 'order' => 2, 'sentence_type_id' => $sentenceType->id],
                ['content' => 'Первые сорок дней с ним был мальчик.', 'order' => 3, 'sentence_type_id' => $sentenceType->id],
                ['content' => 'Но после сорока дней, когда родители мальчика увидели, что старик, по всей вероятности, уже и в самом деле salao, несчастнейший человек.', 'order' => 4, 'sentence_type_id' => $sentenceType->id],
            ],
            RuEntitySeeder::THIRD_ENTITY_NAME => [
                ['content' => 'Алисе надоело сидеть на берегу реки рядом с сестрой без дела.', 'order' => 1, 'sentence_type_id' => $sentenceType->id],
                ['content' => 'Один-два раза она заглянула в книгу, которую читала сестра.', 'order' => 2, 'sentence_type_id' => $sentenceType->id],
                ['content' => 'Вдруг мимо пробежал Белый Кролик с розовыми глазами.', 'order' => 3, 'sentence_type_id' => $sentenceType->id],
            ],
        ];

        foreach ($entities as $entityName => $sentences) {
            $entity = RuEntity::query()->where('name', $entityName)->firstOrFail();

            foreach ($sentences as $sentence) {
                RuEntitySentence::query()->updateOrCreate(
                    [
                        'ru_entity_id' => $entity->id,
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
