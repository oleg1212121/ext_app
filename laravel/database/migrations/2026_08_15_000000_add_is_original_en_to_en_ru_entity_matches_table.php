<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('en_ru_entity_matches', function (Blueprint $table) {
            $table->boolean('is_original_en')->default(true)->after('ru_entity_id');
        });
    }

    public function down(): void
    {
        Schema::table('en_ru_entity_matches', function (Blueprint $table) {
            $table->dropColumn('is_original_en');
        });
    }
};
