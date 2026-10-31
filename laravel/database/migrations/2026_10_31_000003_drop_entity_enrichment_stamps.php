<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The entity-level enrichment stamps (entities.enrichment_stamps,
     * ADR 0057) have no reader left: staleness is per-sentence since the
     * per-sentence staleness change (entity_sentences.enrichment_stamps —
     * see ADR 0094), and the sweep and the job read only sentences. The
     * column is dropped; its per-sentence successor stays.
     */
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->dropColumn('enrichment_stamps');
        });
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->jsonb('enrichment_stamps')
                ->nullable()
                ->after('frequency_counted_at')
                ->comment('Per-enricher completion stamps {enricher key: ISO timestamp}; null = never enriched, {} = no enricher applies (ADR 0057).');
        });
    }
};
