<?php

namespace App\Console\Commands;

use App\Classes\Wiktionary\FormOfClassifier;
use App\Classes\Wiktionary\RelayDemotionMap;
use App\Models\Form;
use App\Models\Language;
use App\Models\Word;
use App\Models\WordClass;
use App\Models\WordTranslation;
use App\Support\LemmaForms;
use App\Support\StagedTranslations;
use App\Support\StressMarks;
use App\Support\TranslationTargets;
use App\Support\WordLookupKey;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The dump-driven cleanup for relay word rows the pre-ticket-03 import
 * stored as words: stream the kaikki dump for one language, build the exact
 * relay→base map (the shared FormOfClassifier signal), then apply it
 * set-based, in chunks — re-point translation links (both arms) and
 * entity_words onto the base, move the relay's staged translations JSON onto
 * the base, attach the relay surface as a form of the base, fold the relay's
 * frequency rank into the base as LEAST (ranks: lower = more common — LEAST
 * never raises a rank, and the unranked sentinel never demotes a ranked
 * base), then delete the relay rows. Relay glosses and self-forms die with
 * the row — they are pure pointers. Bases missing from the database keep
 * their relays as words: counted, sampled, never deleted. The same stream
 * collects the romanization-tagged junk forms (Latin on Cyrillic words) and
 * a final phase deletes those form rows.
 *
 * Re-runnable by construction: a demoted relay no longer exists as a word
 * row, so a second run matches nothing. Run --dry first — it prints every
 * phase count without writing (dry link/dedupe counts are pre-database
 * approximations). This deletes by explicit ids from the dump map — it is
 * not a wipe.
 */
class DemoteFormOfCommand extends Command
{
    protected $signature = 'wiktionary:demote-form-of
        {--lang= : The language whose form-of relay word rows demote (registry code, e.g. ru|en)}
        {--file= : The kaikki JSONL dump (default: kaikki/kaikki.org-dictionary-<Language name>.jsonl)}
        {--dry : Report the phase counts without writing anything}
        {--chunk=25000 : Relay keys resolved and applied per database pass}';

    protected $description = 'Demote imported kaikki form-of relay word rows to forms of their base words — dump-driven, set-based, re-runnable';

    private const STATEMENT_CHUNK = 1000;

    private const SAMPLE_LIMIT = 10;

    private Language $language;

    private bool $dry;

    private bool $strips;

    /** @var array<string, string> relay l_word => terminal base l_word */
    private array $map = [];

    private RelayDemotionMap $builder;

    /** @var array<string, string> class id => slug, this language */
    private array $slugByClassId = [];

    /** @var array<string, array<string, true>> entry l_word => its romanization form l_words */
    private array $romanByEntry = [];

    private int $linesRead = 0;

    /** @var array<string, int> */
    private array $stats = [
        'matched' => 0,
        'missing' => 0,
        'links_rep' => 0,
        'links_dedupe' => 0,
        'self_pairs' => 0,
        'entity' => 0,
        'staged' => 0,
        'forms' => 0,
        'freq' => 0,
        'deleted' => 0,
        'roman' => 0,
    ];

    /** @var list<string> */
    private array $missingSample = [];

