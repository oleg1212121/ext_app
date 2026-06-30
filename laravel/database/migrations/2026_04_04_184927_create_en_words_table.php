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
        Schema::create('en_words', function (Blueprint $table) {
            $table->id();
            $table->string('word', 256)->comment("The word's main form");
            $table->string('l_word', 256)->nullable()->comment("The word's lowercase form");
            $table->decimal('frequency')->default(0)->comment("The word's frequency. More the number - more the frequency");
            $table->foreignId('en_word_class_id')->comment("The word's class id")
                ->references('id')->on('en_word_classes')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->json('translations')->nullable()->after('en_word_class_id')
                ->comment('Raw translation words from Wiktionary for later linking');

            $table->timestamps();
            $table->comment('English words (base form). Different parts of speech might be duplicated');
            $table->index(['l_word', 'en_word_class_id'], 'idx_en_words_l_word_class');
            $table->unique(['word', 'en_word_class_id'], 'uk_en_word_class_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('en_words', function (Blueprint $table) {
            $table->dropIndex('idx_en_words_l_word_class');
            $table->dropUnique('uk_en_word_class_id');
        });
        Schema::dropIfExists('en_words');
    }
};
