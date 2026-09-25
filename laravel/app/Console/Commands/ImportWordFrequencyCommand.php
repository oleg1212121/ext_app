<?php

namespace App\Console\Commands;

use App\Models\Entity;
use App\Models\Language;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class ImportWordFrequencyCommand extends Command
{
    protected $signature = 'words:import-frequency
        {source? : Local "rank,word" CSV file or a named source (en-opensubtitles, ru-rnc)}
        {--lang= : Language code the list belongs to (required for local files)}
        {--delimiter=, : CSV delimiter for local files}
        {--force-redownload : Re-download a named source even when its file already exists}';

    protected $description = 'Import global frequency ranks (lower = more common) onto existing dictionary words. Never creates words. Resets entity frequency-correction markers for the language.';

    private const BATCH_SIZE = 1000;

    private const TMP_TABLE = 'tmp_frequency_ranks';

    public function handle(): int
    {
        $resolved = $this->resolveSource();

        if ($resolved === null) {
            return self::FAILURE;
        }

        [$path, $code, $format] = $resolved;

        $languageId = Language::query()->where('code', $code)->value('id');

        DB::statement('drop table if exists '.self::TMP_TABLE);
        DB::statement('create temp table '.self::TMP_TABLE.' (l_word varchar(256) primary key, rank integer not null)');

        $total = match ($format) {
            'rnc_lemma_csv' => $this->importRncLemmas($path),
            'word_count' => $this->importWordCounts($path),
            default => $this->importRanksCsv($path, (string) $this->option('delimiter')),
        };

        [$matched, $missed] = $this->applyRanks($languageId);

        DB::statement('drop table if exists '.self::TMP_TABLE);

        // Fresh authoritative ranks erase every accumulated correction, so
        // the per-entity pulls re-apply once against them.
        $reset = Entity::query()
            ->where('language_id', $languageId)
            ->update(['frequency_counted_at' => null]);

        $this->table(['Total rows', 'Matched words', 'Missed (not in dictionary)'], [[$total, $matched, $missed]]);
        $this->info("Reset {$reset} entities' frequency-correction markers for '{$code}'.");

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: string, 2: string}|null path, language code, format
     */
    private function resolveSource(): ?array
    {
        $source = (string) $this->argument('source');

        if ($source === '') {
            $this->error('Provide a local file path or a named source ('.implode(', ', array_keys(config('services.frequency.sources', []))).').');

            return null;
        }

        $config = config("services.frequency.sources.{$source}");

        if ($config !== null) {
            $path = Storage::disk('local')->path('frequency/'.basename((string) data_get($config, 'url')));

            if (! is_file($path) || $this->option('force-redownload')) {
                $this->info("Downloading {$source}...");

                if (! $this->download((string) data_get($config, 'url'), $path)) {
                    return null;
                }
            }

            return [$path, (string) data_get($config, 'lang'), $this->formatForNamedSource($source)];
        }

        $code = (string) $this->option('lang');

        if (! preg_match('/^[a-z]{2}$/', $code) || ! Language::query()->where('code', $code)->exists()) {
            $this->error("Unknown language code '{$code}'.");

            return null;
        }

        if (! is_file($source)) {
            $this->error("File not found: {$source}");

            return null;
        }

        return [$source, $code, 'ranks_csv'];
    }

    private function formatForNamedSource(string $source): string
    {
        // OpenSubtitles lists "word count" per line and the rank is the line
        // position; the RNC list needs per-lemma aggregation before ranking.
        return $source === 'ru-rnc' ? 'rnc_lemma_csv' : 'word_count';
    }

    private function download(string $url, string $path): bool
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        try {
            $response = Http::timeout((int) config('services.frequency.timeout', 300))
                ->sink($path)
                ->get($url);
        } catch (\Throwable $e) {
            $this->error("Download failed: {$url} ({$e->getMessage()})");

            return false;
        }

        if ($response->failed() || ! is_file($path)) {
            $this->error("Download failed: {$url} (HTTP {$response->status()})");

            return false;
        }

        return true;
    }

    /**
     * Local "rank,word" CSV with an optional header row.
     */
    private function importRanksCsv(string $path, string $delimiter): int
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            $this->error("Cannot open file: {$path}");

            return 0;
        }

        $total = 0;
        $first = true;
        $buffer = [];

        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
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
            $buffer[] = ['l_word' => mb_strtolower($word), 'rank' => (int) $rank];
            if (count($buffer) >= self::BATCH_SIZE) {
                $this->flushBuffer($buffer);
            }
        }

        fclose($handle);
        $this->flushBuffer($buffer);

        return $total;
    }

    /**
     * OpenSubtitles "word count" lines; the rank is the line position.
     */
    private function importWordCounts(string $path): int
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            $this->error("Cannot open file: {$path}");

            return 0;
        }

        $rank = 0;
        $buffer = [];

        while (($line = fgets($handle)) !== false) {
            $word = trim(explode(' ', trim($line), 2)[0]);

            if ($word === '') {
                continue;
            }

            $rank++;
            $buffer[] = ['l_word' => mb_strtolower($word), 'rank' => $rank];
            if (count($buffer) >= self::BATCH_SIZE) {
                $this->flushBuffer($buffer);
            }
        }

        fclose($handle);
        $this->flushBuffer($buffer);

        return $rank;
    }

    /**
     * RNC lemma dictionary (Lemma,PoS,Freq(ipm),R,D,Doc): one row per lemma
     * AND part of speech, so lemmas are aggregated by summed ipm and ranked
     * most-frequent first.
     */
    private function importRncLemmas(string $path): int
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            $this->error("Cannot open file: {$path}");

            return 0;
        }

        $ipm = [];
        $first = true;

        while (($row = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
            if ($row === [null] || $row === []) {
                continue;
            }

            if ($first) {
                $first = false;
                if (strtolower(trim((string) $row[0])) === 'lemma') {
                    continue;
                }
            }

            // Lowercase and strip combining marks so the lemma matches the
            // parser's l_word lookup keys (which carry no stress marks).
            $lemma = (string) preg_replace('/\p{M}/u', '', mb_strtolower(trim((string) $row[0])));
            $freq = trim((string) ($row[2] ?? ''));

            if ($lemma === '' || $freq === '' || ! is_numeric($freq)) {
                continue;
            }

            $ipm[$lemma] = ($ipm[$lemma] ?? 0.0) + (float) $freq;
        }

        fclose($handle);

        $entries = [];
        foreach ($ipm as $lemma => $sum) {
            $entries[] = ['lemma' => $lemma, 'ipm' => $sum];
        }

        usort($entries, fn (array $a, array $b): int => $b['ipm'] <=> $a['ipm'] ?: strcmp($a['lemma'], $b['lemma']));

        $buffer = [];
        foreach ($entries as $index => $entry) {
            $buffer[] = ['l_word' => $entry['lemma'], 'rank' => $index + 1];
            if (count($buffer) >= self::BATCH_SIZE) {
                $this->flushBuffer($buffer);
            }
        }
        $this->flushBuffer($buffer);

        return count($entries);
    }

    /**
     * Insert the buffered rows and empty the buffer. A repeated l_word
     * takes the later rank, matching the old row-by-row sequential update.
     *
     * @param  array<int, array{l_word: string, rank: int}>  $buffer
     */
    private function flushBuffer(array &$buffer): void
    {
        if ($buffer === []) {
            return;
        }

        $values = [];
        $bindings = [];

        foreach ($buffer as $row) {
            $values[] = '(?, ?)';
            $bindings[] = $row['l_word'];
            $bindings[] = $row['rank'];
        }

        DB::statement(
            'insert into '.self::TMP_TABLE.' (l_word, rank) values '.implode(', ', $values)
            .' on conflict (l_word) do update set rank = excluded.rank',
            $bindings,
        );

        $buffer = [];
    }

    /**
     * One set-based pass: every word-class row of a matched headword gets
     * the rank. Never creates words.
     *
     * @return array{0: int, 1: int} matched word rows, missed list entries
     */
    private function applyRanks(int $languageId): array
    {
        $matched = DB::update(
            'update words set frequency = t.rank from '.self::TMP_TABLE.' t'
            .' where words.language_id = ? and words.l_word = t.l_word',
            [$languageId],
        );

        $missed = (int) DB::selectOne(
            'select count(*) as c from '.self::TMP_TABLE.' t'
            .' where not exists (select 1 from words w where w.language_id = ? and w.l_word = t.l_word)',
            [$languageId],
        )->c;

        return [$matched, $missed];
    }
}
