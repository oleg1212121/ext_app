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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('User name');
            $table->string('email')->unique()->comment('User email address');
            $table->timestamp('email_verified_at')->nullable()->comment('Email verification timestamp');
            $table->string('password')->comment('Hashed password');
            $table->rememberToken();
            $table->timestamps();
            $table->comment('Application users');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary()->comment('User email address');
            $table->string('token')->comment('Password reset token');
            $table->timestamp('created_at')->nullable()->comment('Token creation timestamp');
            $table->comment('Password reset tokens');
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary()->comment('Session ID');
            $table->foreignId('user_id')->nullable()->index()->comment('User ID. Foreign key.');
            $table->string('ip_address', 45)->nullable()->comment('Client IP address');
            $table->text('user_agent')->nullable()->comment('Client user agent');
            $table->longText('payload')->comment('Session data payload');
            $table->integer('last_activity')->index()->comment('Last activity timestamp');
            $table->comment('User sessions');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
