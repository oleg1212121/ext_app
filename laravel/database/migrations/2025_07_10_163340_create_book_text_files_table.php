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
        Schema::create('book_text_files', function (Blueprint $table) {
            $table->id();
            $table->string('name', 256);
            $table->string('path', 1000);
            $table->foreignId('book_id')
            ->constrained('books','id','books_text_file_id_foreign')
            ->onDelete('cascade')
            ->onUpdate('cascade');
            $table->string('lang', 10)->default('en');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        
        Schema::table('book_text_files', function (Blueprint $table) {
            $table->dropForeign('books_text_file_id_foreign');
        });
        Schema::dropIfExists('book_text_files');
    }
};
