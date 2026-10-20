<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Usage/register labels on definitions (popup-ai-enrich ticket 09).
     * jsonb so ticket 10's completeness recalc SQL can mirror the demotion
     * check server-side. Going-forward only, per the user's 2026-10-20
     * decision: no backfill — every row written before this column existed
     * keeps null (= unlabelled), and demotion wakes per-word as labelled
     * data accumulates from the import and the ask-AI enrichment.
     */
    public function up(): void
    {
        Schema::table('definitions', function (Blueprint $table) {
            $table->jsonb('labels')->nullable()->after('definition')
                ->comment('Usage/register labels of the sense, closed set: obsolete, archaic, dated, colloquial, rare, dialectal (App\Support\UsageLabels). null = unlabelled; never backfilled — rows written before 2026-10-20 stay null.');
        });
    }

    public function down(): void
    {
        Schema::table('definitions', function (Blueprint $table) {
            $table->dropColumn('labels');
        });
    }
};
