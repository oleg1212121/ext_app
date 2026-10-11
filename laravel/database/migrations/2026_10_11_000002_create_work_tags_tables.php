<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_tags', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique()->comment('URL-friendly identifier for the tag');
            $table->string('name', 256)->comment('Display name of the tag');
            $table->timestamps();
            $table->comment('Work tags (e.g. classics, short stories and so on)');
        });

        Schema::create('tagged_works', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_id')->comment("The work's id. Foreign key.")
                ->constrained('works')
                ->cascadeOnDelete();
            $table->foreignId('work_tag_id')->comment("The work tag's id. Foreign key.")
                ->constrained('work_tags')
                ->cascadeOnDelete();
            $table->timestamps();
            $table->comment('Tags for works');

            $table->unique(['work_id', 'work_tag_id'], 'uk_tagged_works_pair');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tagged_works');
        Schema::dropIfExists('work_tags');
    }
};
