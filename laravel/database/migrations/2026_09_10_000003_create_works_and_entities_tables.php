<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('works', function (Blueprint $table) {
            $table->id();
            $table->string('title', 512)->comment('Canonical display title, usually in the original language');
            $table->string('author', 512)->nullable()->comment('Work author');
            $table->string('description', 2048)->nullable()->comment('Work description');
            $table->foreignId('original_language_id')
                ->comment('The language the work was written in. Foreign key.')
                ->constrained('languages')
                ->restrictOnDelete();
            $table->timestamps();
            $table->comment('The abstract book that entities (per-language texts) translate.');
        });

        Schema::create('sentence_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique()->comment('Type name (e.g. definition, example, usage)');
            $table->string('description', 256)->nullable()->comment('Description of the sentence type');
            $table->timestamps();
            $table->comment('Types of sentences (definition, example, usage, etc.)');
        });

        Schema::create('entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_id')
                ->comment('The work this text belongs to. Foreign key.')
                ->constrained('works')
                ->cascadeOnDelete();
            $table->foreignId('language_id')
                ->comment('The language of this text. Foreign key.')
                ->constrained('languages')
                ->restrictOnDelete();
            $table->string('name', 512)->comment('Entity name');
            $table->string('label', 256)->nullable()->comment('Translator / edition note telling same-language entities of one work apart');
            $table->string('description', 2048)->nullable()->comment('Entity description');
            $table->text('signature')->nullable()->comment('Entity signature');
            $table->string('file_path', 512)->nullable()->comment('Path to associated file');
            $table->boolean('is_restricted')
                ->default(false)
                ->comment('When true, only admin and explicitly granted users may read this entity.');
            $table->timestamps();
            $table->comment('A text of a work in one language (the original or a translation)');

            $table->index('work_id');
            $table->index('language_id');
        });

        Schema::create('entity_sentences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->comment("The entity's id. Foreign key.")
                ->constrained('entities')
                ->cascadeOnDelete();
            $table->foreignId('sentence_type_id')
                ->nullable(true)
                ->comment("The sentence type's id. Foreign key.")
                ->constrained('sentence_types')
                ->nullOnDelete();
            $table->text('content')->comment('Sentence content');
            $table->bigInteger('order')->default(0)->comment('Sparse sort order (STRIDE 1024)');
            $table->timestamps();
            $table->comment('Sentences extracted from entity files.');

            $table->unique(['entity_id', 'order']);
        });

        Schema::create('entity_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')
                ->comment('The restricted entity. Foreign key.')
                ->constrained('entities')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->comment('The granted user. Foreign key.')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->decimal('similarity', 5, 4)->nullable()
                ->comment('Cosine similarity of the signature match that produced this grant; null for the original uploader.');
            $table->timestamps();
            $table->unique(['entity_id', 'user_id']);
            $table->comment('Per-user read grants to restricted entities.');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_user');
        Schema::dropIfExists('entity_sentences');
        Schema::dropIfExists('entities');
        Schema::dropIfExists('sentence_types');
        Schema::dropIfExists('works');
    }
};
