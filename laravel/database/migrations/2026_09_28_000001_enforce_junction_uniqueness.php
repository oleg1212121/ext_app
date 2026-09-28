<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Strict junction uniqueness (ADR 0048): one sentence is junctioned into
     * at most one meaning match per entity match. Enforced at the DB level by
     * a denormalized entity_match_id on the junction plus a unique index —
     * the junction table's own key cannot express it (a sentence may be
     * junctioned once per match across several matches of the same entity).
     *
     * Pre-existing duplicates (re-fed windows, landmark overlaps, editor
     * saves) are resolved inline before the index is created: per (match,
     * sentence) the junction of the landmark/human row wins, then the higher
     * similarity, the earlier order, the lower id.
     */
    public function up(): void
    {
        Schema::table('sentence_meaning_matches', function (Blueprint $table) {
            $table->unsignedBigInteger('entity_match_id')->nullable()->after('meaning_match_id')
                ->comment('Denormalized meaning_matches.entity_match_id — backs the one-junction-per-sentence-per-match unique index');
        });

        DB::statement('
            UPDATE sentence_meaning_matches AS smm
            SET entity_match_id = mm.entity_match_id
            FROM meaning_matches AS mm
            WHERE mm.id = smm.meaning_match_id
        ');

        DB::statement('
            DELETE FROM sentence_meaning_matches
            WHERE id IN (
                SELECT junction_id FROM (
                    SELECT smm.id AS junction_id,
                           ROW_NUMBER() OVER (
                               PARTITION BY smm.entity_match_id, smm.entity_sentence_id
                               ORDER BY ((mm.alignment_chunk = -1) OR (mm.similarity >= 0.9)) DESC,
                                        mm.similarity DESC,
                                        mm."order" ASC,
                                        smm.id ASC
                           ) AS rn
                    FROM sentence_meaning_matches AS smm
                    JOIN meaning_matches AS mm ON mm.id = smm.meaning_match_id
                ) AS ranked
                WHERE ranked.rn > 1
            )
        ');

        DB::statement('
            DELETE FROM meaning_matches AS mm
            WHERE NOT EXISTS (
                SELECT 1 FROM sentence_meaning_matches AS smm
                WHERE smm.meaning_match_id = mm.id
            )
        ');

        Schema::table('sentence_meaning_matches', function (Blueprint $table) {
            $table->unsignedBigInteger('entity_match_id')->nullable(false)->change();
            $table->foreign('entity_match_id')
                ->references('id')->on('entity_matches')
                ->cascadeOnDelete();
            $table->unique(['entity_match_id', 'entity_sentence_id'], 'smm_match_sentence_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sentence_meaning_matches', function (Blueprint $table) {
            $table->dropUnique('smm_match_sentence_unique');
            $table->dropForeign(['entity_match_id']);
            $table->dropColumn('entity_match_id');
        });
    }
};
