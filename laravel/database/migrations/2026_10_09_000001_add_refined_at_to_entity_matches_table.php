<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entity_matches', function (Blueprint $table) {
            $table->timestamp('refined_at')->nullable()->after('completed_at')
                ->comment('When the refine round (DP + joined re-alignment of one-sided regions) last ran; null means never — the auto-refine after round 1 fires only while this is null');
        });
    }

    public function down(): void
    {
        Schema::table('entity_matches', function (Blueprint $table) {
            $table->dropColumn('refined_at');
        });
    }
};
