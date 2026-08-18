<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sentence_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique()->comment('Type name (e.g. definition, example, usage)');
            $table->string('description', 256)->nullable()->comment('Description of the sentence type');
            $table->timestamps();
            $table->comment('Types of sentences (definition, example, usage, etc.)');
        });

        Schema::create('en_entities', function (Blueprint $table) {
            $table->id();
            $table->string('name', 512)->comment('Entity name');
            $table->string('description', 2048)->nullable()->comment('Entity description');
            $table->text('signature')->nullable()->comment('Entity signature');
            $table->string('file_path', 512)->nullable()->comment('Path to associated file');
            $table->timestamps();
            $table->comment('English language entities (articles, texts)');
        });

        Schema::create('ru_entities', function (Blueprint $table) {
            $table->id();
            $table->string('name', 512)->comment('Entity name');
            $table->string('description', 2048)->nullable()->comment('Entity description');
            $table->text('signature')->nullable()->comment('Entity signature');
            $table->string('file_path', 512)->nullable()->comment('Path to associated file');
            $table->timestamps();
            $table->comment('Russian language entities (articles, texts)');
        });

        Schema::create('en_entity_sentences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('en_entity_id')->comment("The entity's id. Foreign key.")
                ->references('id')->on('en_entities')
                ->cascadeOnDelete();
            $table->foreignId('sentence_type_id')
                ->nullable(true)
                ->comment("The sentence type's id. Foreign key.")
                ->references('id')->on('sentence_types')
                ->nullOnDelete();
            $table->text('content')->comment('Sentence content');
            $table->bigInteger('order')->default(0)->comment('Sort order');
            $table->timestamps();
            $table->comment('Sentences extracted from English entities files.');
            $table->index(['en_entity_id', 'order']);
        });

        Schema::create('ru_entity_sentences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ru_entity_id')->comment("The entity's id. Foreign key.")
                ->references('id')->on('ru_entities')
                ->cascadeOnDelete();
            $table->foreignId('sentence_type_id')
                ->nullable(true)
                ->comment("The sentence type's id. Foreign key.")
                ->references('id')->on('sentence_types')
                ->nullOnDelete();
            $table->text('content')->comment('Sentence content');
            $table->bigInteger('order')->default(0)->comment('Sort order');
            $table->timestamps();
            $table->comment('Sentences extracted from Russian entities');
            $table->index(['ru_entity_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ru_entity_sentences');
        Schema::dropIfExists('en_entity_sentences');
        Schema::dropIfExists('ru_entities');
        Schema::dropIfExists('en_entities');
        Schema::dropIfExists('sentence_types');
    }
};
