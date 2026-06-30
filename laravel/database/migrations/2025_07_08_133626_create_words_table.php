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
        Schema::create('words', function (Blueprint $table) {
            $table->id();
            $table->string('word', 256)->unique()->comment('Word text');
            $table->string('lword', 256)->nullable(true)->comment('Lowercase word');
            $table->boolean('is_full')->default(false)->comment('Whether word is complete');
            $table->boolean('is_known')->default(false)->comment('Whether word is known');
            $table->boolean('has_definitions')->default(false)->comment('Whether word has definitions');
            $table->boolean('for_crossword')->default(false)->comment('Whether word is suitable for crosswords');
            $table->integer('knowledge')->default(0)->comment('Knowledge level');
            $table->integer('less_100')->default(0)->comment('Frequency rank < 100');
            $table->integer('less_500')->default(0)->comment('Frequency rank < 500');
            $table->integer('less_1000')->default(0)->comment('Frequency rank < 1000');
            $table->integer('less_3000')->default(0)->comment('Frequency rank < 3000');
            $table->integer('less_5000')->default(0)->comment('Frequency rank < 5000');
            $table->integer('less_8000')->default(0)->comment('Frequency rank < 8000');
            $table->integer('less_10000')->default(0)->comment('Frequency rank < 10000');
            $table->integer('less_20000')->default(0)->comment('Frequency rank < 20000');
            $table->integer('less_50000')->default(0)->comment('Frequency rank < 50000');
            $table->integer('less_1000000')->default(0)->comment('Frequency rank < 1000000');
            $table->timestamps();
            $table->comment('Main words table with frequency and knowledge tracking');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('words');
    }
};