    public function handle(): int
    {
        // The relay map keeps the whole language's vocabulary in memory until
        // the stream ends (the raw-wiktextract importer flushes batches, this
        // command cannot), and single decoded dump lines can spike past the
        // CLI default of 128M mid-scan.
        ini_set('memory_limit', '2G');
        set_time_limit(0);

        $langCode = (string) $this->option('lang');
        $language = $langCode === ''
            ? null
            : Language::query()->where('code', $langCode)->first();
        if ($language === null) {
            $this->error("Unknown source language: {$langCode}. Pass --lang with a languages-registry code.");

            return self::FAILURE;
        }
        $this->language = $language;
        $this->dry = (bool) $this->option('dry');
        $this->strips = StressMarks::stripsFor($langCode);
        $this->slugByClassId = WordClass::query()
            ->where('language_id', $language->id)
            ->pluck('slug', 'id')
            ->toArray();

        $file = (string) ($this->option('file') ?: 'kaikki/kaikki.org-dictionary-'.$language->name.'.jsonl');
        if (! is_file($file)) {
            $file = base_path($file);
        }
        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        DB::statement('SET statement_timeout = 0');
        try {
            $this->scan($file);

            $this->map = $this->builder->finalize()->map();
            $this->info(sprintf(
                'Scan done: %d lines — %d relay surfaces demote, %d also-real stay words,'
                .' %d base conflicts stay words, %d cycles dropped, %d chained demotions.',
                $this->linesRead,
                count($this->map),
                $this->builder->alsoReal(),
                $this->builder->conflicts(),
                $this->builder->cycles(),
                $this->builder->chained(),
            ));

            $chunk = max(1, (int) $this->option('chunk'));
            $keys = array_keys($this->map);
            $chunks = (int) ceil(count($keys) / $chunk);
            foreach (array_chunk($keys, $chunk) as $i => $chunkKeys) {
                if ($this->dry) {
                    $this->applyChunk($chunkKeys);
                } else {
                    DB::transaction(fn () => $this->applyChunk($chunkKeys));
                }
                $this->info(sprintf(
                    'Chunk %d/%d applied — matched %d, deleted %d so far.',
                    $i + 1,
                    $chunks,
                    $this->stats['matched'],
                    $this->stats['deleted'],
                ));
            }

            $this->deleteRomanizationForms();

            if ($this->stats['missing'] > 0) {
                $this->warn(sprintf(
                    'Bases missing from the database — %d relay word rows stay words. Sample:',
                    $this->stats['missing'],
                ));
                foreach ($this->missingSample as $sample) {
                    $this->warn('  '.$sample);
                }
            }
        } finally {
            // Never let the timeout reset mask a real failure (a statement
            // may have left the connection's transaction aborted).
            try {
                DB::statement('SET statement_timeout = 0');
            } catch (\Throwable) {
            }
        }

        $this->newLine(2);
        if ($this->dry) {
            $this->info('DRY RUN — nothing was written.');
        }
        $this->table(['Phase', 'Count'], [
            ['Dump lines read', $this->linesRead],
            ['Relay word rows matched', $this->stats['matched']],
            ['Relay rows kept (base missing)', $this->stats['missing']],
            ['Translation links re-pointed', $this->stats['links_rep']],
            ['Link self-pairs dropped', $this->stats['self_pairs']],
            ['Links deduped onto existing pairs', $this->stats['links_dedupe']],
            ['entity_words re-pointed', $this->stats['entity']],
            ['Staged translation rows merged', $this->stats['staged']],
            ['Forms attached', $this->stats['forms']],
            ['Bases re-ranked (LEAST fold)', $this->stats['freq']],
            ['Relay word rows deleted', $this->stats['deleted']],
            ['Romanization forms deleted', $this->stats['roman']],
        ]);

        return self::SUCCESS;
    }

