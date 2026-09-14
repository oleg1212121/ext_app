<?php

namespace App\Console\Commands;

use App\Models\Language;
use App\Models\Word;
use Illuminate\Console\Command;

class ImportWordFrequencyCommand extends Command
{
    protected $signature = 'words:import-frequency
        {file : CSV file with "rank,word" rows (header optional)}
        {--lang= : Language code the list belongs to}
        {--delimiter=, : CSV delimiter}';

    protected $description = 'Import global frequency ranks (lower = more common) onto existing dictionary words. Never creates words.';

    private const BATCH_SIZE = 500;

    public function handle(): int
    {
        $code = (string) $this->option('lang');

        if (! preg_match('/^[a-z]{2}$/', $code) || ! Language::query()->where('code', $code)->exists()) {
            $this->error("Unknown language code '{$code}'.");

            return self::FAILURE;
        }

        $path = (string) $this->argument('file');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $languageId = Language::query()->where('code', $code)->value('id');
        $delimiter = (string) $this->option('delimiter');

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            $this->error("Cannot open file: {$path}");

            return self::FAILURE;
        }

        $matched = 0;
        $missed = 0;
        $total = 0;
        $first = true;
        $bar = $this->output->createProgressBar();

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($row === [null] || $row === []) {
                continue;
            }

            $rank = trim((string) $row[0]);
            $word = trim((string) ($row[1] ?? ''));

            if ($first) {
                $first = false;
                if (strtolower($word) === 'word' && ! ctype_digit($rank)) {
                    continue;
                }
            }

            if ($rank === '' || $word === '' || ! ctype_digit($rank)) {
                continue;
            }

            $total++;
            $matched += $this->applyRank((int) $rank, $word, $languageId);
            if ($matched % self::BATCH_SIZE === 0 && $matched > 0) {
                $bar->advance();
            }
        }

        fclose($handle);
        $bar->finish();
        $this->newLine(2);

        $this->table(['Total rows', 'Matched words', 'Missed (not in dictionary)'], [[$total, $matched, $total - $matched]]);

        return self::SUCCESS;
    }

    private function applyRank(int $rank, string $word, int $languageId): int
    {
        return Word::query()
            ->where('language_id', $languageId)
            ->where('l_word', mb_strtolower($word))
            ->update(['frequency' => $rank]);
    }
}
