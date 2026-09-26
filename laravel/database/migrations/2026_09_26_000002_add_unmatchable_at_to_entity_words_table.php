<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entity_words', function (Blueprint $table) {
            $table->timestamp('unmatchable_at')
                ->nullable()
                ->after('word_id')
                ->comment('Set when no dictionary entry matched the token; the linker skips stamped rows until an import clears them.');
        });
    }

    public function down(): void
    {
        Schema::table('entity_words', function (Blueprint $table) {
            $table->dropColumn('unmatchable_at');
        });
    }
};
