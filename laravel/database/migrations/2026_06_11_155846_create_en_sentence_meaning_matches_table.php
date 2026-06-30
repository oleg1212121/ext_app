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
        Schema::create('en_sentence_meaning_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('en_entity_sentence_id')->comment('Link to en sentence. Foreign key.')
                ->references('id')->on('en_entity_sentences')
                ->cascadeOnDelete();
            $table->foreignId('en_ru_meaning_match_id')->comment('Link to a meaning unit. Foreign key.')
                ->references('id')->on('en_ru_meaning_matches')
                ->cascadeOnDelete();
            $table->integer('order')->comment('Order of an en sentence within 1 separate meaning match unit.');
            $table->timestamps();
            $table->comment('Stores a list of en sentences that match a meaning unit.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('en_sentence_meaning_matches');
    }
};
