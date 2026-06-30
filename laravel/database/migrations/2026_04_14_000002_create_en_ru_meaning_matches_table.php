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
        Schema::create('en_ru_meaning_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('en_ru_entity_match_id')->comment('An id of the related (matched by meaning) en/ru entities. Foreign key.')
                ->references('id')->on('en_ru_entity_matches')
                ->cascadeOnDelete();
            $table->integer('order')->comment('Order of the meaning match pairs.');
            $table->decimal('similarity', 5, 4)->comment('Meaning match similarity assessment');
            $table->integer('alignment_chunk')->default(0)->comment('Chunk index that produced this meaning match');
            $table->timestamps();
            $table->comment('Shows relations between ru/en sentences (can be 1 to 1 or several to several) with the same meaning.');

            $table->unique(['en_ru_entity_match_id', 'order']);
            $table->index(['en_ru_entity_match_id', 'alignment_chunk']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('en_ru_meaning_matches');
    }
};
