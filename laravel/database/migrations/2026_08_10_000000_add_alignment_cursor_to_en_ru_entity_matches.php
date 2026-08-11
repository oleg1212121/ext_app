<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('en_ru_entity_matches', function (Blueprint $table) {
            $table->integer('last_en_sentence_offset')->default(0)->after('linked_count');
            $table->integer('last_ru_sentence_offset')->default(0)->after('last_en_sentence_offset');
        });

        if (Schema::hasColumn('en_ru_entity_matches', 'dp_path')) {
            Schema::table('en_ru_entity_matches', function (Blueprint $table) {
                $table->dropColumn('dp_path');
            });
        }
    }

    public function down(): void
    {
        Schema::table('en_ru_entity_matches', function (Blueprint $table) {
            $table->json('dp_path')->nullable()->after('max_n');
            $table->dropColumn(['last_en_sentence_offset', 'last_ru_sentence_offset']);
        });
    }
};
