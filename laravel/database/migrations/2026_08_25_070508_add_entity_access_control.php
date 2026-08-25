<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('en_entities', function (Blueprint $table) {
            $table->boolean('is_restricted')
                ->default(false)
                ->comment('When true, only admin and explicitly granted users may read this entity.');
        });

        Schema::table('ru_entities', function (Blueprint $table) {
            $table->boolean('is_restricted')
                ->default(false)
                ->comment('When true, only admin and explicitly granted users may read this entity.');
        });

        Schema::create('en_entity_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('en_entity_id')
                ->comment('The restricted entity. Foreign key.')
                ->references('id')->on('en_entities')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->comment('The granted user. Foreign key.')
                ->references('id')->on('users')
                ->cascadeOnDelete();
            $table->decimal('similarity', 5, 4)->nullable()
                ->comment('Cosine similarity of the signature match that produced this grant; null for the original uploader.');
            $table->timestamps();
            $table->unique(['en_entity_id', 'user_id']);
            $table->comment('Per-user read grants to restricted English entities.');
        });

        Schema::create('ru_entity_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ru_entity_id')
                ->comment('The restricted entity. Foreign key.')
                ->references('id')->on('ru_entities')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->comment('The granted user. Foreign key.')
                ->references('id')->on('users')
                ->cascadeOnDelete();
            $table->decimal('similarity', 5, 4)->nullable()
                ->comment('Cosine similarity of the signature match that produced this grant; null for the original uploader.');
            $table->timestamps();
            $table->unique(['ru_entity_id', 'user_id']);
            $table->comment('Per-user read grants to restricted Russian entities.');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ru_entity_user');
        Schema::dropIfExists('en_entity_user');
        Schema::table('ru_entities', fn (Blueprint $table) => $table->dropColumn('is_restricted'));
        Schema::table('en_entities', fn (Blueprint $table) => $table->dropColumn('is_restricted'));
    }
};
