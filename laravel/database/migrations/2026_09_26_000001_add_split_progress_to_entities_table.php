<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->unsignedBigInteger('split_offset')
                ->default(0)
                ->after('file_path')
                ->comment('File bytes fully fed to the sentence splitter; a resumed run continues from here.');
            $table->text('split_remainder')
                ->nullable()
                ->after('split_offset')
                ->comment("Python splitter's unsplittable text tail carried across chunk boundaries and pipeline runs.");
        });
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->dropColumn(['split_offset', 'split_remainder']);
        });
    }
};
