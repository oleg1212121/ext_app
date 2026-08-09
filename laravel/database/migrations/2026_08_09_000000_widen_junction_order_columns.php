<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('en_sentence_meaning_matches', function (Blueprint $table) {
            $table->bigInteger('order')->comment('Order of an en sentence within 1 separate meaning match unit.')->change();
        });

        Schema::table('ru_sentence_meaning_matches', function (Blueprint $table) {
            $table->bigInteger('order')->comment('Order of a ru sentence within 1 separate meaning match unit.')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('en_sentence_meaning_matches', function (Blueprint $table) {
            $table->integer('order')->comment('Order of an en sentence within 1 separate meaning match unit.')->change();
        });

        Schema::table('ru_sentence_meaning_matches', function (Blueprint $table) {
            $table->integer('order')->comment('Order of a ru sentence within 1 separate meaning match unit.')->change();
        });
    }
};
