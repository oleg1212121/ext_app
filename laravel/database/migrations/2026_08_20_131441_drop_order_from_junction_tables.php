<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('en_sentence_meaning_matches', function (Blueprint $table) {
            $table->dropColumn('order');
        });

        Schema::table('ru_sentence_meaning_matches', function (Blueprint $table) {
            $table->dropColumn('order');
        });
    }

    public function down(): void
    {
        Schema::table('en_sentence_meaning_matches', function (Blueprint $table) {
            $table->bigInteger('order')->comment('Order of an en sentence within 1 separate meaning match unit.');
        });

        Schema::table('ru_sentence_meaning_matches', function (Blueprint $table) {
            $table->bigInteger('order')->comment('Order of a ru sentence within 1 separate meaning match unit.');
        });
    }
};
