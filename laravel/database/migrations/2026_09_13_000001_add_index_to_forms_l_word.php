<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table): void {
            // The entity word linker resolves inflected tokens through this
            // column (entity_words.l_word -> forms.l_word -> words.id).
            $table->index('l_word', 'idx_forms_l_word');
        });
    }

    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table): void {
            $table->dropIndex('idx_forms_l_word');
        });
    }
};
