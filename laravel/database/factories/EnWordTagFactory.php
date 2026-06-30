<?php

namespace Database\Factories;

use App\Models\EnWord;
use App\Models\EnWordTag;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnWordTag>
 */
class EnWordTagFactory extends Factory
{
    public function definition(): array
    {
        return [
            'en_word_id' => EnWord::factory(),
            'tag_id' => Tag::factory(),
        ];
    }
}
