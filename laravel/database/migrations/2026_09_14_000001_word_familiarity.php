<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_word_event', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->comment("The user's id. Foreign key.")
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('word_id')
                ->comment("The word's id. Foreign key.")
                ->constrained('words')
                ->cascadeOnDelete();
            $table->string('row_key', 64)
                ->comment('Dedupe scope of the event: mm:{meaningMatchId} or es:{entitySentenceId}');
            $table->string('kind', 8)
                ->comment('The event kind: read (sentence revealed) or lookup (word popup opened)');
            $table->timestamps();
            $table->comment('Idempotency ledger for word familiarity deltas.');

            $table->unique(['user_id', 'word_id', 'row_key', 'kind'], 'uk_user_word_event_once');
        });

        Schema::table('user_word', function (Blueprint $table) {
            $table->unsignedTinyInteger('familiarity')
                ->default(0)
                ->after('word_id')
                ->comment('Exposure score 0-100; 100 means the user knows the word.');
        });

        DB::table('user_word')->update([
            'familiarity' => DB::raw("CASE WHEN status = 'known' THEN 100 ELSE 0 END"),
        ]);

        Schema::table('user_word', function (Blueprint $table) {
            $table->dropIndex('idx_user_word_user_status');
            $table->dropColumn('status');
            $table->index(['user_id', 'familiarity'], 'idx_user_word_user_familiarity');
        });
    }

    public function down(): void
    {
        Schema::table('user_word', function (Blueprint $table) {
            $table->string('status', 16)
                ->default('learning')
                ->after('word_id')
                ->comment("The user's progress on the word: learning, solved, known");
        });

        DB::table('user_word')->update([
            'status' => DB::raw("CASE WHEN familiarity >= 100 THEN 'known' ELSE 'learning' END"),
        ]);

        Schema::table('user_word', function (Blueprint $table) {
            $table->index(['user_id', 'status'], 'idx_user_word_user_status');
            $table->dropIndex('idx_user_word_user_familiarity');
            $table->dropColumn('familiarity');
        });

        Schema::dropIfExists('user_word_event');
    }
};
