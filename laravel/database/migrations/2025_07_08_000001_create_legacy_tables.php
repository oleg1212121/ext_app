<?php

use App\Models\Book;
use App\Models\Word;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('etymologies', function (Blueprint $table) {
            $table->id();
            $table->text('pos')->default('noun')->comment('Part of speech');
            $table->text('word')->comment('Word');
            $table->text('etymology')->comment('Etymology information');
            $table->comment('Word etymologies');
        });

        Schema::create('transcriptions', function (Blueprint $table) {
            $table->id();
            $table->text('pos')->default('noun')->comment('Part of speech');
            $table->text('word')->comment('Word');
            $table->text('transcription')->comment('Phonetic transcription');
            $table->comment('Word transcriptions');
        });

        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->text('pos')->default('noun')->comment('Part of speech');
            $table->text('word')->comment('Source word');
            $table->text('translation')->comment('Translation text');
            $table->comment('Word translations');
        });

        Schema::create('forms', function (Blueprint $table) {
            $table->id();
            $table->string('pos')->default('noun')->comment('Part of speech');
            $table->string('word')->comment('Base word');
            $table->string('lword')->nullable(true)->comment('Lowercase word');
            $table->integer('word_id')->nullable(true)->comment('Word ID reference');
            $table->string('form')->comment('Word form');
            $table->string('lform')->nullable(true)->comment('Lowercase form');
            $table->comment('Word forms (conjugations, declensions)');
        });

        Schema::create('definitions', function (Blueprint $table) {
            $table->id();
            $table->text('pos')->default('noun')->comment('Part of speech');
            $table->text('word')->comment('Word');
            $table->text('lword')->nullable(true)->comment('Lowercase word');
            $table->integer('word_id')->nullable(true)->comment('Word ID reference');
            $table->text('definition')->comment('Definition text');
            $table->boolean('is_obsolete')->default(false)->comment('Whether definition is obsolete');
            $table->comment('Word definitions');
        });

        Schema::create('words', function (Blueprint $table) {
            $table->id();
            $table->string('word', 256)->unique()->comment('Word text');
            $table->string('lword', 256)->nullable(true)->comment('Lowercase word');
            $table->boolean('is_full')->default(false)->comment('Whether word is complete');
            $table->boolean('is_known')->default(false)->comment('Whether word is known');
            $table->boolean('has_definitions')->default(false)->comment('Whether word has definitions');
            $table->boolean('for_crossword')->default(false)->comment('Whether word is suitable for crosswords');
            $table->integer('knowledge')->default(0)->comment('Knowledge level');
            $table->integer('less_100')->default(0)->comment('Frequency rank < 100');
            $table->integer('less_500')->default(0)->comment('Frequency rank < 500');
            $table->integer('less_1000')->default(0)->comment('Frequency rank < 1000');
            $table->integer('less_3000')->default(0)->comment('Frequency rank < 3000');
            $table->integer('less_5000')->default(0)->comment('Frequency rank < 5000');
            $table->integer('less_8000')->default(0)->comment('Frequency rank < 8000');
            $table->integer('less_10000')->default(0)->comment('Frequency rank < 10000');
            $table->integer('less_20000')->default(0)->comment('Frequency rank < 20000');
            $table->integer('less_50000')->default(0)->comment('Frequency rank < 50000');
            $table->integer('less_1000000')->default(0)->comment('Frequency rank < 1000000');
            $table->timestamps();
            $table->comment('Main words table with frequency and knowledge tracking');
        });

        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->string('name', 512)->unique()->comment('Book name');
            $table->string('description', 2048)->nullable()->comment('Book description');
            $table->timestamps();
            $table->comment('Books for reading practice');
        });

        Schema::create('book_word', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Book::class, 'book_id')->comment('Book ID. Foreign key.')->onDelete('cascade')->onUpdate('cascade');
            $table->foreignIdFor(Word::class, 'word_id')->comment('Word ID. Foreign key.')->onDelete('cascade')->onUpdate('cascade');
            $table->boolean('is_solved')->default(false)->comment('Whether word is solved in crossword');
            $table->integer('count')->default(1)->comment('Word occurrence count');
            $table->comment('Book-word relationships with occurrence counts');
        });

        Schema::create('saved_phrases', function (Blueprint $table) {
            $table->id();
            $table->text('phrase')->nullable(false)->comment('Saved phrase text');
            $table->timestamps();
            $table->comment('User saved phrases');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_phrases');
        Schema::dropIfExists('book_word');
        Schema::dropIfExists('books');
        Schema::dropIfExists('words');
        Schema::dropIfExists('definitions');
        Schema::dropIfExists('forms');
        Schema::dropIfExists('translations');
        Schema::dropIfExists('transcriptions');
        Schema::dropIfExists('etymologies');
    }
};
