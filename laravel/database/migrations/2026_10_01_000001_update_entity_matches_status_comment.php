<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * ADR 0055: sentence mutations now flip existing matches to a
     * display-only 'stale' status instead of 'pending' (which is reserved
     * for fresh matches awaiting their first automatic run). The column is a
     * plain varchar, so only its self-documentation needs updating.
     */
    public function up(): void
    {
        DB::statement("COMMENT ON COLUMN entity_matches.status IS 'pending|stale|aligning|completed|failed'");
    }

    public function down(): void
    {
        DB::statement("COMMENT ON COLUMN entity_matches.status IS 'pending|verifying|aligning|completed|failed'");
    }
};
