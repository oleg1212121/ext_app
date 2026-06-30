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
        Schema::create('definitions', function (Blueprint $table) {
            $table->id();
            $table->text('pos')->default('noun')->comment('Part of speech');
            $table->text('word')->comment('Word');
            $table->text('lword')->nullable(true)->comment('Lowercase word');
            $table->integer('word_id')->nullable(true)->comment('Word ID reference');
            $table->text('definition')->comment('Definition text');
            $table->boolean('is_obsolete')->default(false)->comment('Whether definition is obsolete');
            $table->comment('Word definitions');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('definitions');
    }
};
