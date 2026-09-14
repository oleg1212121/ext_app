<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->boolean('is_enabled')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_provider_id')->constrained('ai_providers')->cascadeOnDelete();
            $table->string('external_id');
            $table->string('canonical_slug')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->bigInteger('context_length')->nullable();
            $table->decimal('pricing_prompt', 20, 16)->nullable();
            $table->decimal('pricing_completion', 20, 16)->nullable();
            $table->json('reasoning')->nullable();
            $table->date('expiration_date')->nullable();
            $table->timestamp('api_created_at')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();

            $table->unique(['ai_provider_id', 'external_id']);
        });

        Schema::create('user_api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_provider_id')->constrained('ai_providers')->cascadeOnDelete();
            $table->text('api_key');
            $table->timestamps();

            $table->unique(['user_id', 'ai_provider_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_api_keys');
        Schema::dropIfExists('ai_models');
        Schema::dropIfExists('ai_providers');
    }
};
