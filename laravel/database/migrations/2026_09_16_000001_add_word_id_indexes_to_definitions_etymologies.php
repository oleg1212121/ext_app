<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('definitions', function (Blueprint $table): void {
            // The importer's insertNewOnly() resolves existing rows per batch
            // via whereIn('word_id', ...); without an index this degrades to a
            // full scan per batch once the table holds millions of rows.
            $table->index('word_id', 'idx_definitions_word_id');
        });

        Schema::table('etymologies', function (Blueprint $table): void {
            $table->index('word_id', 'idx_etymologies_word_id');
        });
    }

    public function down(): void
    {
        Schema::table('definitions', function (Blueprint $table): void {
            $table->dropIndex('idx_definitions_word_id');
        });

        Schema::table('etymologies', function (Blueprint $table): void {
            $table->dropIndex('idx_etymologies_word_id');
        });
    }
};
