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
        Schema::create('en_ru_entity_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('en_entity_id')->comment('The EN entity id. Foreign key.')
                ->references('id')->on('en_entities')
                ->cascadeOnDelete();
            $table->foreignId('ru_entity_id')->comment('The RU entity id. Foreign key.')
                ->references('id')->on('ru_entities')
                ->cascadeOnDelete();
            $table->string('status')->default('pending')->comment('pending|verifying|aligning|completed|failed');
            $table->decimal('entity_similarity', 5, 4)->nullable()->comment('Cosine similarity of entity signatures');
            $table->integer('en_total_sentences')->default(0)->comment('Total EN sentences');
            $table->integer('ru_total_sentences')->default(0)->comment('Total RU sentences');
            $table->integer('linked_count')->default(0)->comment('Number of matched pairs created');
            $table->integer('chunk_size')->default(75)->comment('Sentences per chunk');
            $table->integer('max_n')->default(6)->comment('Max sentence span on either side of an alignment group');
            $table->json('dp_path')->nullable()->comment('Full alignment path for viewer');
            $table->text('error_message')->nullable()->comment('Error details if failed');
            $table->timestamp('started_at')->nullable()->comment('When processing started');
            $table->timestamp('completed_at')->nullable()->comment('When processing completed');
            $table->timestamps();
            $table->comment('A pair of related (matched) EN-RU entities.');

            $table->unique(['en_entity_id', 'ru_entity_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('en_ru_entity_matches');
    }
};
