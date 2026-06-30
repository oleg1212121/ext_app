<?php

namespace Database\Seeders;

use App\Models\RuWordClass;
use Illuminate\Database\Seeder;

class RuWordClassSeeder extends Seeder
{
    public function run(): void
    {
        $classes = [
            ['slug' => 'noun', 'title' => 'Существительное', 'description' => 'Часть речи, обозначающая предмет'],
            ['slug' => 'verb', 'title' => 'Глагол', 'description' => 'Часть речи, обозначающая действие'],
            ['slug' => 'adj', 'title' => 'Прилагательное', 'description' => 'Часть речи, обозначающая признак предмета'],
            ['slug' => 'adv', 'title' => 'Наречие', 'description' => 'Часть речи, обозначающая признак действия'],
            ['slug' => 'pron', 'title' => 'Местоимение', 'description' => 'Часть речи, указывающая на предмет без называния его'],
            ['slug' => 'prep', 'title' => 'Предлог', 'description' => 'Служебная часть речи, выражающая отношения между словами'],
            ['slug' => 'conj', 'title' => 'Союз', 'description' => 'Служебная часть речи, связывающая однородные члены или части сложного предложения'],
            ['slug' => 'det', 'title' => 'Определитель', 'description' => 'Служебное слово перед существительным'],
            ['slug' => 'num', 'title' => 'Числительное', 'description' => 'Часть речи, обозначающая количество или порядок предметов'],
            ['slug' => 'intj', 'title' => 'Междометие', 'description' => 'Часть речи, выражающая эмоции'],
            ['slug' => 'article', 'title' => 'Артикль', 'description' => 'Служебное слово перед существительным'],
            ['slug' => 'particle', 'title' => 'Частица', 'description' => 'Служебная часть речи, вносящая различные оттенки значения'],
            ['slug' => 'proper_noun', 'title' => 'Собственное существительное', 'description' => 'Имя собственное'],
            ['slug' => 'phrase', 'title' => 'Фраза', 'description' => 'Группа слов, функционирующая как единое целое'],
            ['slug' => 'suffix', 'title' => 'Суффикс', 'description' => 'Морфема, добавляемая к концу слова'],
            ['slug' => 'prefix', 'title' => 'Префикс', 'description' => 'Морфема, добавляемая к началу слова'],
            ['slug' => 'unknown', 'title' => 'Неизвестно', 'description' => 'Часть речи не определена'],
        ];

        RuWordClass::upsert($classes, ['slug'], ['title', 'description']);
    }
}
