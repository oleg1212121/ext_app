<?php

namespace Database\Factories;

use App\Models\RuWord;
use App\Models\RuWordTag;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RuWordTag>
 */
class RuWordTagFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ru_word_id' => RuWord::factory(),
            'tag_id' => Tag::factory(),
        ];
    }
}
