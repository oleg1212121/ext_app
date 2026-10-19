<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('word_ai_fetches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('word_id')
                ->comment('The dictionary word the ask-AI enrichment was requested for. Foreign key.')
                ->constrained('words')
                ->cascadeOnDelete();
            $table->string('provider', 32)->nullable()
                ->comment('The AI provider that served (or was about to serve) the ask (openrouter/gemini/...).');
            $table->string('model', 256)->nullable()
                ->comment("The AI model that served (or was about to serve) the ask — the model part of the 'provider:model' key.");
            $table->string('status', 16)->default('pending')
                ->comment('pending|succeeded|failed; succeeded is the one-ask-per-word lock, failed stays retryable.');
            $table->unsignedSmallInteger('attempts')->default(0)
                ->comment('Asks attempted so far; failed attempts stay retryable at any count (user-driven, throttled endpoint).');
            $table->string('error', 500)->nullable()
                ->comment('The failure message of the latest failed attempt; null on pending/succeeded.');
            $table->timestamp('asked_at')->nullable()
                ->comment('When the word was last asked for.');
            $table->timestamp('succeeded_at')->nullable()
                ->comment('When an ask succeeded and locked the word against further asks.');
            $table->timestamps();
            $table->comment('Ask-AI word enrichment ledger (popup-ai-enrich ticket 04); one row per word — a succeeded row is the lock, failed attempts stay retryable.');

            $table->unique('word_id', 'uk_word_ai_fetches_word');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('word_ai_fetches');
    }
};
