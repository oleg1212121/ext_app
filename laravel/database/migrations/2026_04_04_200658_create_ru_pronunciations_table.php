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
        Schema::create('ru_pronunciations', function (Blueprint $table) {
            $table->id();
            $table->string('path', 256)->comment('The path to the pronunciation of the word');
            $table->foreignId('ru_word_id')->comment("The word's id. Foreign key.")
                ->references('id')->on('ru_words')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('ru_transcription_type_id')->comment('The transcription type id. Foreign key.')
                ->references('id')->on('ru_transcription_types')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamps();
            $table->unique(['path', 'ru_word_id', 'ru_transcription_type_id'], 'uk_ru_word_path_transcription_id');
            $table->comment('Pronunciations for Russian words');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ru_pronunciations', function (Blueprint $table) {
            $table->dropUnique('uk_ru_word_path_transcription_id');
        });
        Schema::dropIfExists('ru_pronunciations');
    }
};
