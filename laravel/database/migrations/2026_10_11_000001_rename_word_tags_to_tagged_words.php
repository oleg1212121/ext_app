<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('word_tags', 'tagged_words');

        Schema::table('tagged_words', function (Blueprint $table) {
            $table->renameIndex('uk_word_tags_pair', 'uk_tagged_words_pair');
        });
    }

    public function down(): void
    {
        Schema::table('tagged_words', function (Blueprint $table) {
            $table->renameIndex('uk_tagged_words_pair', 'uk_word_tags_pair');
        });

        Schema::rename('tagged_words', 'word_tags');
    }
};
