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
        Schema::create('en_entities', function (Blueprint $table) {
            $table->id();
            $table->string('name', 512)->comment('Entity name');
            $table->string('description', 2048)->nullable()->comment('Entity description');
            $table->text('signature')->nullable()->comment('Entity signature');
            $table->string('file_path', 512)->nullable()->comment('Path to associated file');
            $table->timestamps();
            $table->comment('English language entities (articles, texts)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('en_entities');
    }
};