    /**
     * Stream the dump once: classify every entry into the shared relay map
     * and collect the romanization-tagged form strings (the junk-form delete
     * set) for the stress-mark languages.
     */
    private function scan(string $file): void
    {
        $this->builder = new RelayDemotionMap;

        $bar = $this->output->createProgressBar();
        $bar->setFormat('[%current% lines] [%elapsed%] %message%');
        $bar->setMessage('Scanning...');
        $bar->start();

        $handle = fopen($file, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open file: {$file}");
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $this->linesRead++;
                if ($this->linesRead % 1000 === 0) {
                    $bar->setProgress($this->linesRead);
                }

                $decoded = json_decode($line);
                if ($decoded === null
                    || ! is_object($decoded)
                    || ! isset($decoded->word)
                    || ! is_string($decoded->word)
                    || trim($decoded->word) === '') {
                    continue;
                }

                $verdict = FormOfClassifier::classify($decoded);
                $this->builder->add([
                    'word' => $decoded->word,
                    'relay' => $verdict['relay'],
                    'base' => $verdict['base'],
                ]);

                if ($this->strips) {
                    $this->collectRomanizations($decoded);
                }
            }
        } finally {
            fclose($handle);
            $bar->finish();
            $this->newLine();
        }
    }

    private function collectRomanizations(object $decoded): void
    {
        $entryKey = WordLookupKey::normalize($decoded->word);
        foreach ((array) ($decoded->forms ?? []) as $form) {
            $tags = is_array($form->tags ?? null) ? $form->tags : [];
            $text = $form->form ?? null;
            if (! in_array('romanization', $tags, true)
                || ! is_string($text)
                || trim($text) === '') {
                continue;
            }
            $norm = WordLookupKey::normalize(mb_substr($text, 0, LemmaForms::CAP));
            if ($norm !== '') {
                $this->romanByEntry[$entryKey][$norm] = true;
            }
        }
    }

    /**
     * One apply pass: resolve this chunk's relay rows and bases, then run
     * the phases in FK-safe order — links, entity words, staged JSON and
     * ranks before the relay rows go (the row delete cascades the rest).
     *
     * @param  list<string>  $keys
     */
    private function applyChunk(array $keys): void
    {
        $relayRows = Word::query()
            ->where('language_id', $this->language->id)
            ->whereIn('l_word', $keys)
            ->orderBy('id')
            ->get();

        $this->stats['matched'] += $relayRows->count();

        $baseKeys = array_values(array_unique(array_map(
            fn (string $key): string => $this->map[$key],
            $keys,
        )));
        $baseRows = Word::query()
            ->where('language_id', $this->language->id)
            ->whereIn('l_word', $baseKeys)
            ->orderBy('id')
            ->get(['id', 'l_word', 'word_class_id', 'frequency', 'translations']);
        $baseById = $baseRows->keyBy('id');

        // The shared resolver semantics per wanted class: same-class base row
        // preferred, else lowest id; an unknown-class relay skips the
        // preference entirely.
        $wanted = [];
        $groupOf = [];
        foreach ($relayRows as $row) {
            $slug = $this->slugByClassId[$row->word_class_id] ?? 'unknown';
            $classId = $slug === 'unknown' ? null : $row->word_class_id;
            $groupKey = $classId === null ? 'any' : 'c'.$classId;
            $groupOf[$row->id] = $groupKey;
            $wanted[$groupKey][$this->map[$row->l_word]] = $classId;
        }

        $picked = [];
        foreach ($wanted as $groupKey => $groupWanted) {
            $picked[$groupKey] = TranslationTargets::pick($groupWanted, $baseRows);
        }

        $resolved = [];
        foreach ($relayRows as $row) {
            $baseId = $picked[$groupOf[$row->id]][$this->map[$row->l_word]] ?? null;
            if ($baseId === null) {
                $this->stats['missing']++;
                if (count($this->missingSample) < self::SAMPLE_LIMIT) {
                    $this->missingSample[] = $row->l_word.' → '.$this->map[$row->l_word];
                }

                continue;
            }
            $resolved[$row->id] = $baseId;
        }

        $this->applyLinks($resolved);
        $this->applyEntityWords($resolved);
        $this->applyStagedTranslations($relayRows, $resolved, $baseById);
        $this->applyForms($relayRows, $resolved);
        $this->applyFrequency($relayRows, $resolved, $baseById);

        $this->stats['deleted'] += count($resolved);
        if (! $this->dry) {
            foreach (array_chunk(array_keys($resolved), self::STATEMENT_CHUNK) as $ids) {
                Word::query()->whereIn('id', $ids)->delete();
            }
        }
    }

    /**
     * Re-point both word_translations arms: every link touching a relay row
     * is deleted and re-inserted onto the relay's base (canonical pair
     * order, insert-or-ignore dedupes onto pre-existing pairs and within
     * the chunk). A pair collapsing onto itself (relay→own base, two relays
     * of one base) is dropped. Other-side relays demoting in a LATER chunk
     * resolve through the full map, so their pair survives both deletions.
     *
     * @param  array<int, int>  $resolved  relay word id => base word id
     */
    private function applyLinks(array $resolved): void
    {
        if ($resolved === []) {
            return;
        }

        $relayIdSet = array_flip(array_keys($resolved));

        $linkRows = WordTranslation::query()
            ->where(function ($query) use ($relayIdSet) {
                $query->whereIn('word_a_id', array_keys($relayIdSet))
                    ->orWhereIn('word_b_id', array_keys($relayIdSet));
            })
            ->get(['id', 'word_a_id', 'word_b_id']);

        $otherIds = [];
        foreach ($linkRows as $link) {
            foreach ([$link->word_a_id, $link->word_b_id] as $side) {
                if (! isset($relayIdSet[$side])) {
                    $otherIds[] = (int) $side;
                }
            }
        }
        $otherLWords = $otherIds === []
            ? collect()
            : Word::query()->whereIn('id', array_values(array_unique($otherIds)))->pluck('l_word', 'id');

        $crossBaseIds = [];
        $crossKeys = [];
        foreach ($otherLWords as $lWord) {
            if (isset($this->map[$lWord])) {
                $crossKeys[] = $this->map[$lWord];
            }
        }
        foreach (array_unique($crossKeys) as $baseKey) {
            // Cross-chunk relays carry no wanted class here: lowest id (the
            // resolver's fallback) keeps the pick deterministic.
            $id = Word::query()
                ->where('language_id', $this->language->id)
                ->where('l_word', $baseKey)
                ->min('id');
            if ($id !== null) {
                $crossBaseIds[$baseKey] = (int) $id;
            }
        }

        $pairs = [];
        $selfPairs = 0;
        foreach ($linkRows as $link) {
            $sideIds = [];
            foreach (['word_a_id', 'word_b_id'] as $arm) {
                $side = (int) $link->{$arm};
                if (isset($relayIdSet[$side])) {
                    $sideIds[] = $resolved[$side];

                    continue;
                }
                $other = $otherLWords->get($side);
                $sideIds[] = $other !== null && isset($this->map[$other], $crossBaseIds[$this->map[$other]])
                    ? $crossBaseIds[$this->map[$other]]
                    : $side;
            }

            if ($sideIds[0] === $sideIds[1]) {
                $selfPairs++;

                continue;
            }

            [$aId, $bId] = WordTranslation::canonicalize($sideIds[0], $sideIds[1]);
            $pairs[$aId.':'.$bId] = ['word_a_id' => $aId, 'word_b_id' => $bId];
        }

        if (! $this->dry) {
            foreach ($linkRows->pluck('id')->chunk(self::STATEMENT_CHUNK) as $ids) {
                WordTranslation::query()->whereIn('id', $ids->all())->delete();
            }

            $inserted = 0;
            $stamp = now();
            foreach (array_chunk(array_values($pairs), self::STATEMENT_CHUNK) as $chunk) {
                $rows = array_map(fn (array $pair): array => $pair + [
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ], $chunk);
                $inserted += DB::table('word_translations')->insertOrIgnore($rows);
            }

            $this->stats['links_rep'] += $inserted;
            $this->stats['links_dedupe'] += count($pairs) - $inserted;
        } else {
            $this->stats['links_rep'] += count($pairs);
        }
        $this->stats['self_pairs'] += $selfPairs;
    }

    /**
     * entity_words.word_id re-points from the relay onto the base (the
     * token's l_word stays the surface the entity text actually carries).
     *
     * @param  array<int, int>  $resolved  relay word id => base word id
     */
    private function applyEntityWords(array $resolved): void
    {
        if ($resolved === []) {
            return;
        }

        if ($this->dry) {
            $this->stats['entity'] += DB::table('entity_words')
                ->whereIn('word_id', array_keys($resolved))
                ->whereNotNull('word_id')
                ->count();

            return;
        }

        foreach (array_chunk(array_keys($resolved), self::STATEMENT_CHUNK) as $chunkIds) {
            $placeholders = [];
            $bindings = [];
            foreach ($chunkIds as $relayId) {
                $placeholders[] = '(?, ?)';
                $bindings[] = $relayId;
                $bindings[] = $resolved[$relayId];
            }
            $this->stats['entity'] += DB::affectingStatement(
                'update entity_words as ew set word_id = v.base_id::bigint'
                .' from (values '.implode(', ', $placeholders).') as v(relay_id, base_id)'
                .' where ew.word_id = v.relay_id::bigint',
                $bindings,
            );
        }
    }

    /**
     * The relay's staged translations JSON moves onto the base through the
     * shared union semantics — before the relay rows go (a base survives the
     * delete; the relay does not).
     *
     * @param  Collection<int, Word>  $relayRows
     * @param  array<int, int>  $resolved
     * @param  Collection<int, Word>  $baseById
     */
    private function applyStagedTranslations(Collection $relayRows, array $resolved, Collection $baseById): void
    {
        $candidatesByBase = [];
        $rowsByBase = [];
        foreach ($relayRows as $row) {
            $staged = $row->translations;
            if (! is_array($staged) || $staged === [] || ! isset($resolved[$row->id])) {
                continue;
            }
            $baseId = $resolved[$row->id];
            foreach ($staged as $candidate) {
                $candidatesByBase[$baseId][] = $candidate;
            }
            $rowsByBase[$baseId] = ($rowsByBase[$baseId] ?? 0) + 1;
        }

        foreach ($candidatesByBase as $baseId => $candidates) {
            $base = $baseById->get($baseId);
            if ($base === null) {
                continue;
            }
            $merged = StagedTranslations::merge($base->translations, $candidates);
            if ($merged === $base->translations) {
                continue;
            }
            if (! $this->dry) {
                DB::table('words')->where('id', $baseId)
                    ->update(['translations' => $merged === null ? null : json_encode($merged)]);
            }
            $this->stats['staged'] += $rowsByBase[$baseId];
        }
    }

    /**
     * The relay surface attaches as a form of the base — the same
     * cap/strip/normalize conventions as every other form row (pre-02 rows
     * may hold stressed displays; the strip fixes them here). The upsert on
     * (form, word_id) skips a base that already claims the surface.
     *
     * @param  Collection<int, Word>  $relayRows
     * @param  array<int, int>  $resolved
     */
    private function applyForms(Collection $relayRows, array $resolved): void
    {
        $rows = [];
        foreach ($relayRows as $row) {
            if (! isset($resolved[$row->id])) {
                continue;
            }
            array_push($rows, ...LemmaForms::rows($row->word, [], $resolved[$row->id], $this->strips));
        }
        $rows = $this->uniqueByCompound($rows, ['form', 'word_id']);
        if ($rows === []) {
            return;
        }

        if ($this->dry) {
            $existing = Form::query()
                ->whereIn('word_id', array_values(array_unique(array_column($rows, 'word_id'))))
                ->whereIn('form', array_values(array_unique(array_column($rows, 'form'))))
                ->get(['form', 'word_id'])
                ->keyBy(fn ($form) => $form->word_id.'|'.$form->form);
            foreach ($rows as $i => $row) {
                if ($existing->has($row['word_id'].'|'.$row['form'])) {
                    unset($rows[$i]);
                }
            }
        } else {
            foreach (array_chunk($rows, self::STATEMENT_CHUNK) as $chunk) {
                Form::upsert($chunk, ['form', 'word_id']);
            }
        }

        $this->stats['forms'] += count($rows);
    }

    /**
     * The relay's frequency rank folds into the base as LEAST — the best
     * rank wins, the unranked sentinel never demotes a ranked base (ADR
     * 0070's never-raise-a-rank fold, through the relay surface's rank).
     *
     * @param  Collection<int, Word>  $relayRows
     * @param  array<int, int>  $resolved
     * @param  Collection<int, Word>  $baseById
     */
    private function applyFrequency(Collection $relayRows, array $resolved, Collection $baseById): void
    {
        $bestByBase = [];
        foreach ($relayRows as $row) {
            if (! isset($resolved[$row->id])) {
                continue;
            }
            $baseId = $resolved[$row->id];
            $bestByBase[$baseId] = min($bestByBase[$baseId] ?? INF, (float) $row->frequency);
        }

        $raise = [];
        foreach ($bestByBase as $baseId => $freq) {
            $base = $baseById->get($baseId);
            if ($base !== null && $freq < (float) $base->frequency) {
                $raise[$baseId] = $freq;
            }
        }
        if ($raise === []) {
            return;
        }
        $this->stats['freq'] += count($raise);
        if ($this->dry) {
            return;
        }

        foreach (array_chunk($raise, self::STATEMENT_CHUNK, true) as $chunk) {
            $placeholders = [];
            $bindings = [];
            foreach ($chunk as $baseId => $freq) {
                $placeholders[] = '(?, ?)';
                $bindings[] = $baseId;
                $bindings[] = $freq;
            }
            DB::affectingStatement(
                'update words as w set frequency = least(w.frequency, v.freq::numeric)'
                .' from (values '.implode(', ', $placeholders).') as v(word_id, freq)'
                .' where w.id = v.word_id::bigint',
                $bindings,
            );
        }
    }

    /**
     * Romanization-tagged junk forms (Latin transliterations on Cyrillic
     * words, stored by the pre-ticket-02 import) collected from the same
     * dump stream, deleted by the exact (entry l_word, form l_word) pair —
     * dump-derived, never a heuristic.
     */
    private function deleteRomanizationForms(): void
    {
        if (! $this->strips) {
            return;
        }

        $pairs = [];
        foreach ($this->romanByEntry as $entryKey => $norms) {
            foreach (array_keys($norms) as $formNorm) {
                $pairs[] = [$entryKey, $formNorm];
            }
        }

        foreach (array_chunk($pairs, self::STATEMENT_CHUNK) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '(?, ?)'));
            // Placeholder order follows the SQL text: the dry SELECT's join
            // puts the language id first, the DELETE's WHERE puts it last.
            $pairBindings = [];
            foreach ($chunk as [$entryKey, $formNorm]) {
                $pairBindings[] = $entryKey;
                $pairBindings[] = $formNorm;
            }

            if ($this->dry) {
                $count = DB::selectOne(
                    'select count(*) as n from forms f'
                    .' join words w on w.id = f.word_id and w.language_id = ?'
                    .' join (values '.$placeholders.') as v(entry_l, form_l)'
                    .' on w.l_word = v.entry_l::varchar and f.l_word = v.form_l::varchar',
                    [$this->language->id, ...$pairBindings],
                )->n;
                $this->stats['roman'] += (int) $count;
            } else {
                $this->stats['roman'] += DB::affectingStatement(
                    'delete from forms f'
                    .' using words w, (values '.$placeholders.') as v(entry_l, form_l)'
                    .' where f.word_id = w.id and w.language_id = ?'
                    .' and w.l_word = v.entry_l::varchar and f.l_word = v.form_l::varchar',
                    [...$pairBindings, $this->language->id],
                );
            }
        }
    }

    /**
     * Dedupe rows on the compound identity a table enforces.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $keyColumns
     * @return list<array<string, mixed>>
     */
    private function uniqueByCompound(array $rows, array $keyColumns): array
    {
        $seen = [];
        $result = [];
        foreach ($rows as $row) {
            $key = implode('|', array_map(fn (string $col): string => (string) ($row[$col] ?? ''), $keyColumns));
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $row;
            }
        }

        return $result;
    }
}
