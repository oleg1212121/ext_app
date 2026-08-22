<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50)->default('openrouter');
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

            $table->unique(['provider', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_models');
    }
};
