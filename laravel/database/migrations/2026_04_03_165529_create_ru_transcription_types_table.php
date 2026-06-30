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
        Schema::create('ru_transcription_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique()->comment('The slug of the word transcription type.');
            $table->string('title', 256)->comment('The name of the word transcription type. In related language.');
            $table->string('description', 1000)->nullable()->comment('The description of the word transcription type. In related language.');
            $table->timestamps();
            $table->comment('Transcription types for Russian language');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ru_transcription_types');
    }
};
