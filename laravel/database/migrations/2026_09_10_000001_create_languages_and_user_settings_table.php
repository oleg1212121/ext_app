<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->comment('ISO 639-1 two-letter language code, e.g. en, ru');
            $table->string('name')->comment('Display name in the application UI language, e.g. English');
            $table->string('native_name')->nullable()->comment('Endonym, e.g. Русский');
            $table->boolean('is_enabled')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->comment('Language catalog; adding a language is a row insert, not DDL');
        });

        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('native_language_id')->nullable()->constrained('languages')->nullOnDelete();
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_settings');
        Schema::dropIfExists('languages');
    }
};
