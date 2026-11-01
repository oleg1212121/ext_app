<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_sentence_words', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')
                ->comment("The entity's id, denormalized from the sentence so the entity-level aggregate is a single-table indexed scan. Foreign key.")
                ->constrained('entities')
                ->cascadeOnDelete();
            $table->foreignId('entity_sentence_id')
                ->comment('The sentence this occurrence belongs to. Foreign key.')
                ->constrained('entity_sentences')
                ->cascadeOnDelete();
            $table->unsignedInteger('position')
                ->comment("0-based ordinal of the token occurrence among the sentence's kept tokens");
            $table->unsignedInteger('start')
                ->comment('0-based character offset of the token surface in the sentence content, inclusive');
            $table->unsignedInteger('end')
                ->comment('0-based character offset where the token surface ends in the sentence content, exclusive');
            $table->string('l_word', 256)
                ->comment("The token's dictionary lookup key, same form as entity_words.l_word");
            $table->string('token', 256)
                ->comment('The surface form of this occurrence in the sentence content');
            $table->comment('Per-sentence token occurrences with ordinals and char spans; the entity word list derives from these by aggregation.');

            $table->unique(['entity_sentence_id', 'position'], 'uk_entity_sentence_words_sentence_position');
            $table->index('entity_id', 'idx_entity_sentence_words_entity_id');
        });

        Schema::table('entity_sentences', function (Blueprint $table) {
            $table->timestamp('words_indexed_at')
                ->nullable()
                ->after('content')
                ->comment('When the sentence word rows were last built; null means never. Written with the sentence own updated_at (clock-skew safe).');
        });
    }

    public function down(): void
    {
        Schema::table('entity_sentences', function (Blueprint $table) {
            $table->dropColumn('words_indexed_at');
        });

        Schema::dropIfExists('entity_sentence_words');
    }
};
