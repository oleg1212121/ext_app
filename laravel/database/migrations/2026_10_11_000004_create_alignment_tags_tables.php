<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alignment_tags', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique()->comment('URL-friendly identifier for the tag');
            $table->string('name', 256)->comment('Display name of the tag');
            $table->timestamps();
            $table->comment('Alignment tags (e.g. hand-verified, machine-only and so on)');
        });

        Schema::create('tagged_alignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_match_id')->comment("The entity match's id. Foreign key.")
                ->constrained('entity_matches')
                ->cascadeOnDelete();
            $table->foreignId('alignment_tag_id')->comment("The alignment tag's id. Foreign key.")
                ->constrained('alignment_tags')
                ->cascadeOnDelete();
            $table->timestamps();
            $table->comment('Tags for entity matches (alignments)');

            $table->unique(['entity_match_id', 'alignment_tag_id'], 'uk_tagged_alignments_pair');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tagged_alignments');
        Schema::dropIfExists('alignment_tags');
    }
};
