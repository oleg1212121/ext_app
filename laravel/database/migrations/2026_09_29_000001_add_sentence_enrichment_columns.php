<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entity_sentences', function (Blueprint $table) {
            // Enrichment results live BESIDE content, never inside it: mutating
            // content would bump sentences_updated_at, change the text hash and
            // flip entity matches back to pending (ADR 0052).
            $table->text('stressed_content')
                ->nullable()
                ->after('content')
                ->comment('Stress-marked display variant (U+0301, ru also е→ё); spans in phrasal_verbs/intonation index content, not this column.');
            $table->jsonb('phrasal_verbs')
                ->nullable()
                ->after('stressed_content')
                ->comment('English phrasal-verb hits: [{verb, particles[], start, end}] with char spans into content.');
            $table->jsonb('intonation')
                ->nullable()
                ->after('phrasal_verbs')
                ->comment('Heuristic intonation: {nuclear: {start, end}|null, terminal: rise|fall}; spans into content.');
        });

        Schema::table('entities', function (Blueprint $table) {
            $table->timestamp('enriched_at')
                ->nullable()
                ->after('frequency_counted_at')
                ->comment('Sentence enrichment finished at; null or older than sentences_updated_at means stale (words_indexed_at pattern).');
        });
    }

    public function down(): void
    {
        Schema::table('entity_sentences', function (Blueprint $table) {
            $table->dropColumn(['stressed_content', 'phrasal_verbs', 'intonation']);
        });

        Schema::table('entities', function (Blueprint $table) {
            $table->dropColumn('enriched_at');
        });
    }
};
