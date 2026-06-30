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
        Schema::create('sentence_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique()->comment('Type name (e.g. definition, example, usage)');
            $table->string('description', 256)->nullable()->comment('Description of the sentence type');
            $table->timestamps();
            $table->comment('Types of sentences (definition, example, usage, etc.)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sentence_types');
    }
};
