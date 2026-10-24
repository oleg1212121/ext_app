<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The user-facing rename (ADR 0084 amendment, 2026-10-24): the stored value
 * the popup's source badge and the Filament Words table render verbatim
 * becomes "wiktionary" — the content source's real name — instead of the
 * dump service's ("kaikki.org"). The closed vocabulary's pipeline vocabulary
 * (dump file paths, --file=kaikki/..., the parser's tag helper) keeps
 * naming the dump; only this column value changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('words')->where('source', 'kaikki')->update(['source' => 'wiktionary']);
    }

    public function down(): void
    {
        DB::table('words')->where('source', 'wiktionary')->update(['source' => 'kaikki']);
    }
};
