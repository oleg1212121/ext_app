<?php

namespace Database\Factories;

use App\Models\Book;
use App\Models\BookTextFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookTextFile>
 */
class BookTextFileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->sentence(2),
            'path' => fake()->filePath(),
            'book_id' => Book::factory(),
            'lang' => fake()->randomElement(['en', 'ru', 'es', 'fr', 'de']),
        ];
    }
}
