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
        'be' => [
            ['slug' => 'noun', 'title' => 'назоўнік', 'description' => 'Часціна мовы, якая абазначае прадмет'],
            ['slug' => 'verb', 'title' => 'дзеяслоў', 'description' => 'Часціна мовы, якая абазначае дзеянне'],
            ['slug' => 'adj', 'title' => 'прыметнік', 'description' => 'Часціна мовы, якая абазначае прыкмету прадмета'],
            ['slug' => 'adv', 'title' => 'прыслоўе', 'description' => 'Часціна мовы, якая абазначае прыкмету дзеяння'],
            ['slug' => 'pron', 'title' => 'займеннік', 'description' => 'Часціна мовы, якая паказвае на прадмет, не называючы яго'],
            ['slug' => 'prep', 'title' => 'прыназоўнік', 'description' => 'Службовая часціна мовы, якая выражае адносіны паміж словамі'],
            ['slug' => 'conj', 'title' => 'злучнік', 'description' => 'Службовая часціна мовы, якая злучае словы і сказы'],
            ['slug' => 'det', 'title' => 'вызначальнік', 'description' => 'Слова, якое паказвае на назоўнік'],
            ['slug' => 'num', 'title' => 'лічэўнік', 'description' => 'Часціна мовы, якая абазначае колькасць'],
            ['slug' => 'intj', 'title' => 'выклічнік', 'description' => 'Часціна мовы, якая выражае моцныя эмоцыі'],
            ['slug' => 'article', 'title' => 'артыкль', 'description' => 'Слова перад назоўнікам, якое паказвае на яго вызначанасць'],
            ['slug' => 'particle', 'title' => 'часціца', 'description' => 'Службовае слова, якое надае розныя адценні значэння'],
            ['slug' => 'proper_noun', 'title' => 'Уласнае імя', 'description' => 'Назва канкрэтнай асобы, прадмета або з\'явы'],
            ['slug' => 'phrase', 'title' => 'фраза', 'description' => 'Група слоў, што ўспрымаецца як адзінае цэлае'],
            ['slug' => 'suffix', 'title' => 'суфікс', 'description' => 'Марфема, якая далучаецца пасля кораня слова'],
            ['slug' => 'prefix', 'title' => 'прэфікс', 'description' => 'Марфема, якая далучаецца перад коранем слова'],
            ['slug' => 'unknown', 'title' => 'невядома', 'description' => 'Не ўдалося вызначыць часціну мовы'],
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
