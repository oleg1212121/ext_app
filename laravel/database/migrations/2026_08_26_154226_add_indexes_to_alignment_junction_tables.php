<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add missing indexes to alignment-domain junction tables.
     *
     * PostgreSQL does not auto-index foreign-key columns. The two
     * *_sentence_meaning_matches pivots had zero indexes despite being
     * eager-loaded on every reader/editor/simulator request, and
     * en_ru_entity_matches.ru_entity_id had no usable index (the existing
     * unique leads on en_entity_id).
     */
    public function up(): void
    {
        Schema::table('en_sentence_meaning_matches', function (Blueprint $table) {
            $table->index('en_ru_meaning_match_id', 'esmm_meaning_match_id_index');
            $table->index('en_entity_sentence_id', 'esmm_entity_sentence_id_index');
        });

        Schema::table('ru_sentence_meaning_matches', function (Blueprint $table) {
            $table->index('en_ru_meaning_match_id', 'rsmm_meaning_match_id_index');
            $table->index('ru_entity_sentence_id', 'rsmm_entity_sentence_id_index');
        });

        Schema::table('en_ru_entity_matches', function (Blueprint $table) {
            $table->index(['ru_entity_id', 'status'], 'erem_ru_entity_id_status_index');
        });

        Schema::table('book_word', function (Blueprint $table) {
            $table->index(['book_id', 'is_solved'], 'book_word_book_id_is_solved_index');
        });
    }

    public function down(): void
    {
        Schema::table('en_sentence_meaning_matches', function (Blueprint $table) {
            $table->dropIndex('esmm_meaning_match_id_index');
            $table->dropIndex('esmm_entity_sentence_id_index');
        });

        Schema::table('ru_sentence_meaning_matches', function (Blueprint $table) {
            $table->dropIndex('rsmm_meaning_match_id_index');
            $table->dropIndex('rsmm_entity_sentence_id_index');
        });

        Schema::table('en_ru_entity_matches', function (Blueprint $table) {
            $table->dropIndex('erem_ru_entity_id_status_index');
        });

        Schema::table('book_word', function (Blueprint $table) {
            $table->dropIndex('book_word_book_id_is_solved_index');
        });
    }
};
