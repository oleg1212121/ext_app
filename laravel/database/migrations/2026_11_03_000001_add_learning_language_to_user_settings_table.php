<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The user's account-level learning language — the one language they study.
// Distinct from the native language (what they speak) and from the per-text
// learning side (ADR 0037, which a match's two languages determine). The
// column is nullable by design: the always-set guarantee lives in the
// app-side resolution (stored value, else English when enabled, else the
// first enabled language), not a schema NOT NULL — a fresh install migrates
// before any language row exists to point at.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->foreignId('learning_language_id')->nullable()->constrained('languages')->nullOnDelete();
        });

        // Every existing settings row predates the preference, and English is
        // what those users study today (the seeded learning default) — backfill
        // the stored value so the resolution chain only serves users with no
        // settings row at all. No-op on a fresh install (no languages yet).
        $englishId = DB::table('languages')->where('code', 'en')->value('id');

        if ($englishId !== null) {
            DB::table('user_settings')
                ->whereNull('learning_language_id')
                ->update(['learning_language_id' => $englishId]);
        }
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('learning_language_id');
        });
    }
};
