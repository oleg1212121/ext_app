<?php

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
        Schema::create('ru_examples', function (Blueprint $table) {
            $table->id();
            $table->string('example', 500)->comment("An example of the word's usage");
            $table->foreignId('ru_word_id')->comment("The word's id. Foreign key.")
                ->references('id')->on('ru_words')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamps();
            $table->unique(['example', 'ru_word_id'], 'uk_ru_example_word_id');
            $table->comment('Examples for Russian words usage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ru_examples', function (Blueprint $table) {
            $table->dropUnique('uk_ru_example_word_id');
        });
        Schema::dropIfExists('ru_examples');
    }
};
