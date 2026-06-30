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
        Schema::create('ru_words', function (Blueprint $table) {
            $table->id();
            $table->string('word', 256)->comment("The word's main form");
            $table->string('l_word', 256)->nullable()->comment("The word's lowercase form");
            $table->decimal('frequency')->default(0)->comment("The word's frequency. More the number - more the frequency");
            $table->foreignId('ru_word_class_id')->comment("The word's class id")
                ->references('id')->on('ru_word_classes')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->json('translations')->nullable()->after('ru_word_class_id')
                ->comment('Raw translation words from Wiktionary for later linking');
            $table->timestamps();
            $table->comment('Russian words (base form). Different parts of speech might be duplicated');
            $table->unique(['word', 'ru_word_class_id'], 'uk_ru_word_class_id');
            $table->index(['l_word', 'ru_word_class_id'], 'idx_ru_words_l_word_class');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ru_words', function (Blueprint $table) {
            $table->dropUnique('uk_ru_word_class_id');
            $table->dropIndex('idx_ru_words_l_word_class');
        });
        Schema::dropIfExists('ru_words');
    }
};
