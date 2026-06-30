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
        Schema::create('saved_phrases', function (Blueprint $table) {
            $table->id();
            $table->text('phrase')->nullable(false)->comment('Saved phrase text');
            $table->timestamps();
            $table->comment('User saved phrases');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('saved_phrases');
    }
};
