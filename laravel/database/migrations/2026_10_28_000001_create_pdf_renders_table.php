<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_renders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')
                ->nullable()
                ->comment("The rendered entity's id. Foreign key. Null for non-entity subjects.")
                ->constrained('entities')
                ->nullOnDelete();
            $table->foreignId('entity_match_id')
                ->nullable()
                ->comment("The rendered entity match's id. Foreign key. Unused until alignment PDFs ship (book-pdf ticket 02).")
                ->constrained('entity_matches')
                ->nullOnDelete();
            $table->char('content_hash', 64)
                ->comment("sha256 over everything the rendered content depends on: the subject's fresh text hash (EntityTextHasher) plus the title-page inputs. A digest that no longer matches the row means the stored file is stale and must be re-rendered.");
            $table->string('path')
                ->comment("Rendered PDF path on the 'local' disk, fixed per subject so stale files are overwritten, never accumulated.");
            $table->unsignedBigInteger('size_bytes')
                ->nullable()
                ->comment('Rendered file size in bytes.');
            $table->timestamp('generated_at')
                ->comment('When the PDF was last rendered; a cache hit never moves it.');
            $table->comment('Cached server-rendered book PDFs, one row per subject (entity or entity match), updated in place.');
        });

        // One render row per subject. Plain unique indexes would behave the
        // same (NULLs are distinct in PostgreSQL), but the partial form
        // states the rule outright: exactly one row per non-null FK.
        DB::statement('CREATE UNIQUE INDEX pdf_renders_entity_id_unique ON pdf_renders (entity_id) WHERE entity_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX pdf_renders_entity_match_id_unique ON pdf_renders (entity_match_id) WHERE entity_match_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_renders');
    }
};
