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
        Schema::create('en_word_classes', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique()->comment('The slug of the word class');
            $table->string('title', 256)->comment('The name of the word class. In related language.');
            $table->string('description', 1000)->nullable()->comment('The description of the word class. In related language.');
            $table->timestamps();
            $table->comment('Parts of speech for English language');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('en_word_classes');
    }
};
