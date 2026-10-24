<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('word_translation_fetches', function (Blueprint $table) {
            $table->unsignedSmallInteger('linked_count')->default(0)
                ->comment('Candidates the link-only fetch converted into word_translations pairs.')
                ->after('status');
            $table->unsignedSmallInteger('staged_count')->default(0)
                ->comment('Candidates staged into the source word\'s translations JSON for the later link pass.')
                ->after('linked_count');
        });
    }

    public function down(): void
    {
        Schema::table('word_translation_fetches', function (Blueprint $table) {
            $table->dropColumn(['linked_count', 'staged_count']);
        });
    }
};
