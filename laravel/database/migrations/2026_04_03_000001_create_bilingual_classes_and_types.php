<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ru_word_classes', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique()->comment('The slug of the word class');
            $table->string('title', 256)->comment('The name of the word class. In related language.');
            $table->string('description', 1000)->nullable()->comment('The description of the word class. In related language.');
            $table->timestamps();
            $table->comment('Parts of speech for Russian language');
        });

        Schema::create('en_word_classes', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique()->comment('The slug of the word class');
            $table->string('title', 256)->comment('The name of the word class. In related language.');
            $table->string('description', 1000)->nullable()->comment('The description of the word class. In related language.');
            $table->timestamps();
            $table->comment('Parts of speech for English language');
        });

        Schema::create('en_transcription_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique()
                ->comment('The slug of the word transcription type.');
            $table->string('title', 256)->comment('The name of the word transcription type. In related language.');
            $table->string('description', 1000)->nullable()
                ->comment('The description of the word transcription type. In related language.');
            $table->timestamps();
            $table->comment('Transcription types for English language');
        });

        Schema::create('ru_transcription_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique()->comment('The slug of the word transcription type.');
            $table->string('title', 256)->comment('The name of the word transcription type. In related language.');
            $table->string('description', 1000)->nullable()->comment('The description of the word transcription type. In related language.');
            $table->timestamps();
            $table->comment('Transcription types for Russian language');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ru_transcription_types');
        Schema::dropIfExists('en_transcription_types');
        Schema::dropIfExists('en_word_classes');
        Schema::dropIfExists('ru_word_classes');
    }
};
