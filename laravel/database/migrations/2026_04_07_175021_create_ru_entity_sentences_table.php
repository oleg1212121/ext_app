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
        Schema::create('ru_entity_sentences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ru_entity_id')->comment("The entity's id. Foreign key.")
                ->references('id')->on('ru_entities')
                ->cascadeOnDelete();
            $table->foreignId('sentence_type_id')
                ->nullable(true)
                ->comment("The sentence type's id. Foreign key.")
                ->references('id')->on('sentence_types')
                ->nullOnDelete();
            $table->text('content')->comment('Sentence content');
            $table->integer('order')->default(0)->comment('Sort order');
            $table->timestamps();
            $table->comment('Sentences extracted from Russian entities');
            $table->index(['ru_entity_id', 'order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ru_entity_sentences');
    }
};
