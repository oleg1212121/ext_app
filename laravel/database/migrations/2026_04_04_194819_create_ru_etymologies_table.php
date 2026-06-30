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
        Schema::create('ru_etymologies', function (Blueprint $table) {
            $table->id();
            $table->string('etymology', 1000)->comment('The etymology of the word');
            $table->foreignId('ru_word_id')->comment("The word's id. Foreign key.")
                ->references('id')->on('ru_words')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamps();
            $table->comment('Etymologies for Russian words');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ru_etymologies');
    }
};
