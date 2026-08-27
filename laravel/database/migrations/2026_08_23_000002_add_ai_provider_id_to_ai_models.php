<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_models', function (Blueprint $table) {
            $table->unsignedBigInteger('ai_provider_id')->after('id');

            $table->foreign('ai_provider_id')
                ->references('id')
                ->on('ai_providers')
                ->cascadeOnDelete();

            $table->dropUnique(['provider', 'external_id']);
            $table->dropColumn('provider');

            $table->unique(['ai_provider_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_models', function (Blueprint $table) {
            $table->dropUnique(['ai_provider_id', 'external_id']);
            $table->dropForeign(['ai_provider_id']);
            $table->dropColumn('ai_provider_id');

            $table->string('provider', 50)->default('openrouter');
            $table->unique(['provider', 'external_id']);
        });
    }
};
