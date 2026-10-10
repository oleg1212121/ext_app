<?php

namespace Database\Seeders;

use App\Models\Language;
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
                'is_interface_enabled' => true,
            ],
            [
                'code' => 'ru',
                'name' => 'Russian',
                'native_name' => 'Русский',
                'is_enabled' => true,
                'sort_order' => 1,
                'is_interface_enabled' => true,
            ],
            [
                // Belarusian: a content language from day one; the interface
                // flag stays off until its UI strings ship.
                'code' => 'be',
                'name' => 'Belarusian',
                'native_name' => 'Беларуская',
                'is_enabled' => true,
                'sort_order' => 2,
                'is_interface_enabled' => false,
            ],
        ];

        Language::upsert($languages, ['code'], ['name', 'native_name', 'is_enabled', 'sort_order', 'is_interface_enabled']);
    }
}
