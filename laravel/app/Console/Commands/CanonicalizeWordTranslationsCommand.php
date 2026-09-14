<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CanonicalizeWordTranslationsCommand extends Command
{
    protected $signature = 'words:canonicalize-translations';

    protected $description = 'One-off: collapse mirrored word_translations rows into one canonical row per pair and rename from_word_id/to_word_id to word_a_id/word_b_id';

    public function handle(): int
    {
        if (DB::connection()->getName() === 'testing') {
            $this->error('Refusing to run against the testing connection.');

            return self::FAILURE;
        }

        if (! Schema::hasTable('word_translations')) {
            $this->error('Table word_translations does not exist.');

            return self::FAILURE;
        }

        $renamed = Schema::hasColumn('word_translations', 'word_a_id');
        $from = $renamed ? 'word_a_id' : 'from_word_id';
        $to = $renamed ? 'word_b_id' : 'to_word_id';

        $this->info("Canonicalizing word_translations on [".DB::connection()->getDatabaseName()."] (columns already renamed: ".var_export($renamed, true).')');

        DB::transaction(function () use ($renamed, $from, $to): void {
            // 1. Delete mirror rows where both orientations exist, keeping the
            //    canonical one (lower word id first).
            $mirrors = DB::delete("
                DELETE FROM word_translations wt
                USING word_translations mirror
                WHERE wt.{$from} = mirror.{$to}
                  AND wt.{$to} = mirror.{$from}
                  AND wt.{$from} > wt.{$to}
            ");

            $this->info("Deleted {$mirrors} mirrored row(s).");

            // 2. Rename columns and rebuild constraints to match the baseline.
            if (! $renamed) {
                Schema::table('word_translations', function (Blueprint $table): void {
                    $table->dropForeign('word_translations_from_word_id_foreign');
                    $table->dropForeign('word_translations_to_word_id_foreign');
                    $table->dropUnique('uk_word_translations_pair');
                    $table->dropIndex('idx_word_translations_to_word_id');
                    $table->renameColumn('from_word_id', 'word_a_id');
                    $table->renameColumn('to_word_id', 'word_b_id');
                });

                Schema::table('word_translations', function (Blueprint $table): void {
                    $table->foreign('word_a_id')->references('id')->on('words')->cascadeOnDelete();
                    $table->foreign('word_b_id')->references('id')->on('words')->cascadeOnDelete();
                    $table->unique(['word_a_id', 'word_b_id'], 'uk_word_translations_pair');
                    $table->index('word_b_id', 'idx_word_translations_word_b_id');
                });

                $this->info('Renamed columns to word_a_id/word_b_id and rebuilt constraints.');
            }

            // 3. Swap any remaining non-canonical rows.
            $swapped = DB::table('word_translations')
                ->whereColumn('word_a_id', '>', 'word_b_id')
                ->update([
                    'word_a_id' => DB::raw('word_b_id'),
                    'word_b_id' => DB::raw('word_a_id'),
                ]);

            $this->info("Swapped {$swapped} non-canonical row(s).");

            DB::statement("COMMENT ON TABLE word_translations IS 'Translation links: one row per word pair (canonical order: word_a_id < word_b_id).'");
        });

        $this->info('Done.');

        return self::SUCCESS;
    }
}
