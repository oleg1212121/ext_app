<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('word_translation_fetches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('word_id')
                ->comment('The dictionary word whose translations were requested. Foreign key.')
                ->constrained('words')
                ->cascadeOnDelete();
            $table->foreignId('target_language_id')
                ->comment('The language translations are fetched into. Foreign key.')
                ->constrained('languages')
                ->cascadeOnDelete();
            $table->string('provider', 32)->nullable()->comment('The provider that produced the outcome (yandex/google).');
            $table->string('status', 16)->default('pending')
                ->comment('pending|succeeded|empty|failed; empty = every provider answered "no translation" (the exclusion list — never re-checked).');
            $table->unsignedSmallInteger('attempts')->default(0)->comment('Attempts so far; failed lookups stop retrying at the model MAX_ATTEMPTS cap.');
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamps();
            $table->comment('Fetch ledger for auto-fetched word translations; doubles as the exclusion list via status=empty.');

            $table->unique(['word_id', 'target_language_id'], 'uk_word_translation_fetches_pair');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('word_translation_fetches');
    }
};
