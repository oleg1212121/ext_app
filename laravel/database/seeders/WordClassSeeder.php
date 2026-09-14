<?php

namespace Database\Seeders;

use App\Models\Language;
use App\Models\WordClass;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WordClassSeeder extends Seeder
{
    /**
     * @var array<string, list<array{slug: string, title: string, description: string}>>
     */
    private const CLASSES = [
        'en' => [
            ['slug' => 'noun', 'title' => 'Noun', 'description' => 'A word that represents a person, place, thing, or idea'],
            ['slug' => 'verb', 'title' => 'Verb', 'description' => 'A word that expresses an action, event, or state of being'],
            ['slug' => 'adj', 'title' => 'Adjective', 'description' => 'A word that describes or modifies a noun'],
            ['slug' => 'adv', 'title' => 'Adverb', 'description' => 'A word that modifies a verb, adjective, or other adverb'],
            ['slug' => 'pron', 'title' => 'Pronoun', 'description' => 'A word used in place of a noun'],
            ['slug' => 'prep', 'title' => 'Preposition', 'description' => 'A word that shows the relationship between a noun and other words'],
            ['slug' => 'conj', 'title' => 'Conjunction', 'description' => 'A word that connects words, phrases, or clauses'],
            ['slug' => 'det', 'title' => 'Determiner', 'description' => 'A word that introduces a noun'],
            ['slug' => 'num', 'title' => 'Numeral', 'description' => 'A word that expresses a number'],
            ['slug' => 'intj', 'title' => 'Interjection', 'description' => 'A word that expresses strong emotion'],
            ['slug' => 'article', 'title' => 'Article', 'description' => 'A word used before a noun to indicate definiteness'],
            ['slug' => 'particle', 'title' => 'Particle', 'description' => 'A word that has a grammatical function but does not fit into other categories'],
            ['slug' => 'proper_noun', 'title' => 'Proper Noun', 'description' => 'A specific name for a particular person, place, or thing'],
            ['slug' => 'phrase', 'title' => 'Phrase', 'description' => 'A group of words that functions as a unit'],
            ['slug' => 'suffix', 'title' => 'Suffix', 'description' => 'A morpheme added at the end of a word'],
            ['slug' => 'prefix', 'title' => 'Prefix', 'description' => 'A morpheme added at the beginning of a word'],
            ['slug' => 'unknown', 'title' => 'Unknown', 'description' => 'Part of speech could not be determined'],
        ],
        'ru' => [
            ['slug' => 'noun', 'title' => 'Существительное', 'description' => 'Часть речи, обозначающая предмет'],
            ['slug' => 'verb', 'title' => 'Глагол', 'description' => 'Часть речи, обозначающая действие'],
            ['slug' => 'adj', 'title' => 'Прилагательное', 'description' => 'Часть речи, обозначающая признак предмета'],
            ['slug' => 'adv', 'title' => 'Наречие', 'description' => 'Часть речи, обозначающая признак действия'],
            ['slug' => 'pron', 'title' => 'Местоимение', 'description' => 'Часть речи, указывающая на предмет без называния его'],
            ['slug' => 'prep', 'title' => 'Предлог', 'description' => 'Служебная часть речи, выражающая отношения между словами'],
            ['slug' => 'conj', 'title' => 'Союз', 'description' => 'Служебная часть речи, соединяющая слова и предложения'],
            ['slug' => 'det', 'title' => 'Определитель', 'description' => 'Слово, указывающее на существительное'],
            ['slug' => 'num', 'title' => 'Числительное', 'description' => 'Часть речи, обозначающая количество'],
            ['slug' => 'intj', 'title' => 'Междометие', 'description' => 'Часть речи, выражающая сильные эмоции'],
            ['slug' => 'article', 'title' => 'Артикль', 'description' => 'Слово перед существительным, указывающее на его определённость'],
            ['slug' => 'particle', 'title' => 'Частица', 'description' => 'Служебное слово, придающее различные оттенки значения'],
            ['slug' => 'proper_noun', 'title' => 'Имя собственное', 'description' => 'Название конкретного лица, предмета или явления'],
            ['slug' => 'phrase', 'title' => 'Фраза', 'description' => 'Группа слов, функционирующая как единое целое'],
            ['slug' => 'suffix', 'title' => 'Суффикс', 'description' => 'Морфема, добавляемая в конец слова'],
            ['slug' => 'prefix', 'title' => 'Префикс', 'description' => 'Морфема, добавляемая в начало слова'],
            ['slug' => 'unknown', 'title' => 'Неизвестно', 'description' => 'Часть речи не удалось определить'],
        ],
    ];

    public function run(): void
    {
        $languageIds = Language::query()
            ->whereIn('code', array_keys(self::CLASSES))
            ->pluck('id', 'code');

        foreach (self::CLASSES as $code => $classes) {
            $languageId = $languageIds[$code] ?? null;

            if ($languageId === null) {
                continue;
            }

            foreach ($classes as $class) {
                WordClass::query()->updateOrCreate(
                    [
                        'language_id' => $languageId,
                        'slug' => $class['slug'],
                    ],
                    [
                        'title' => $class['title'],
                        'description' => $class['description'],
                    ],
                );
            }
        }

        // Drop classes for languages that no longer exist.
        WordClass::query()
            ->whereNotIn('language_id', DB::table('languages')->pluck('id'))
            ->delete();
    }
}
