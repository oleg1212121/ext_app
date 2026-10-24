<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every child table below carries a word_id FK (ON DELETE CASCADE) whose
     * existing indexes bury word_id behind a leading text column (or a user_id
     * prefix). Per-word joins, recount batches and cascade deletes therefore
     * seq-scanned — the root cause of the 17-minute wipe crawl on the
     * 3M-row forms table. This mirrors idx_definitions_word_id (2026_09_16).
     */
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table): void {
            $table->index('word_id', 'idx_forms_word_id');
        });

        Schema::table('transcriptions', function (Blueprint $table): void {
            $table->index('word_id', 'idx_transcriptions_word_id');
        });

        Schema::table('examples', function (Blueprint $table): void {
            $table->index('word_id', 'idx_examples_word_id');
        });

        Schema::table('pronunciations', function (Blueprint $table): void {
            $table->index('word_id', 'idx_pronunciations_word_id');
        });

        Schema::table('user_word', function (Blueprint $table): void {
            $table->index('word_id', 'idx_user_word_word_id');
        });

        Schema::table('user_word_event', function (Blueprint $table): void {
            $table->index('word_id', 'idx_user_word_event_word_id');
        });
    }

    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table): void {
            $table->dropIndex('idx_forms_word_id');
        });

        Schema::table('transcriptions', function (Blueprint $table): void {
            $table->dropIndex('idx_transcriptions_word_id');
        });

        Schema::table('examples', function (Blueprint $table): void {
            $table->dropIndex('idx_examples_word_id');
        });

        Schema::table('pronunciations', function (Blueprint $table): void {
            $table->dropIndex('idx_pronunciations_word_id');
        });

        Schema::table('user_word', function (Blueprint $table): void {
            $table->dropIndex('idx_user_word_word_id');
        });

        Schema::table('user_word_event', function (Blueprint $table): void {
            $table->dropIndex('idx_user_word_event_word_id');
        });
    }
};
