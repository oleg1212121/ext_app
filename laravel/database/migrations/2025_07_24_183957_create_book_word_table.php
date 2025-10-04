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
            $table->foreignIdFor(Book::class, 'book_id')->onDelete('cascade')->onUpdate('cascade');
            $table->foreignIdFor(Word::class, 'word_id')->onDelete('cascade')->onUpdate('cascade');
            $table->boolean('is_solved')->default(false);
            $table->integer('count')->default(1);
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
