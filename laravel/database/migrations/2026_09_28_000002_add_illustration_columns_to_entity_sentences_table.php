<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entity_sentences', function (Blueprint $table) {
            // An illustration IS a sentence (ADR 0050): image_path non-null
            // marks it; content carries the optional caption. The aligner
            // works in image-less sentence space — the columns are queried
            // directly, so no sentence_types join is needed to find them.
            $table->string('image_path', 512)->nullable()->after('content');
            $table->string('image_hash', 64)->nullable()->after('image_path');
            $table->unsignedInteger('image_width')->nullable()->after('image_hash');
            $table->unsignedInteger('image_height')->nullable()->after('image_width');
            $table->string('image_mime', 64)->nullable()->after('image_height');

            // Alignment-copy reuse keys on the text hash; an illustration is
            // part of the text, so its bytes take part in the digest.
            $table->index('image_hash');
        });
    }

    public function down(): void
    {
        Schema::table('entity_sentences', function (Blueprint $table) {
            $table->dropIndex(['image_hash']);
            $table->dropColumn(['image_path', 'image_hash', 'image_width', 'image_height', 'image_mime']);
        });
    }
};
