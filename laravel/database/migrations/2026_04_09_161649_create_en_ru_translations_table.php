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
        Schema::create('en_ru_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('en_word_id')->comment('English word id')
                ->references('id')->on('en_words')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('ru_word_id')->comment('Russian word id')
                ->references('id')->on('ru_words')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamps();
            $table->unique(['en_word_id', 'ru_word_id'], 'uk_en_ru_word_ids');
            $table->comment('English to Russian translations (pivot table)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('en_ru_translations');
    }
};
