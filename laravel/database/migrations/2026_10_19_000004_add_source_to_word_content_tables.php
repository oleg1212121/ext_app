<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ask-AI enrichment's per-row provenance (popup-ai-enrich ticket 04):
     * null = imported by a dictionary import or provider fetch, 'ai' = the
     * row was written by POST /ai/word-enrich. DB-only — the popup renders no
     * visual marker.
     */
    public function up(): void
    {
        Schema::table('definitions', function (Blueprint $table) {
            $table->string('source', 16)->nullable()->after('definition')
                ->comment("Where the row came from: null = imported, 'ai' = the word popup's ask-AI enrichment.");
        });

        Schema::table('transcriptions', function (Blueprint $table) {
            $table->string('source', 16)->nullable()->after('transcription')
                ->comment("Where the row came from: null = imported, 'ai' = the word popup's ask-AI enrichment.");
        });

        Schema::table('examples', function (Blueprint $table) {
            $table->string('source', 16)->nullable()->after('example')
                ->comment("Where the row came from: null = imported, 'ai' = the word popup's ask-AI enrichment.");
        });

        Schema::table('etymologies', function (Blueprint $table) {
            $table->string('source', 16)->nullable()->after('etymology')
                ->comment("Where the row came from: null = imported, 'ai' = the word popup's ask-AI enrichment.");
        });
    }

    public function down(): void
    {
        Schema::table('definitions', function (Blueprint $table) {
            $table->dropColumn('source');
        });

        Schema::table('transcriptions', function (Blueprint $table) {
            $table->dropColumn('source');
        });

        Schema::table('examples', function (Blueprint $table) {
            $table->dropColumn('source');
        });

        Schema::table('etymologies', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
