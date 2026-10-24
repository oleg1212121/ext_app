<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('words', function (Blueprint $table) {
            $table->unsignedTinyInteger('completeness')->nullable()
                ->after('translations')
                ->comment('How much is known about the word, 0-7: linked translations 2, definitions/transcriptions/examples/etymologies 1 each, a real frequency rank (below the unranked sentinel) 1. Null until the first recalc. Drives the word popup\'s entry ordering.');
            $table->timestamp('completeness_at')->nullable()
                ->after('completeness')
                ->comment('When the completeness score was last (re)computed. Older than the word row or any content child (definitions/transcriptions/examples/etymologies/forms) => stale, recalculated lazily on the next popup open or words:recalc-completeness run.');
        });
    }

    public function down(): void
    {
        Schema::table('words', function (Blueprint $table) {
            $table->dropColumn(['completeness', 'completeness_at']);
        });
    }
};
