<?php

namespace Database\Factories;

use App\Models\Book;
use App\Models\BookWord;
use App\Models\Word;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookWord>
 */
class BookWordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'book_id' => Book::factory(),
            'word_id' => Word::factory(),
            'count' => fake()->numberBetween(1, 100),
            'is_solved' => fake()->boolean(),
        ];
    }
}
