<?php

use App\Models\Book;
use App\Models\Word;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('book_word', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Book::class, 'book_id')->comment('Book ID. Foreign key.')->onDelete('cascade')->onUpdate('cascade');
            $table->foreignIdFor(Word::class, 'word_id')->comment('Word ID. Foreign key.')->onDelete('cascade')->onUpdate('cascade');
            $table->boolean('is_solved')->default(false)->comment('Whether word is solved in crossword');
            $table->integer('count')->default(1)->comment('Word occurrence count');
            $table->comment('Book-word relationships with occurrence counts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('book_word');
    }
};
