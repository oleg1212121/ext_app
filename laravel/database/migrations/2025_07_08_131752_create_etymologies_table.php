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
        Schema::create('etymologies', function (Blueprint $table) {
            $table->id();
            $table->text('pos')->default('noun')->comment('Part of speech');
            $table->text('word')->comment('Word');
            $table->text('etymology')->comment('Etymology information');
            $table->comment('Word etymologies');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('etymologies');
    }
};
