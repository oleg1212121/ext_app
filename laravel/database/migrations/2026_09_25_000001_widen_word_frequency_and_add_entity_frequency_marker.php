<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The old numeric(8,2) capped at 999,999.99 — below the unranked
        // marker — and had no room for the fractional correction steps.
        Schema::table('words', function (Blueprint $table) {
            $table->decimal('frequency', 12, 2)
                ->default(1100000)
                ->comment('Frequency rank: lower = more common; 1100000 = unranked. Corrected toward entity word positions.')
                ->change();
        });

        // The old default 0 also meant "unranked"; ranks start at 1, so no
        // collision with the new marker.
        DB::table('words')->where('frequency', 0)->update(['frequency' => 1100000]);

        Schema::table('entities', function (Blueprint $table) {
            $table->timestamp('frequency_counted_at')
                ->nullable()
                ->after('words_indexed_at')
                ->comment('When the entity last corrected word frequencies; null means still pending.');
        });
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->dropColumn('frequency_counted_at');
        });

        // Unranked and the heaviest ranks revert to the old 0 = unranked
        // marker so the narrowed column cannot overflow.
        DB::table('words')->where('frequency', '>=', 999999)->update(['frequency' => 0]);

        Schema::table('words', function (Blueprint $table) {
            $table->decimal('frequency')
                ->default(0)
                ->comment("The word's frequency. More the number - more the frequency")
                ->change();
        });
    }
};
