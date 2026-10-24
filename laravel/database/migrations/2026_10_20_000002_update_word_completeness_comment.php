<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Popup-ai-enrich ticket 10 (completeness v2, user decision 2026-10-20):
     * the score re-weights to 0–9 — translations 3; definitions 2 on a
     * major POS (noun/verb/adjective/adj/adverb/adv) else 1, 0 when every
     * definition carries a demoting label (obsolete/archaic — no
     * definitions is live, not demoted) — and the popup now sorts live
     * entries ahead of demoted ones. The column is a plain smallint, so
     * only its self-documentation needs re-pinning; the stored values are
     * corrected in place by a full words:recalc-completeness sweep.
     */
    public function up(): void
    {
        DB::statement("COMMENT ON COLUMN words.completeness IS 'How much is known about the word, 0-9: linked translations 3; definitions 2 when the word class is a major POS (noun/verb/adjective/adj/adverb/adv) else 1, and 0 when every definition carries a demoting label (obsolete/archaic — no definitions is live, not demoted); transcriptions/examples/etymologies 1 each; a real frequency rank (below the unranked sentinel) 1. Null until the first recalc. Drives the word popup''s entry ordering: live entries before demoted, then completeness desc.'");
    }

    public function down(): void
    {
        DB::statement("COMMENT ON COLUMN words.completeness IS 'How much is known about the word, 0-7: linked translations 2, definitions/transcriptions/examples/etymologies 1 each, a real frequency rank (below the unranked sentinel) 1. Null until the first recalc. Drives the word popup''s entry ordering.'");
    }
};
