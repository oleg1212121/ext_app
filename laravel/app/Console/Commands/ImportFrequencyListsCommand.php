<?php

namespace App\Console\Commands;

use App\Models\Language;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ImportFrequencyListsCommand extends Command
{
    protected $signature = 'words:import-frequency-lists
        {--path= : Directory of band word lists (default: storage/app/frequency-lists)}
        {--dry-run : Report matches without writing any ranks}';

    protected $description = 'Clamp word frequency ranks from curated band word lists (line 1: language code, line 2: band, lines 3+: one word per line): frequency = min(current, band) through direct and form matches. Never raises a rank and never creates words (ADR 0070).';

    private const BATCH_SIZE = 1000;

    private const TMP_TABLE = 'tmp_band_ranks';

    public function handle(): int
    {
        $directory = (string) ($this->option('path') ?: storage_path('app/frequency-lists'));

        if (! is_dir($directory)) {
            $this->error("Directory not found: {$directory}");

            return self::FAILURE;
        }

        $files = glob($directory.'/*.txt');

        if ($files === []) {
            $this->error("No .txt files found in {$directory}");

            return self::FAILURE;
        }

        sort($files);

        // Validate every header before touching the database, so a bad file
        // fails the whole run instead of half-applying the bands.
        $languages = Language::query()->pluck('id', 'code');
        $lists = [];

        foreach ($files as $file) {
            $list = $this->parseHeader($file, $languages);

            if ($list === null) {
                return self::FAILURE;
            }

            $lists[] = $list;
        }

        // Largest band first — for readability only, min() makes the
        // application order irrelevant.
        usort($lists, fn (array $a, array $b): int => $b['band'] <=> $a['band']);

        $groups = [];
        foreach ($lists as $list) {
            $groups[$list['code']][] = $list;
        }

        $dryRun = (bool) $this->option('dry-run');
        $totals = ['read' => 0, 'direct' => 0, 'forms' => 0, 'missed' => 0];
        $fileRows = [];

        foreach ($groups as $code => $group) {
            DB::statement('create temp table '.self::TMP_TABLE.' (l_word varchar(256) primary key, rank integer not null)');

            foreach ($group as $list) {
                $read = $this->loadList($list);
                $fileRows[] = [$list['name'], $code, $list['band'], $read];
                $totals['read'] += $read;
            }

            [$direct, $forms, $missed] = $dryRun
                ? $this->countMatches((int) $languages[$code])
                : $this->applyRanks((int) $languages[$code]);

            DB::statement('drop table if exists '.self::TMP_TABLE);

            $totals['direct'] += $direct;
            $totals['forms'] += $forms;
            $totals['missed'] += $missed;
        }

        $this->table(['File', 'Language', 'Band', 'Words read'], $fileRows);

        $verb = $dryRun ? 'Would match' : 'Matched';
        $this->table(
            ["{$verb} word rows (direct)", "{$verb} word rows (via forms)", 'Missed entries'],
            [[$totals['direct'], $totals['forms'], $totals['missed']]],
        );

        if ($dryRun) {
            $this->info('Dry run: no ranks were written.');
        }

        return self::SUCCESS;
    }

    /**
     * Read and validate the two header lines.
     *
     * @param  Collection<string, int>  $languages
     * @return array{name: string, path: string, code: string, band: int}|null
     */
    private function parseHeader(string $file, $languages): ?array
    {
        $handle = fopen($file, 'rb');

        if ($handle === false) {
            $this->error("Cannot open file: {$file}");

            return null;
        }

        $codeLine = trim((string) fgets($handle));
        $bandLine = trim((string) fgets($handle));
        fclose($handle);

        $code = mb_strtolower($codeLine);

        if (! preg_match('/^[a-z]{2}$/', $code) || ! $languages->has($code)) {
            $this->error(basename($file).": line 1 must be a language code known to the app, got '{$codeLine}'.");

            return null;
        }

        if (! ctype_digit($bandLine) || (int) $bandLine < 1) {
            $this->error(basename($file).": line 2 must be a positive band number, got '{$bandLine}'.");

            return null;
        }

        return [
            'name' => basename($file),
            'path' => $file,
            'code' => $code,
            'band' => (int) $bandLine,
        ];
    }

    /**
     * Stream lines 3+ into the temp table. Keys are lowercased and stripped
     * of combining marks, matching the parser's l_word lookup keys. A word
     * repeated across files keeps the smaller rank it already got.
     *
     * @return int non-empty words read
     */
    private function loadList(array $list): int
    {
        $handle = fopen($list['path'], 'rb');

        if ($handle === false) {
            $this->error("Cannot open file: {$list['path']}");

            return 0;
        }

        fgets($handle); // language code
        fgets($handle); // band

        $read = 0;
        $buffer = [];

        while (($line = fgets($handle)) !== false) {
            $lWord = (string) preg_replace('/\p{M}/u', '', mb_strtolower(trim($line)));

            if ($lWord === '') {
                continue;
            }

            $read++;
            $buffer[] = ['l_word' => $lWord, 'rank' => $list['band']];

            if (count($buffer) >= self::BATCH_SIZE) {
                $this->flushBuffer($buffer);
            }
        }

        fclose($handle);
        $this->flushBuffer($buffer);

        return $read;
    }

    /**
     * Insert the buffered rows and empty the buffer. A repeated l_word keeps
     * the smaller of the two bands.
     *
     * @param  array<int, array{l_word: string, rank: int}>  $buffer
     */
    private function flushBuffer(array &$buffer): void
    {
        if ($buffer === []) {
            return;
        }

        // Collapse repeats within one statement — Postgres rejects an
        // ON CONFLICT DO UPDATE that touches the same row twice.
        $rows = [];
        foreach ($buffer as $row) {
            if (isset($rows[$row['l_word']])) {
                $rows[$row['l_word']]['rank'] = min($rows[$row['l_word']]['rank'], $row['rank']);

                continue;
            }

            $rows[$row['l_word']] = $row;
        }

        $values = [];
        $bindings = [];

        foreach ($rows as $row) {
            $values[] = '(?, ?)';
            $bindings[] = $row['l_word'];
            $bindings[] = $row['rank'];
        }

        DB::statement(
            'insert into '.self::TMP_TABLE.' (l_word, rank) values '.implode(', ', $values)
            .' on conflict (l_word) do update set rank = least('.self::TMP_TABLE.'.rank, excluded.rank)',
            $bindings,
        );

        $buffer = [];
    }

    /**
     * One set-based pass per match route: direct l_word equality, then
     * inflected forms mapped to their base word. Every word-class row of a
     * matched headword moves together; a word reached through several forms
     * takes the smallest band among them.
     *
     * @return array{0: int, 1: int, 2: int} word rows matched direct, word rows matched via forms, missed entries
     */
    private function applyRanks(int $languageId): array
    {
        $direct = DB::update(
            'update words set frequency = least(words.frequency, t.rank) from '.self::TMP_TABLE.' t'
            .' where words.language_id = ? and words.l_word = t.l_word',
            [$languageId],
        );

        $forms = DB::update(
            'update words set frequency = least(words.frequency, b.rank) from ('
            .'select f.word_id, min(t.rank) as rank from '.self::TMP_TABLE.' t'
            .' join forms f on f.l_word = t.l_word'
            .' join words w on w.id = f.word_id and w.language_id = ?'
            .' group by f.word_id) b'
            .' where words.id = b.word_id',
            [$languageId],
        );

        return [$direct, $forms, $this->countMisses($languageId)];
    }

    /**
     * Dry-run counterpart of applyRanks: counts what would match without
     * writing.
     *
     * @return array{0: int, 1: int, 2: int} word rows matched direct, distinct base words matched via forms, missed entries
     */
    private function countMatches(int $languageId): array
    {
        $direct = (int) DB::selectOne(
            'select count(*) as c from words w join '.self::TMP_TABLE.' t on w.l_word = t.l_word'
            .' where w.language_id = ?',
            [$languageId],
        )->c;

        $forms = (int) DB::selectOne(
            'select count(distinct f.word_id) as c from forms f'
            .' join '.self::TMP_TABLE.' t on f.l_word = t.l_word'
            .' join words w on w.id = f.word_id and w.language_id = ?',
            [$languageId],
        )->c;

        return [$direct, $forms, $this->countMisses($languageId)];
    }

    private function countMisses(int $languageId): int
    {
        return (int) DB::selectOne(
            'select count(*) as c from '.self::TMP_TABLE.' t'
            .' where not exists (select 1 from words w where w.language_id = ? and w.l_word = t.l_word)'
            .' and not exists (select 1 from forms f join words w on w.id = f.word_id'
            .' where w.language_id = ? and f.l_word = t.l_word)',
            [$languageId, $languageId],
        )->c;
    }
}
