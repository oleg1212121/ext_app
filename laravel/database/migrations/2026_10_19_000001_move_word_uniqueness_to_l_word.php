<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Word uniqueness moves from the display word to the lookup key: the
     * cross-source twins (до́м/дом, ёлка/елка, March/march) are ONE row per
     * (l_word, language_id, word_class_id) — the first source's display
     * spelling stands and later sources attach to it. The old display-word
     * unique and the superseded non-unique (l_word, word_class_id) index go;
     * the new unique covers both. NULL l_word rows stay unconstrained (NULLs
     * never conflict in Postgres).
     *
     * Fails on a database that still holds same-key duplicates — run
     * `words:normalize`'s l_word merge phase first (the dev-DB runbook
     * sequences normalize → migrate); fresh databases are unaffected.
     */
    public function up(): void
    {
        Schema::table('words', function (Blueprint $table): void {
            $table->dropUnique('uk_words_word_language_class');
            $table->dropIndex('idx_words_l_word_class');
            $table->unique(['l_word', 'language_id', 'word_class_id'], 'uk_words_l_word_language_class');
        });
    }

    public function down(): void
    {
        Schema::table('words', function (Blueprint $table): void {
            $table->dropUnique('uk_words_l_word_language_class');
            $table->unique(['word', 'language_id', 'word_class_id'], 'uk_words_word_language_class');
            $table->index(['l_word', 'word_class_id'], 'idx_words_l_word_class');
        });
    }
};
