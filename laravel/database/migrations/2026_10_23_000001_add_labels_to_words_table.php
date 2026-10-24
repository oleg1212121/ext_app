<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The word-level usage mark (popup-usage-labels follow-up): the label
     * Wiktionary prints under the part-of-speech heading — "decimate
     * (obsolete)" on the noun. kaikki keeps it out of senses[]; it rides the
     * entry-level tags array and the tlb/term-label head template's args, and
     * the import maps both through the closed set onto the row itself, so the
     * popup's section header dims from the word's own mark — never an
     * aggregate of the senses below it. The import upsert's update list
     * carries the column, so a re-import backfills and refreshes it, the
     * same semantics as definitions.labels.
     */
    public function up(): void
    {
        Schema::table('words', function (Blueprint $table) {
            $table->jsonb('labels')->nullable()->after('translations')
                ->comment('Word-level usage labels from the source headword mark, closed set: obsolete, archaic, dated, colloquial, rare, dialectal (App\Support\UsageLabels). null = unmarked.');
        });
    }

    public function down(): void
    {
        Schema::table('words', function (Blueprint $table) {
            $table->dropColumn('labels');
        });
    }
};
