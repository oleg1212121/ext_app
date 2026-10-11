<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_tags', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique()->comment('URL-friendly identifier for the tag');
            $table->string('name', 256)->comment('Display name of the tag');
            $table->timestamps();
            $table->comment('Entity tags (e.g. bilingual, adapted and so on)');
        });

        Schema::create('tagged_entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->comment("The entity's id. Foreign key.")
                ->constrained('entities')
                ->cascadeOnDelete();
            $table->foreignId('entity_tag_id')->comment("The entity tag's id. Foreign key.")
                ->constrained('entity_tags')
                ->cascadeOnDelete();
            $table->timestamps();
            $table->comment('Tags for entities');

            $table->unique(['entity_id', 'entity_tag_id'], 'uk_tagged_entities_pair');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tagged_entities');
        Schema::dropIfExists('entity_tags');
    }
};
