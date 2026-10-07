<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_entity_word_knowledge', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->comment("The user's id. Foreign key.")
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('entity_id')
                ->comment("The entity's id. Foreign key.")
                ->constrained('entities')
                ->cascadeOnDelete();
            $table->decimal('score', 5, 2)
                ->nullable()
                ->comment('Occurrence-weighted share of the entity\'s linked word occurrences the user knows, 0-100; null when the entity has no dictionary-linked words.');
            $table->timestamp('computed_at')
                ->comment('When the score was last computed; the refresh sweep compares it against entities.words_indexed_at and a 3-day age cap.');
            $table->timestamps();
            $table->comment('Per-user word knowledge snapshot for one entity, written on first view and refreshed in place (no history).');

            $table->unique(['user_id', 'entity_id'], 'uk_user_entity_word_knowledge_pair');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_entity_word_knowledge');
    }
};
