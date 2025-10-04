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
            $table->string('word', 256)->unique();
            $table->string('lword', 256)->nullable(true);
            $table->boolean('is_full')->default(false);
            $table->boolean('is_known')->default(false);
            $table->boolean('has_definitions')->default(false);
            $table->boolean('for_crossword')->default(false);
            $table->integer('knowledge')->default(0);
            $table->integer('less_100')->default(0);
            $table->integer('less_500')->default(0);
            $table->integer('less_1000')->default(0);
            $table->integer('less_3000')->default(0);
            $table->integer('less_5000')->default(0);
            $table->integer('less_8000')->default(0);
            $table->integer('less_10000')->default(0);
            $table->integer('less_20000')->default(0);
            $table->integer('less_50000')->default(0);
            $table->integer('less_1000000')->default(0);
            $table->timestamps();
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
