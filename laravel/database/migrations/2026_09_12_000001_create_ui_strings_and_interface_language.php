<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('languages', function (Blueprint $table) {
            $table->boolean('is_interface_enabled')->default(false)->after('sort_order')
                ->comment('Usable as the language the web UI renders in');
        });

        DB::table('languages')->whereIn('code', ['en', 'ru'])->update(['is_interface_enabled' => true]);

        Schema::table('user_settings', function (Blueprint $table) {
            $table->foreignId('interface_language_id')->nullable()->constrained('languages')->nullOnDelete()
                ->comment('Null = follow the native language');
        });

        Schema::create('ui_string_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique()->comment('Dotted identifier, e.g. nav.library');
            $table->string('group')->index()->comment('First segment of the key: the surface the string belongs to');
            $table->timestamps();
            $table->comment('UI string registry; adding an interface language is a row insert, not DDL');
        });

        Schema::create('ui_strings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ui_string_key_id')->constrained('ui_string_keys')->cascadeOnDelete();
            $table->foreignId('language_id')->constrained('languages')->cascadeOnDelete();
            $table->text('text');
            $table->timestamps();

            $table->unique(['ui_string_key_id', 'language_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ui_strings');
        Schema::dropIfExists('ui_string_keys');

        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('interface_language_id');
        });

        Schema::table('languages', function (Blueprint $table) {
            $table->dropColumn('is_interface_enabled');
        });
    }
};
