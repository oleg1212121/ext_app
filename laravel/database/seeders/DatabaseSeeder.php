<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            AiProviderSeeder::class,
            SentenceTypeSeeder::class,
            EnEntitySeeder::class,
            RuEntitySeeder::class,
            EnEntitySentenceSeeder::class,
            RuEntitySentenceSeeder::class,
            EnRuEntityMatchSeeder::class,
            EnRuMeaningMatchSeeder::class,
            EnSentenceMeaningMatchSeeder::class,
            RuSentenceMeaningMatchSeeder::class,
        ]);
    }
}
