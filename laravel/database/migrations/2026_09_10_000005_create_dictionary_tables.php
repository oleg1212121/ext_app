<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('word_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')
                ->comment('The language this class applies to. Foreign key.')
                ->constrained('languages')
                ->cascadeOnDelete();
            $table->string('slug', 100)->comment('The slug of the word class');
            $table->string('title', 256)->comment('The name of the word class. In the class language.');
            $table->string('description', 1000)->nullable()->comment('The description of the word class. In the class language.');
            $table->timestamps();
            $table->comment('Parts of speech, per language');

            $table->unique(['language_id', 'slug']);
        });

        Schema::create('transcription_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')
                ->comment('The language this type applies to. Foreign key.')
                ->constrained('languages')
                ->cascadeOnDelete();
            $table->string('slug', 100)->comment('The slug of the word transcription type.');
            $table->string('title', 256)->comment('The name of the word transcription type. In the type language.');
            $table->string('description', 1000)->nullable()->comment('The description of the word transcription type. In the type language.');
            $table->timestamps();
            $table->comment('Transcription types, per language');

            $table->unique(['language_id', 'slug']);
        });

        Schema::create('words', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')
                ->comment("The word's language. Foreign key.")
                ->constrained('languages')
                ->cascadeOnDelete();
            $table->string('word', 256)->comment("The word's main form");
            $table->string('l_word', 256)->nullable()->comment("The word's lowercase form");
            $table->decimal('frequency')->default(0)->comment("The word's frequency. More the number - more the frequency");
            $table->foreignId('word_class_id')->comment("The word's class id. Foreign key.")
                ->constrained('word_classes')
                ->cascadeOnDelete();
            $table->json('translations')->nullable()
                ->comment('Raw translation words from Wiktionary for later linking');
            $table->timestamps();
            $table->comment('Words (base form), any language. Different parts of speech might be duplicated');

            $table->unique(['word', 'language_id', 'word_class_id'], 'uk_words_word_language_class');
            $table->index(['l_word', 'word_class_id'], 'idx_words_l_word_class');
            $table->index('language_id', 'idx_words_language_id');
        });

        Schema::create('forms', function (Blueprint $table) {
            $table->id();
            $table->string('form', 256)->comment('The form');
            $table->string('l_word', 256)->nullable()->comment("The form's lowercase form");
            $table->foreignId('word_id')->comment("The word's id. Foreign key.")
                ->constrained('words')
                ->cascadeOnDelete();
            $table->timestamps();
            $table->comment('Word forms');

            $table->unique(['form', 'word_id'], 'uk_forms_form_word_id');
        });

        Schema::create('definitions', function (Blueprint $table) {
            $table->id();
            $table->string('definition', 500)->comment('The definition of the word');
            $table->foreignId('word_id')->comment("The word's id. Foreign key.")
                ->constrained('words')
                ->cascadeOnDelete();
            $table->timestamps();
            $table->comment('Definitions for words');
        });

        Schema::create('transcriptions', function (Blueprint $table) {
            $table->id();
            $table->string('transcription', 100)->comment('The transcription of the word');
            $table->foreignId('word_id')->comment("The word's id. Foreign key.")
                ->constrained('words')
                ->cascadeOnDelete();
            $table->foreignId('transcription_type_id')->comment('The transcription type id. Foreign key.')
                ->constrained('transcription_types')
                ->cascadeOnDelete();
            $table->timestamps();
            $table->comment('Transcriptions for words');

            $table->unique(['transcription', 'word_id', 'transcription_type_id'], 'uk_transcriptions_triple');
        });

        Schema::create('etymologies', function (Blueprint $table) {
            $table->id();
            $table->string('etymology', 1000)->comment('The etymology of the word');
            $table->foreignId('word_id')->comment("The word's id. Foreign key.")
                ->constrained('words')
                ->cascadeOnDelete();
            $table->timestamps();
            $table->comment('Etymologies for words');
        });

        Schema::create('pronunciations', function (Blueprint $table) {
            $table->id();
            $table->string('path', 256)->comment('Path to the audio file with a pronunciation example of the word');
            $table->foreignId('word_id')->comment("The word's id. Foreign key.")
                ->constrained('words')
                ->cascadeOnDelete();
            $table->timestamps();
            $table->comment('Audio files with pronunciation examples for words');

            $table->unique(['path', 'word_id'], 'uk_pronunciations_path_word_id');
        });

        Schema::create('examples', function (Blueprint $table) {
            $table->id();
            $table->string('example', 500)->comment("An example of the word's usage");
            $table->foreignId('word_id')->comment("The word's id. Foreign key.")
                ->constrained('words')
                ->cascadeOnDelete();
            $table->timestamps();
            $table->comment('Examples for word usage');

            $table->unique(['example', 'word_id'], 'uk_examples_example_word_id');
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique()->comment('URL-friendly identifier for the tag');
            $table->string('name', 256)->comment('Display name of the tag');
            $table->timestamps();
            $table->comment('Word tags (e.g. most used, science and so on)');
        });

        Schema::create('word_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('word_id')->comment("The word's id. Foreign key.")
                ->constrained('words')
                ->cascadeOnDelete();
            $table->foreignId('tag_id')->comment("The tag's id. Foreign key.")
                ->constrained('tags')
                ->cascadeOnDelete();
            $table->timestamps();
            $table->comment('Tags for words');

            $table->unique(['word_id', 'tag_id'], 'uk_word_tags_pair');
        });

        Schema::create('word_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('word_a_id')->comment('The a-side word of the pair. Foreign key.')
                ->constrained('words')
                ->cascadeOnDelete();
            $table->foreignId('word_b_id')->comment('The b-side word of the pair. Foreign key.')
                ->constrained('words')
                ->cascadeOnDelete();
            $table->timestamps();
            $table->comment('Translation links: one row per word pair (canonical order: word_a_id < word_b_id).');

            $table->unique(['word_a_id', 'word_b_id'], 'uk_word_translations_pair');
            $table->index('word_b_id', 'idx_word_translations_word_b_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('word_translations');
        Schema::dropIfExists('word_tags');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('examples');
        Schema::dropIfExists('pronunciations');
        Schema::dropIfExists('etymologies');
        Schema::dropIfExists('transcriptions');
        Schema::dropIfExists('definitions');
        Schema::dropIfExists('forms');
        Schema::dropIfExists('words');
        Schema::dropIfExists('transcription_types');
        Schema::dropIfExists('word_classes');
    }
};
