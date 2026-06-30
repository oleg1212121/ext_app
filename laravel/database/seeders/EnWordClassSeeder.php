<?php

namespace Database\Seeders;

use App\Models\EnWordClass;
use Illuminate\Database\Seeder;

class EnWordClassSeeder extends Seeder
{
    public function run(): void
    {
        $classes = [
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
        ];

        EnWordClass::upsert($classes, ['slug'], ['title', 'description']);
    }
}
