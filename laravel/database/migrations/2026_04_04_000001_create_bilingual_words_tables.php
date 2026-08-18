<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('en_words', function (Blueprint $table) {
            $table->id();
            $table->string('word', 256)->comment("The word's main form");
            $table->string('l_word', 256)->nullable()->comment("The word's lowercase form");
            $table->decimal('frequency')->default(0)->comment("The word's frequency. More the number - more the frequency");
            $table->foreignId('en_word_class_id')->comment("The word's class id")
                ->references('id')->on('en_word_classes')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->json('translations')->nullable()->after('en_word_class_id')
                ->comment('Raw translation words from Wiktionary for later linking');
            $table->timestamps();
            $table->comment('English words (base form). Different parts of speech might be duplicated');
            $table->index(['l_word', 'en_word_class_id'], 'idx_en_words_l_word_class');
            $table->unique(['word', 'en_word_class_id'], 'uk_en_word_class_id');
        });

        Schema::create('ru_words', function (Blueprint $table) {
            $table->id();
            $table->string('word', 256)->comment("The word's main form");
            $table->string('l_word', 256)->nullable()->comment("The word's lowercase form");
            $table->decimal('frequency')->default(0)->comment("The word's frequency. More the number - more the frequency");
            $table->foreignId('ru_word_class_id')->comment("The word's class id")
                ->references('id')->on('ru_word_classes')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->json('translations')->nullable()->after('ru_word_class_id')
                ->comment('Raw translation words from Wiktionary for later linking');
            $table->timestamps();
            $table->comment('Russian words (base form). Different parts of speech might be duplicated');
            $table->unique(['word', 'ru_word_class_id'], 'uk_ru_word_class_id');
            $table->index(['l_word', 'ru_word_class_id'], 'idx_ru_words_l_word_class');
        });

        Schema::create('en_forms', function (Blueprint $table) {
            $table->id();
            $table->string('form', 256)->comment('The form');
            $table->string('l_word', 256)->nullable()
                ->comment("The form's lowercase form");
            $table->foreignId('en_word_id')->comment("The word's id")
                ->references('id')->on('en_words')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamps();
            $table->unique(['form', 'en_word_id'], 'uk_en_form_word_id');
            $table->comment('Forms of English words');
        });

        Schema::create('en_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('definition', 500)->comment('The definition of the word');
            $table->foreignId('en_word_id')->comment("The word's id. Foreign key.")
                ->references('id')->on('en_words')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamps();
            $table->comment('Definitions for English words');
        });

        Schema::create('en_transcriptions', function (Blueprint $table) {
            $table->id();
            $table->string('transcription', 100)->comment('The transcription of the word');
            $table->foreignId('en_word_id')->comment("The word's id. Foreign key.")
                ->references('id')->on('en_words')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('en_transcription_type_id')->comment('The transcription type id. Foreign key.')
                ->references('id')->on('en_transcription_types')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamps();
            $table->unique(['transcription', 'en_word_id', 'en_transcription_type_id'], 'uk_en_word_transcription_id');
            $table->comment('Transcriptions for English words');
        });

        Schema::create('en_etymologies', function (Blueprint $table) {
            $table->id();
            $table->string('etymology', 1000)->comment('The etymology of the word');
            $table->foreignId('en_word_id')->comment("The word's id. Foreign key.")
                ->references('id')->on('en_words')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamps();
            $table->comment('Etymologies for English words');
        });

        Schema::create('ru_forms', function (Blueprint $table) {
            $table->id();
            $table->string('form', 256)->comment('The form');
            $table->string('l_word', 256)->nullable()->comment("The form's lowercase form");
            $table->foreignId('ru_word_id')->comment("The word's id")
                ->references('id')->on('ru_words')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamps();
            $table->unique(['form', 'ru_word_id'], 'uk_ru_form_word_id');
            $table->comment('Forms of Russian words');
        });

        Schema::create('ru_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('definition', 500)->comment('The definition of the word');
            $table->foreignId('ru_word_id')->comment("The word's id. Foreign key.")
                ->references('id')->on('ru_words')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamps();
            $table->comment('Definitions for Russian words');
        });

        Schema::create('ru_transcriptions', function (Blueprint $table) {
            $table->id();
            $table->string('transcription', 100)->comment('The transcription of the word');
            $table->foreignId('ru_word_id')->comment("The word's id. Foreign key.")
                ->references('id')->on('ru_words')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('ru_transcription_type_id')->comment('The transcription type id. Foreign key.')
                ->references('id')->on('ru_transcription_types')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamps();
            $table->unique(['transcription', 'ru_word_id', 'ru_transcription_type_id'], 'uk_ru_word_transcription_id');
            $table->comment('Transcriptions for Russian words');
        });

        Schema::create('ru_etymologies', function (Blueprint $table) {
            $table->id();
            $table->string('etymology', 1000)->comment('The etymology of the word');
            $table->foreignId('ru_word_id')->comment("The word's id. Foreign key.")
                ->references('id')->on('ru_words')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamps();
            $table->comment('Etymologies for Russian words');
        });

        Schema::create('en_pronunciations', function (Blueprint $table) {
            $table->id();
            $table->string('path', 256)
                ->comment('The path to the pronunciation of the word');
            $table->foreignId('en_word_id')
                ->comment("The word's id. Foreign key.")
                ->references('id')->on('en_words')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('en_transcription_type_id')->comment('The transcription type id. Foreign key.')
                ->references('id')->on('en_transcription_types')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamps();
            $table->unique(['path', 'en_word_id', 'en_transcription_type_id'], 'uk_en_word_path_transcription_id');
            $table->comment('Pronunciations for English words');
        });

        Schema::create('en_examples', function (Blueprint $table) {
            $table->id();
            $table->string('example', 500)
                ->comment("An example of the word's usage");
            $table->foreignId('en_word_id')
                ->comment("The word's id. Foreign key.")
                ->references('id')->on('en_words')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamps();
            $table->unique(['example', 'en_word_id'], 'uk_en_example_word_id');
            $table->comment('Examples for English words usage');
        });

        Schema::create('ru_pronunciations', function (Blueprint $table) {
            $table->id();
            $table->string('path', 256)->comment('The path to the pronunciation of the word');
            $table->foreignId('ru_word_id')->comment("The word's id. Foreign key.")
                ->references('id')->on('ru_words')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('ru_transcription_type_id')->comment('The transcription type id. Foreign key.')
                ->references('id')->on('ru_transcription_types')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamps();
            $table->unique(['path', 'ru_word_id', 'ru_transcription_type_id'], 'uk_ru_word_path_transcription_id');
            $table->comment('Pronunciations for Russian words');
        });

        Schema::create('ru_examples', function (Blueprint $table) {
            $table->id();
            $table->string('example', 500)->comment("An example of the word's usage");
            $table->foreignId('ru_word_id')->comment("The word's id. Foreign key.")
                ->references('id')->on('ru_words')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamps();
            $table->unique(['example', 'ru_word_id'], 'uk_ru_example_word_id');
            $table->comment('Examples for Russian words usage');
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique()->comment('URL-friendly identifier for the tag');
            $table->string('name', 256)->comment('Display name of the tag');
            $table->timestamps();
            $table->comment('Word tags (e.g. most used, science and so on)');
        });

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

        Schema::create('ru_word_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ru_word_id')->comment("The word's id. Foreign key.")
                ->references('id')->on('ru_words')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('tag_id')->comment("The tag's id. Foreign key.")
                ->references('id')->on('tags')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamps();
            $table->unique(['ru_word_id', 'tag_id'], 'uk_ru_word_tag');
            $table->comment('Tags for Russian words');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ru_word_tags');
        Schema::dropIfExists('en_word_tags');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('ru_examples');
        Schema::dropIfExists('ru_pronunciations');
        Schema::dropIfExists('en_examples');
        Schema::dropIfExists('en_pronunciations');
        Schema::dropIfExists('ru_etymologies');
        Schema::dropIfExists('ru_transcriptions');
        Schema::dropIfExists('ru_definitions');
        Schema::dropIfExists('ru_forms');
        Schema::dropIfExists('en_etymologies');
        Schema::dropIfExists('en_transcriptions');
        Schema::dropIfExists('en_definitions');
        Schema::dropIfExists('en_forms');
        Schema::dropIfExists('ru_words');
        Schema::dropIfExists('en_words');
    }
};
