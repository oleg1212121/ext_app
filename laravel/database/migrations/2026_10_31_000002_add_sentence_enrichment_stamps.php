<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enrichment staleness moves from the entity to the sentence: each
     * sentence carries the per-enricher completion stamps of the run that
     * wrote its annotations, in the same {enricher key: {v, pv, at}} shape
     * as entities.enrichment_stamps (ADR 0057, ADR 0067). Null = never
     * enriched (stale); the column starts null everywhere, so the corpus
     * re-stales exactly once and the bounded five-minute sweep rebuilds the
     * stamps sentence by sentence (accelerate with
     * `entities:enrich --enricher=<key> --limit=N`).
     */
    public function up(): void
    {
        Schema::table('entity_sentences', function (Blueprint $table) {
            $table->jsonb('enrichment_stamps')
                ->nullable()
                ->after('phrasal_verbs')
                ->comment('Per-enricher completion stamps {enricher key: {v, pv, at}} for the run that wrote this sentence\'s annotations; null = never enriched (stale).');
        });
    }

    public function down(): void
    {
        Schema::table('entity_sentences', function (Blueprint $table) {
            $table->dropColumn('enrichment_stamps');
        });
    }
};
