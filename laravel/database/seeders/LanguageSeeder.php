<?php

namespace Database\Seeders;

use App\Models\Language;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class LanguageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $languages = [
            [
                'code' => 'en',
                'name' => 'English',
                'native_name' => 'English',
                'is_enabled' => true,
                'sort_order' => 0,
            ],
            [
                'code' => 'ru',
                'name' => 'Russian',
                'native_name' => 'Русский',
                'is_enabled' => true,
                'sort_order' => 1,
            ],
        ];

        Language::upsert($languages, ['code'], ['name', 'native_name', 'is_enabled', 'sort_order']);
    }
}
