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
        Schema::create('ru_sentence_meaning_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ru_entity_sentence_id')->comment('Link to ru sentence. Foreign key.')
                ->references('id')->on('ru_entity_sentences')
                ->cascadeOnDelete();
            $table->foreignId('en_ru_meaning_match_id')->comment('Link to a meaning unit. Foreign key.')
                ->references('id')->on('en_ru_meaning_matches')
                ->cascadeOnDelete();
            $table->integer('order')->comment('Order of a ru sentence within 1 separate meaning match unit.');
            $table->timestamps();
            $table->comment('Stores a list of ru sentences that match a meaning unit.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ru_sentence_meaning_matches');
    }
};
