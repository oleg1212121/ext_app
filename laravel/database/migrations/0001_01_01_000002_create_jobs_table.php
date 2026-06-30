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
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index()->comment('Queue name');
            $table->longText('payload')->comment('Job payload data');
            $table->unsignedTinyInteger('attempts')->comment('Number of attempts');
            $table->unsignedInteger('reserved_at')->nullable()->comment('When job was reserved');
            $table->unsignedInteger('available_at')->comment('When job becomes available');
            $table->unsignedInteger('created_at')->comment('Job creation timestamp');
            $table->comment('Queued jobs');
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary()->comment('Batch ID');
            $table->string('name')->comment('Batch name');
            $table->integer('total_jobs')->comment('Total jobs in batch');
            $table->integer('pending_jobs')->comment('Pending jobs count');
            $table->integer('failed_jobs')->comment('Failed jobs count');
            $table->longText('failed_job_ids')->comment('IDs of failed jobs');
            $table->mediumText('options')->nullable()->comment('Batch options');
            $table->integer('cancelled_at')->nullable()->comment('Cancellation timestamp');
            $table->integer('created_at')->comment('Batch creation timestamp');
            $table->integer('finished_at')->nullable()->comment('Batch completion timestamp');
            $table->comment('Job batches');
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique()->comment('Job UUID');
            $table->text('connection')->comment('Queue connection name');
            $table->text('queue')->comment('Queue name');
            $table->longText('payload')->comment('Job payload data');
            $table->longText('exception')->comment('Exception details');
            $table->timestamp('failed_at')->useCurrent()->comment('Failure timestamp');
            $table->comment('Failed jobs');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('failed_jobs');
    }
};
