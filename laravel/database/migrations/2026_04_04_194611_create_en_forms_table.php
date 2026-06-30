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
        Schema::create('en_forms', function (Blueprint $table) {
            $table->id();
            $table->string('form', 256)->comment('The form');
            $table->string('l_word', 256)->nullable()
                ->comment("The form's lowercase form");
            $table->foreignId('en_word_id')->comment("The word's id")
                ->references('id')->on('en_words')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamps();
            $table->unique(['form', 'en_word_id'], 'uk_en_form_word_id');
            $table->comment('Forms of English words');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {

        Schema::table('en_forms', function (Blueprint $table) {
            $table->dropUnique('uk_en_form_word_id');
        });
        Schema::dropIfExists('en_forms');
    }
};
