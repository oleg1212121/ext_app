<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The original add_ai_provider_id_to_ai_models migration already ran on
        // existing databases while the column was still nullable. This migration
        // backfills the NOT NULL constraint (and the cascade delete behaviour)
        // onto those already-migrated schemas. On a fresh install the column is
        // already NOT NULL, so the raw ALTER is a no-op.
        Schema::table('ai_models', function (Blueprint $table) {
            $table->dropForeign(['ai_provider_id']);
        });

        DB::statement('ALTER TABLE ai_models ALTER COLUMN ai_provider_id SET NOT NULL');

        Schema::table('ai_models', function (Blueprint $table) {
            $table->foreign('ai_provider_id')
                ->references('id')
                ->on('ai_providers')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_models', function (Blueprint $table) {
            $table->dropForeign(['ai_provider_id']);
        });

        DB::statement('ALTER TABLE ai_models ALTER COLUMN ai_provider_id DROP NOT NULL');

        Schema::table('ai_models', function (Blueprint $table) {
            $table->foreign('ai_provider_id')
                ->references('id')
                ->on('ai_providers')
                ->nullOnDelete();
        });
    }
};
