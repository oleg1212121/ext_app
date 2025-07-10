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
        Schema::create('ru_sentences', function (Blueprint $table) {
            $table->id();
            $table->text('sentense', 1000);
            $table->foreignId('book_id')
            ->constrained('books', 'id', 'books_ru_sentence_id_foreign')
            ->onUpdate('cascade')
            ->onDelete('cascade');
            $table->unsignedBigInteger('order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ru_sentences', function (Blueprint $table) {
            $table->dropForeign('books_ru_sentence_id_foreign');
        });
        Schema::dropIfExists('ru_sentences');
    }
};
