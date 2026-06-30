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
        Schema::table('en_entity_sentences', function (Blueprint $table) {
            $table->bigInteger('order')->default(0)->comment('Sort order')->change();
        });

        Schema::table('ru_entity_sentences', function (Blueprint $table) {
            $table->bigInteger('order')->default(0)->comment('Sort order')->change();
        });

        Schema::table('en_ru_meaning_matches', function (Blueprint $table) {
            $table->bigInteger('order')->comment('Order of the meaning match pairs.')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('en_entity_sentences', function (Blueprint $table) {
            $table->integer('order')->default(0)->comment('Sort order')->change();
        });

        Schema::table('ru_entity_sentences', function (Blueprint $table) {
            $table->integer('order')->default(0)->comment('Sort order')->change();
        });

        Schema::table('en_ru_meaning_matches', function (Blueprint $table) {
            $table->integer('order')->comment('Order of the meaning match pairs.')->change();
        });
    }
};
