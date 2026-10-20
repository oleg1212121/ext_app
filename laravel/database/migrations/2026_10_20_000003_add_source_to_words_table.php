<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The word-row import provenance (popup-provenance ticket 03): which
     * creator minted the row — 'kaikki' | 'golddict' | 'cmudict' | 'entity'
     * | 'ai'. Stamped at INSERT, first importer wins (never part of an
     * upsert's update list); each creating writer's per-batch write also
     * fills still-null rows it touches (the no-backfill backdoor), so rows
     * never touched again keep null — pre-column rows and hand-created
     * admin words included.
     */
    public function up(): void
    {
        Schema::table('words', function (Blueprint $table) {
            $table->string('source', 16)->nullable()->after('l_word')
                ->comment("Which creator minted the row: 'kaikki' | 'golddict' | 'cmudict' | 'entity' | 'ai'; null = pre-column rows and hand-created words.");
        });
    }

    public function down(): void
    {
        Schema::table('words', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
