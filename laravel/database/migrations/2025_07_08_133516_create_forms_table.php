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
        Schema::create('forms', function (Blueprint $table) {
            $table->id();
            $table->string('pos')->default('noun')->comment('Part of speech');
            $table->string('word')->comment('Base word');
            $table->string('lword')->nullable(true)->comment('Lowercase word');
            $table->integer('word_id')->nullable(true)->comment('Word ID reference');
            $table->string('form')->comment('Word form');
            $table->string('lform')->nullable(true)->comment('Lowercase form');
            $table->comment('Word forms (conjugations, declensions)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('forms');
    }
};
