<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('en_ru_entity_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('en_entity_id')->comment('The EN entity id. Foreign key.')
                ->references('id')->on('en_entities')
                ->cascadeOnDelete();
            $table->foreignId('ru_entity_id')->comment('The RU entity id. Foreign key.')
                ->references('id')->on('ru_entities')
                ->cascadeOnDelete();
            $table->boolean('is_original_en')->default(true)->after('ru_entity_id');
            $table->string('status')->default('pending')->comment('pending|verifying|aligning|completed|failed');
            $table->decimal('entity_similarity', 5, 4)->nullable()->comment('Cosine similarity of entity signatures');
            $table->integer('en_total_sentences')->default(0)->comment('Total EN sentences');
            $table->integer('ru_total_sentences')->default(0)->comment('Total RU sentences');
            $table->integer('linked_count')->default(0)->comment('Number of matched pairs created');
            $table->integer('last_en_sentence_offset')->default(0)->after('linked_count');
            $table->integer('last_ru_sentence_offset')->default(0)->after('last_en_sentence_offset');
            $table->integer('chunk_size')->default(75)->comment('Sentences per chunk');
            $table->integer('max_n')->default(6)->comment('Max sentence span on either side of an alignment group');
            $table->text('error_message')->nullable()->comment('Error details if failed');
            $table->timestamp('started_at')->nullable()->comment('When processing started');
            $table->timestamp('completed_at')->nullable()->comment('When processing completed');
            $table->timestamps();
            $table->comment('A pair of related (matched) EN-RU entities.');

            $table->unique(['en_entity_id', 'ru_entity_id']);
        });

        Schema::create('en_ru_meaning_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('en_ru_entity_match_id')->comment('An id of the related (matched by meaning) en/ru entities. Foreign key.')
                ->references('id')->on('en_ru_entity_matches')
                ->cascadeOnDelete();
            $table->bigInteger('order')->comment('Order of the meaning match pairs.');
            $table->decimal('similarity', 5, 4)->comment('Meaning match similarity assessment');
            $table->integer('alignment_chunk')->default(0)->comment('Chunk index that produced this meaning match');
            $table->timestamps();
            $table->comment('Shows relations between ru/en sentences (can be 1 to 1 or several to several) with the same meaning.');

            $table->unique(['en_ru_entity_match_id', 'order']);
            $table->index(['en_ru_entity_match_id', 'alignment_chunk']);
        });

        Schema::create('en_sentence_meaning_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('en_entity_sentence_id')->comment('Link to en sentence. Foreign key.')
                ->references('id')->on('en_entity_sentences')
                ->cascadeOnDelete();
            $table->foreignId('en_ru_meaning_match_id')->comment('Link to a meaning unit. Foreign key.')
                ->references('id')->on('en_ru_meaning_matches')
                ->cascadeOnDelete();
            $table->bigInteger('order')->comment('Order of an en sentence within 1 separate meaning match unit.');
            $table->timestamps();
            $table->comment('Stores a list of en sentences that match a meaning unit.');
        });

        Schema::create('ru_sentence_meaning_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ru_entity_sentence_id')->comment('Link to ru sentence. Foreign key.')
                ->references('id')->on('ru_entity_sentences')
                ->cascadeOnDelete();
            $table->foreignId('en_ru_meaning_match_id')->comment('Link to a meaning unit. Foreign key.')
                ->references('id')->on('en_ru_meaning_matches')
                ->cascadeOnDelete();
            $table->bigInteger('order')->comment('Order of a ru sentence within 1 separate meaning match unit.');
            $table->timestamps();
            $table->comment('Stores a list of ru sentences that match a meaning unit.');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ru_sentence_meaning_matches');
        Schema::dropIfExists('en_sentence_meaning_matches');
        Schema::dropIfExists('en_ru_meaning_matches');
        Schema::dropIfExists('en_ru_entity_matches');
    }
};
