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
        Schema::create('en_pronunciations', function (Blueprint $table) {
            $table->id();
            $table->string('path', 256)
                ->comment('The path to the pronunciation of the word');
            $table->foreignId('en_word_id')
                ->comment("The word's id. Foreign key.")
                ->references('id')->on('en_words')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('en_transcription_type_id')->comment('The transcription type id. Foreign key.')
                ->references('id')->on('en_transcription_types')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamps();
            $table->unique(['path', 'en_word_id', 'en_transcription_type_id'], 'uk_en_word_path_transcription_id');
            $table->comment('Pronunciations for English words');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('en_pronunciations', function (Blueprint $table) {
            $table->dropUnique('uk_en_word_path_transcription_id');
        });
        Schema::dropIfExists('en_pronunciations');
    }
};
