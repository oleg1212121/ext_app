<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The user's persisted vocabulary level (cefr-word-test): their current
     * word-test result on the 0-20000 frequency-rank scale, written raise-only
     * on submit (ADR 0090) so a bad retake never lowers the stored level.
     * Unsigned smallint safely holds the whole 0-20000 range.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('vocabulary_level')->default(0)
                ->comment('Current word-test level on the 0-20000 frequency-rank scale; raise-only (ADR 0090).');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('vocabulary_level');
        });
    }
};
