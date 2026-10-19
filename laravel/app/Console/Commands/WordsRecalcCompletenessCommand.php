<?php

namespace App\Console\Commands;

use App\Models\Word;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Chunked backfill of the word popup's completeness score onto
 * `words.completeness`/`words.completeness_at` — the same 0–7 matrix the
 * lazy `WordCompleteness::recalcFamily` applies on popup open (linked
 * translations 2; definitions/transcriptions/examples/etymologies 1 each; a
 * real frequency rank — 0 < rank < the unranked sentinel — 1), computed
 * set-based per id-range chunk so a run over the whole dictionary stays one
 * indexed pass per child table per chunk.
 *
 * Modes:
 * - full (default): authoritative re-score of every word in range, writing
 *   only rows whose stored value differs or whose stamp is missing — a
 *   re-run is a no-op by construction.
 * - `--stale-only`: rewrites only words the lazy staleness predicate flags
 *   (stamp missing or older than the word row, any content child, or the
 *   translation pivot). Fresh words are left untouched — their stamps keep
 *   tracking content changes — which is the mode for scheduled sweeps after
 *   the initial backfill.
 */
class WordsRecalcCompletenessCommand extends Command
{
    protected $signature = 'words:recalc-completeness
        {--stale-only : Only rewrite words whose completeness_at is missing or older than the word row or any content child (definitions/transcriptions/examples/etymologies/forms, translation links included)}
        {--chunk=5000 : Words per id-range pass}';

    protected $description = "Backfill the word popup's completeness score (0-7: linked translations 2, definitions/transcriptions/examples/etymologies 1 each, a real frequency rank 1) onto words.completeness";

    private const STATEMENT_CHUNK = 1000;

    private bool $staleOnly;

    private int $scanned = 0;

    private int $rewritten = 0;

    public function handle(): int
    {
        ini_set('memory_limit', '512M');
        set_time_limit(0);

        $this->staleOnly = (bool) $this->option('stale-only');
        $chunk = max(1, (int) $this->option('chunk'));

        $bounds = DB::table('words')->selectRaw('min(id) as min_id, max(id) as max_id')->first();
        if ($bounds === null || $bounds->min_id === null) {
            $this->info('No words to score.');

            return self::SUCCESS;
        }

        DB::statement('SET statement_timeout = 0');
        $this->scanned = 0;
        $this->rewritten = 0;

        $batches = (int) ceil(($bounds->max_id - $bounds->min_id + 1) / $chunk);
        $bar = $this->output->createProgressBar($batches);
        $bar->setFormat('[%current%/%max% passes] [%elapsed%] %message%');
        $bar->setMessage($this->staleOnly ? 'Recalculating stale words...' : 'Recalculating...');
        $bar->start();

        for ($start = (int) $bounds->min_id; $start <= (int) $bounds->max_id; $start += $chunk) {
            $this->recalcChunk($start, min($start + $chunk - 1, (int) $bounds->max_id));
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        $this->table(['Metric', 'Count'], [
            ['words scanned', $this->scanned],
            [$this->staleOnly ? 'stale words rewritten' : 'words rewritten', $this->rewritten],
        ]);

        return self::SUCCESS;
    }

    private function recalcChunk(int $start, int $end): void
    {
        $rows = DB::select($this->scanSql(), [
            Word::FREQUENCY_UNRANKED,
            $start,
            $end,
        ]);
        $this->scanned += count($rows);

        $tuples = [];
        foreach ($rows as $row) {
            if ($this->apply($row)) {
                $tuples[] = [(int) $row->id, (int) $row->score, true];
            }
            if (count($tuples) === self::STATEMENT_CHUNK) {
                $this->writeTuples($tuples);
                $tuples = [];
            }
        }
        if ($tuples !== []) {
            $this->writeTuples($tuples);
        }
    }

    /**
     * The completeness matrix set-based (mirrors WordCompleteness::score —
     * the command test pins the mirror), with both write predicates
     * computed per row: `stale` drives --stale-only, `apply_full` drives
     * the authoritative full sweep.
     */
    private function scanSql(): string
    {
        $childStamps = [
            'definitions' => 'd',
            'transcriptions' => 'c',
            'examples' => 'e',
            'etymologies' => 'et',
            'forms' => 'f',
        ];

        $thresholds = ['w.updated_at'];
        foreach ($childStamps as $table => $alias) {
            $thresholds[] = "coalesce((select greatest(max({$alias}.created_at), max({$alias}.updated_at)) from {$table} {$alias} where {$alias}.word_id = w.id), '-infinity'::timestamp)";
        }
        $thresholds[] = "coalesce((select greatest(max(t.created_at), max(t.updated_at)) from word_translations t where t.word_a_id = w.id or t.word_b_id = w.id), '-infinity'::timestamp)";

        return 'select w.id, s.score,'
            .' (w.completeness_at is null or w.completeness_at < s.threshold) as stale,'
            .' (w.completeness is distinct from s.score or w.completeness_at is null) as apply_full'
            .' from words w'
            .' cross join lateral ('
            .' select'
            // word_translations existence: 2 points
            .'   (case when exists (select 1 from word_translations t where t.word_a_id = w.id or t.word_b_id = w.id) then 2 else 0 end)'
            // each content child: 1 point
            .'  + (case when exists (select 1 from definitions d where d.word_id = w.id) then 1 else 0 end)'
            .'  + (case when exists (select 1 from transcriptions c where c.word_id = w.id) then 1 else 0 end)'
            .'  + (case when exists (select 1 from examples e where e.word_id = w.id) then 1 else 0 end)'
            .'  + (case when exists (select 1 from etymologies et where et.word_id = w.id) then 1 else 0 end)'
            // a real frequency rank: 1 point (0 is the never-ranked column
            // default, the sentinel the explicit unranked stamp)
            .'  + (case when w.frequency > 0 and w.frequency < ? then 1 else 0 end) as score,'
            .'   greatest('.implode(', ', $thresholds).') as threshold'
            .' ) s'
            .' where w.id between ? and ?';
    }

    private function apply(stdClass $row): bool
    {
        return $this->staleOnly ? (bool) $row->stale : (bool) $row->apply_full;
    }

    /**
     * @param  list<array{int, int, bool}>  $tuples  [word id, score, apply]
     */
    private function writeTuples(array $tuples): void
    {
        $placeholders = implode(', ', array_map(
            // Untyped VALUES literals bind as text — cast to the words
            // column types so the join resolves (bigint = text has no
            // operator).
            fn (array $tuple): string => '(?::bigint, ?::int, true)',
            $tuples,
        ));
        $bindings = array_merge(...array_map(fn (array $tuple): array => [$tuple[0], $tuple[1]], $tuples));

        $this->rewritten += DB::affectingStatement(
            'update words as w set completeness = v.score, completeness_at = now()'
            ." from (values {$placeholders}) as v(id, score, apply)"
            .' where w.id = v.id and v.apply',
            $bindings,
        );
    }
}
