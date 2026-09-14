<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('a_entity_id')->comment('The a-side entity. Foreign key.')
                ->constrained('entities')
                ->cascadeOnDelete();
            $table->foreignId('b_entity_id')->comment('The b-side entity. Foreign key.')
                ->constrained('entities')
                ->cascadeOnDelete();
            $table->string('status')->default('pending')->comment('pending|verifying|aligning|completed|failed');
            $table->decimal('entity_similarity', 5, 4)->nullable()->comment('Cosine similarity of entity signatures');
            $table->integer('a_total_sentences')->default(0)->comment('Total a-side sentences');
            $table->integer('b_total_sentences')->default(0)->comment('Total b-side sentences');
            $table->integer('linked_count')->default(0)->comment('Number of matched pairs created');
            $table->integer('a_last_sentence_offset')->default(0)->comment('Chunk-resume cursor, a side');
            $table->integer('b_last_sentence_offset')->default(0)->comment('Chunk-resume cursor, b side');
            $table->integer('chunk_size')->default(75)->comment('Sentences per chunk');
            $table->integer('max_n')->default(6)->comment('Max sentence span on either side of an alignment group');
            $table->text('error_message')->nullable()->comment('Error details if failed');
            $table->timestamp('started_at')->nullable()->comment('When processing started');
            $table->timestamp('completed_at')->nullable()->comment('When processing completed');
            $table->timestamps();
            $table->comment('A pair of related entities of one work (canonical order: a_entity_id < b_entity_id). The original side is derived from the work\'s original language.');

            $table->unique(['a_entity_id', 'b_entity_id']);
            $table->index(['b_entity_id', 'status'], 'entity_matches_b_entity_id_status_index');
        });

        Schema::create('meaning_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_match_id')->comment('The related (matched) entity pair. Foreign key.')
                ->constrained('entity_matches')
                ->cascadeOnDelete();
            $table->bigInteger('order')->comment('Sparse document order of the meaning match pairs.');
            $table->decimal('similarity', 5, 4)->comment('Meaning match similarity assessment');
            $table->integer('alignment_chunk')->default(0)->comment('Chunk index that produced this meaning match; -1 marks human edits');
            $table->timestamps();
            $table->comment('Relations between sentences of both sides (1:1 to n:m) with the same meaning.');

            $table->unique(['entity_match_id', 'order']);
            $table->index(['entity_match_id', 'alignment_chunk']);
        });

        Schema::create('sentence_meaning_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_sentence_id')->comment('The sentence. Foreign key.')
                ->constrained('entity_sentences')
                ->cascadeOnDelete();
            $table->foreignId('meaning_match_id')->comment('The meaning unit. Foreign key.')
                ->constrained('meaning_matches')
                ->cascadeOnDelete();
            $table->char('side', 1)->comment("Which entity_match side the sentence belongs to: 'a' or 'b'");
            $table->timestamps();
            $table->comment('Sentences linked to meaning units, with the match side they came from.');

            $table->index('entity_sentence_id', 'smm_entity_sentence_id_index');
            $table->index('meaning_match_id', 'smm_meaning_match_id_index');
            $table->unique(['entity_sentence_id', 'meaning_match_id', 'side'], 'smm_sentence_match_side_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sentence_meaning_matches');
        Schema::dropIfExists('meaning_matches');
        Schema::dropIfExists('entity_matches');
    }
};
