<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->string('status')
                ->default('completed')
                ->after('file_path')
                ->comment('processing|completed|failed — lifecycle of the upload pipeline (ADR 0044)');
            $table->index(['created_by', 'status']);
        });

        // A pipeline-less entity (no file) has nothing processing; anything
        // still mid-pipeline (file present, signature pending) keeps holding
        // a processing slot exactly as it visibly did before.
        DB::table('entities')
            ->whereNotNull('file_path')
            ->whereNull('signature')
            ->update(['status' => 'processing']);

        Schema::table('entity_matches', function (Blueprint $table) {
            $table->foreignId('created_by')
                ->nullable()
                ->after('b_entity_id')
                ->comment('The user who created this alignment; consumes their processing slot while pending/aligning.')
                ->constrained('users')
                ->nullOnDelete();
            $table->index(['created_by', 'status']);
        });

        // Backfill ownership from the a-side entity's uploader.
        DB::statement(<<<'SQL'
            UPDATE entity_matches
            SET created_by = entities.created_by
            FROM entities
            WHERE entities.id = entity_matches.a_entity_id
                AND entities.created_by IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('entity_matches', function (Blueprint $table) {
            $table->dropIndex(['created_by', 'status']);
            $table->dropConstrainedForeignId('created_by');
        });

        Schema::table('entities', function (Blueprint $table) {
            $table->dropIndex(['created_by', 'status']);
            $table->dropColumn('status');
        });
    }
};
