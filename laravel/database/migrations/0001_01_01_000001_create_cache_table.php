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
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary()->comment('Cache key');
            $table->mediumText('value')->comment('Cached value');
            $table->integer('expiration')->comment('Expiration timestamp');
            $table->comment('Application cache');
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary()->comment('Lock key');
            $table->string('owner')->comment('Lock owner identifier');
            $table->integer('expiration')->comment('Lock expiration timestamp');
            $table->comment('Cache locks for concurrent access control');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cache');
        Schema::dropIfExists('cache_locks');
    }
};
