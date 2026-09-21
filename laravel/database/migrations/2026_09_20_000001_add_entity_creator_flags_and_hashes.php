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
            $table->foreignId('created_by')
                ->nullable()
                ->after('language_id')
                ->comment('The user who uploaded this entity; null for system/admin imports.')
                ->constrained('users')
                ->nullOnDelete();
            $table->boolean('is_approved')
                ->default(false)
                ->after('is_restricted')
                ->comment('When true, the entity and its alignments are edit-locked; only the approval flag stays changeable (creator/admin).');
            $table->string('file_hash', 64)
                ->nullable()
                ->after('file_path')
                ->comment('sha256 of the uploaded file bytes; fast exact-copy detection at upload.');
            $table->string('text_hash', 64)
                ->nullable()
                ->after('file_hash')
                ->comment('sha256 of whitespace-normalized sentence contents in order; exact-copy key for alignment reuse.');
            $table->timestamp('text_hashed_at')
                ->nullable()
                ->after('text_hash')
                ->comment('When text_hash was last computed.');
            $table->timestamp('sentences_updated_at')
                ->nullable()
                ->after('text_hashed_at')
                ->comment('Bumped by every sentence mutation; text_hashed_at older than this means the hash is stale.');

            $table->index('file_hash');
            $table->index('text_hash');
        });

        // Mark every existing entity stale so the refresh scheduler backfills
        // text hashes for them on its first runs.
        DB::table('entities')->update(['sentences_updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropIndex(['file_hash']);
            $table->dropIndex(['text_hash']);
            $table->dropColumn([
                'is_approved',
                'file_hash',
                'text_hash',
                'text_hashed_at',
                'sentences_updated_at',
            ]);
        });
    }
};
