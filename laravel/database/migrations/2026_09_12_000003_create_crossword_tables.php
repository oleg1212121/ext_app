<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_words', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')
                ->comment("The entity's id. Foreign key.")
                ->constrained('entities')
                ->cascadeOnDelete();
            $table->foreignId('word_id')
                ->nullable()
                ->comment("The linked dictionary word. Null until the link pass matches the token. Foreign key.")
                ->constrained('words')
                ->nullOnDelete();
            $table->string('l_word', 256)->comment("The token's lowercase form");
            $table->string('token', 256)->comment("The first-seen surface form of the token in the text");
            $table->unsignedInteger('count')->default(1)->comment('How many times the token occurs in the entity');
            $table->timestamps();
            $table->comment("The entity word list: unique tokens of one entity with occurrence counts, dictionary link filled later.");

            $table->unique(['entity_id', 'l_word'], 'uk_entity_words_entity_l_word');
            $table->index('word_id', 'idx_entity_words_word_id');
        });

        Schema::create('user_word', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->comment("The user's id. Foreign key.")
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('word_id')
                ->comment("The word's id. Foreign key.")
                ->constrained('words')
                ->cascadeOnDelete();
            $table->string('status', 16)
                ->default('learning')
                ->comment("The user's progress on the word: learning, solved, known");
            $table->timestamps();
            $table->comment('Per-user global word progress (learning/solved/known).');

            $table->unique(['user_id', 'word_id'], 'uk_user_word_user_word');
            $table->index(['user_id', 'status'], 'idx_user_word_user_status');
        });

        Schema::table('entities', function (Blueprint $table) {
            $table->timestamp('words_indexed_at')
                ->nullable()
                ->after('is_restricted')
                ->comment('When the entity word list was last built; null means never.');
        });
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->dropColumn('words_indexed_at');
        });
        Schema::dropIfExists('user_word');
        Schema::dropIfExists('entity_words');
    }
};
