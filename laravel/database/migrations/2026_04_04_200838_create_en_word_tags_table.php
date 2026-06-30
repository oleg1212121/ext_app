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
        Schema::create('en_word_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('en_word_id')->comment("The word's id. Foreign key.")
                ->references('id')->on('en_words')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('tag_id')->comment("The tag's id. Foreign key.")
                ->references('id')->on('tags')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamps();
            $table->unique(['en_word_id', 'tag_id'], 'uk_en_word_tag');
            $table->comment('Tags for English words');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('en_word_tags');
    }
};
