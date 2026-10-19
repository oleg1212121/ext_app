<?php

namespace App\Console\Commands;

use App\Models\Language;
use App\Models\Word;
use App\Models\WordClass;
use App\Support\LemmaForms;
use App\Support\PosAliases;
use App\Support\StagedTranslations;
use App\Support\StressMarks;
use App\Support\WordLookupKey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * The in-place repair of EXISTING dictionary rows to the purity rules, in
 * idempotent phases (the residual the importers cannot fix themselves):
 *
 * 1. Strip the combining stress marks from ru/be/uk display `words.word` and
 *    `forms.form` (spec rule 1 — the ADR 0078 display carve-out reversal).
 *    A stressed word merges into an existing plain twin (same language +
 *    class + stripped display — keep the lowest id, move the children,
 *    delete the absorbed row); without a twin it strips in place. Stressed
 *    forms group per (word, stripped form): a group whose plain twin already
 *    exists collapses onto it, otherwise the lowest form id survives the
 *    strip and the other variants in the group dedupe away (two stressed
 *    variants can strip to the same plain string). en rows are legitimate
 *    spelling ("café") and untouched — the strip applies to the
 *    App\Support\StressMarks languages only. A display-exact twin lookup
 *    cannot see case variants (До́м strips to Дом, not дом) — the residue
 *    those leave behind is exactly what phase 2's duplicate merge absorbs.
 * 2. Recompute the l_word lookup keys to the current WordLookupKey formula
 *    (trim → lowercase → combining-mark strip → zero-width strip → ё→е
 *    fold) across words and forms — chunked, updating only rows whose value
 *    actually changes: stale old-formula keys (ёлка, до́м with its mark
 *    intact), en diacritic keys (café → cafe), NULL keys backfilled; a
 *    display that computes to the empty key stays unkeyed, like phase 1
 *    leaves it. The words pass then merges duplicate
 *    (l_word, language_id, word_class_id) rows — the residue pre-migrate
 *    databases still hold (cross-source ёлка/елка spellings, case twins),
 *    since same-key rows can no longer be created and the unique index
 *    refuses to build over them. The survivor preference is a display
 *    containing ё first (the fullest spelling survives — the user edits the
 *    display later), then most definitions, then the lowest id; detection
 *    runs on the effective (post-recompute) keys, and the key updates apply
 *    only after the merge, so a row is never updated into a collision. This
 *    phase works under the OLD schema too — the unique index must not need
 *    to exist: zero duplicate groups afterwards is what lets `migrate`
 *    build it (the dev-DB normalize → migrate sequencing).
 * 3. Merge `unknown`-class twins into the properly-classed same-l_word row
 *    (cmudict and entity adoption minted them before ticket 08). The
 *    survivor is the lowest-id non-unknown row; the cmudict convention
 *    holds — when the survivor already carries a ˈ-marked IPA transcription
 *    the twin's transcriptions die with the merge instead of stacking on
 *    top (Kaikki wins where it has stress data).
 * 4. Fold alias word classes into their canonical class per language
 *    (PosAliases semantics, resolved by class-row slug — the dev data's
 *    `adj` beside `adjective`): a word whose l_word exists in the
 *    canonical class merges into the lowest-id canonical row (case/ё
 *    variants included — one lookup key IS one word under the words
 *    unique), the twinless leftovers reclass in place, and the orphan
 *    alias class rows delete last.
 * 5. Recompute the entity tokenizer's `entity_words.l_word` stamps to the
 *    same formula (the stamp source is the token; the old tokenizer never
 *    folded ё). Stamps are unique per (entity_id, l_word), so a recomputed
 *    key can only collide with another stamp of the same entity — two
 *    pre-fold spellings of one word: the counts fold into the
 *    already-correct stamp (or the lowest id), adopting a linked word when
 *    the survivor has none, and the loser deletes.
 * 6. Backfill the lemma form: every word carries itself (its stripped
 *    display, LemmaForms conventions) as a form where missing
 *    (~1.5M rows in dev) — insert-new-only through the `(form, word_id)`
 *    unique, chunked.
 *
 * The word merges share one set-based machinery: pairs land in a temp
 * mapping table, then every child move is one SQL statement per table —
 * definitions/etymologies/entity_words update in place, unique-keyed
 * children (forms/transcriptions/examples/pronunciations/tagged_words/
 * user_word/user_word_event/word_translation_fetches) move through
 * insert-select ON CONFLICT DO NOTHING + delete (collapsing onto the
 * survivor's existing rows and deduping within the map),
 * word_translations re-point through BOTH arms (canonical pairs, self-pairs
 * dropped), staged `words.translations` JSON union-merges, and the
 * frequency rank folds as LEAST (ranks: lower is more common — never
 * raises a rank, and the 0 "no rank" column default on cmudict/adoption
 * rows never folds onto a ranked survivor). The absorbed rows delete last;
 * children cascade. All tables touched through the explicit mapping ids —
 * never a wipe.
 *
 * Re-runnable by construction: every phase finds nothing on a second run.
 * Run --dry first — it prints every phase count without writing (child-move
 * counts are pre-database approximations where insert-time dedupe applies,
 * and phase-4 reclass counts include rows a real run would merge).
 */
class WordsNormalizeCommand extends Command
{
    protected $signature = 'words:normalize
        {--dry : Report every phase count without writing anything}
        {--chunk=20000 : Rows per scan pass (the display scans and the lemma-form backfill)}';

    protected $description = 'Repair existing dictionary rows to the purity rules in idempotent phases: strip stress marks from ru/be/uk display words and forms (merging plain twins), recompute l_word keys and merge duplicate (l_word, language, class) rows onto the fullest survivor, merge unknown-class twins, fold the adj class into adjective, recompute entity stamp keys, backfill lemma forms';

    private const STATEMENT_CHUNK = 1000;

    private const INSERT_CHUNK = 500;

    private bool $dry;

    /** @var array<string, bool> child table => present in the current schema */
    private array $childTables = [];

    /** @var array<string, int> */
    private array $stats = [
        'w_merged' => 0,
        'w_stripped' => 0,
        'w_marks_only' => 0,
        'f_collapsed' => 0,
        'f_stripped' => 0,
        'f_deduped' => 0,
        'f_marks_only' => 0,
        'kw_rekeyed' => 0,
        'kf_rekeyed' => 0,
        'dup_merged' => 0,
        'twins' => 0,
        'alias_merged' => 0,
        'alias_reclassed' => 0,
        'alias_classes_deleted' => 0,
        'ew_rekeyed' => 0,
        'ew_folded' => 0,
        'lemmas' => 0,
        'definitions' => 0,
        'etymologies' => 0,
        'forms' => 0,
        'transcriptions' => 0,
        'transcriptions_gated' => 0,
        'examples' => 0,
        'pronunciations' => 0,
        'entity_words' => 0,
        'tagged_words' => 0,
        'user_word' => 0,
        'user_word_event' => 0,
        'word_translation_fetches' => 0,
        'links_repointed' => 0,
        'links_self_dropped' => 0,
        'links_dedupe' => 0,
        'staged' => 0,
        'freq' => 0,
        'deleted' => 0,
    ];

    public function handle(): int
    {
        // The alias-fold group map holds every word row of the folded
        // classes (~200k en adj/adjective rows on the dev DB) and the twin
        // phases hold their merge maps — both blow past the 128M CLI
        // default on real data. Same ceiling the demote command raises.
        ini_set('memory_limit', '2G');
        set_time_limit(0);

        $this->dry = (bool) $this->option('dry');
        // The command instance is cached by the console kernel — a second
        // invocation in one process (tests, tinker) must not accumulate the
        // previous run's counts.
        $this->stats = array_fill_keys(array_keys($this->stats), 0);
        foreach (['definitions', 'etymologies', 'forms', 'transcriptions', 'examples', 'pronunciations', 'entity_words', 'tagged_words', 'user_word', 'user_word_event', 'word_translation_fetches'] as $table) {
            $this->childTables[$table] = Schema::hasTable($table);
        }

        DB::statement('SET statement_timeout = 0');
        try {
            $strippingLangIds = Language::query()->get(['id', 'code'])
                ->filter(fn (Language $language): bool => StressMarks::stripsFor($language->code))
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $this->stripStressedWords($strippingLangIds);
            $this->stripStressedForms($strippingLangIds);
            $this->rekeyAndMergeWords();
            $this->recomputeFormKeys();
            $this->mergeUnknownTwins();
            $this->mergeAliasClasses();
            $this->recomputeEntityStamps();
            $this->backfillLemmaForms();
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
            ['1. stressed words merged into plain twins', $this->stats['w_merged']],
            ['1. stressed words stripped in place', $this->stats['w_stripped']],
            ['1. marks-only word displays left alone', $this->stats['w_marks_only']],
            ['1. stressed forms collapsed onto plain twins', $this->stats['f_collapsed']],
            ['1. stressed forms stripped in place', $this->stats['f_stripped']],
            ['1. stressed form variants deduped', $this->stats['f_deduped']],
            ['1. marks-only form rows dropped', $this->stats['f_marks_only']],
            ['2. words.l_word keys recomputed', $this->stats['kw_rekeyed']],
            ['2. forms.l_word keys recomputed', $this->stats['kf_rekeyed']],
            ['2. duplicate rows merged per (l_word, language, class)', $this->stats['dup_merged']],
            ['3. unknown-class twins merged', $this->stats['twins']],
            ['4. alias-class words merged into canonical twins', $this->stats['alias_merged']],
            ['4. alias-class words reclassed in place', $this->stats['alias_reclassed']],
            ['4. alias word classes deleted', $this->stats['alias_classes_deleted']],
            ['5. entity_words.l_word stamps recomputed', $this->stats['ew_rekeyed']],
            ['5. entity stamp duplicates collapsed', $this->stats['ew_folded']],
            ['6. lemma forms backfilled', $this->stats['lemmas']],
            ['Merge children — definitions/etymologies moved', $this->stats['definitions'] + $this->stats['etymologies']],
            ['Merge children — forms moved', $this->stats['forms']],
            ['Merge children — transcriptions moved', $this->stats['transcriptions']],
            ['Merge children — transcriptions dropped (survivor has stressed IPA)', $this->stats['transcriptions_gated']],
            ['Merge children — examples moved', $this->stats['examples']],
            ['Merge children — pronunciations moved', $this->stats['pronunciations']],
            ['Merge children — entity_words re-pointed', $this->stats['entity_words']],
            ['Merge children — tagged_words moved', $this->stats['tagged_words']],
            ['Merge children — user_word rows re-pointed', $this->stats['user_word']],
            ['Merge children — user_word_event rows re-pointed', $this->stats['user_word_event']],
            ['Merge children — word_translation_fetches moved', $this->stats['word_translation_fetches']],
            ['Merge links — translation links re-pointed', $this->stats['links_repointed']],
            ['Merge links — self-pairs dropped', $this->stats['links_self_dropped']],
            ['Merge links — deduped onto existing pairs', $this->stats['links_dedupe']],
            ['Merge — staged translation rows merged', $this->stats['staged']],
            ['Merge — survivors re-ranked (LEAST fold)', $this->stats['freq']],
            ['Absorbed word rows deleted', $this->stats['deleted']],
        ]);

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------
    // Phase 1a — display words (ru/be/uk)
    // ------------------------------------------------------------------

    /**
     * @param  list<int>  $langIds
     */
    private function stripStressedWords(array $langIds): void
    {
        $this->info('Phase 1 — stress marks in display words...');
        $bounds = $this->idBounds('words', $langIds);
        if ($bounds === null) {
            return;
        }

        $candidates = [];
        $this->scanChunked(
            'words as w',
            'w.id',
            $bounds,
            fn () => DB::table('words as w')->whereIn('w.language_id', $langIds),
            function (object $row) use (&$candidates): void {
                $stripped = StressMarks::strip($row->word);
                if ($stripped === $row->word) {
                    return;
                }
                if ($stripped === '') {
                    $this->stats['w_marks_only']++;

                    return; // a marks-only display stores no twin; left alone
                }
                $candidates[(int) $row->id] = [
                    'language_id' => (int) $row->language_id,
                    'word_class_id' => (int) $row->word_class_id,
                    'stripped' => $stripped,
                ];
            },
        );
        if ($candidates === []) {
            return;
        }

        // Plain twins: stored words equal to a stripped candidate value under
        // the same (language, class) — the words unique key.
        $twins = [];
        $byClass = [];
        foreach ($candidates as $candidate) {
            $byClass[$candidate['language_id'].'|'.$candidate['word_class_id']][$candidate['stripped']] = true;
        }
        foreach ($byClass as $key => $values) {
            [$langId, $classId] = explode('|', $key);
            foreach (array_chunk(array_keys($values), self::STATEMENT_CHUNK) as $chunkValues) {
                $rows = Word::query()
                    ->where('language_id', (int) $langId)
                    ->where('word_class_id', (int) $classId)
                    ->whereIn('word', $chunkValues)
                    ->get(['id', 'word']);
                foreach ($rows as $row) {
                    $twins[$langId.'|'.$classId.'|'.$row->word] = (int) $row->id;
                }
            }
        }

        $pairs = [];
        $strips = [];
        $groups = [];
        foreach ($candidates as $id => $candidate) {
            $groups[$candidate['language_id'].'|'.$candidate['word_class_id'].'|'.$candidate['stripped']][] = $id;
        }
        foreach ($groups as $groupIds) {
            $keep = min($groupIds);
            foreach ($groupIds as $id) {
                $candidate = $candidates[$id];
                $twin = $twins[$candidate['language_id'].'|'.$candidate['word_class_id'].'|'.$candidate['stripped']] ?? null;
                if ($twin !== null) {
                    $pairs[] = [$id, $twin];
                } elseif ($id === $keep) {
                    $strips[] = [$id, $candidate['stripped'], WordLookupKey::normalize($candidate['stripped'])];
                } else {
                    // Two stressed variants stripping to one plain string that
                    // is not stored: the lowest id survives, the rest merge.
                    $pairs[] = [$id, $keep];
                }
            }
        }

        $this->beginMergeMap($pairs);
        $this->applyMerge();
        $this->stats['w_merged'] += count($pairs);
        $this->stats['w_stripped'] += count($strips);

        if ($strips !== [] && ! $this->dry) {
            foreach (array_chunk($strips, self::STATEMENT_CHUNK) as $chunk) {
                [$placeholders, $bindings] = $this->valuesPlaceholders($chunk);
                DB::affectingStatement(
                    'update words as w set word = v.plain::varchar, l_word = v.lw::varchar'
                    .' from (values '.$placeholders.') as v(word_id, plain, lw)'
                    .' where w.id = v.word_id::bigint',
                    $bindings,
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // Phase 1b — display forms (ru/be/uk)
    // ------------------------------------------------------------------

    /**
     * @param  list<int>  $langIds
     */
    private function stripStressedForms(array $langIds): void
    {
        $this->info('Phase 1 — stress marks in display forms...');
        $bounds = $this->idBounds('forms', $langIds, 'language_id', 'words');
        if ($bounds === null) {
            return;
        }

        $candidates = [];
        $this->scanChunked(
            'forms as f',
            'f.id',
            $bounds,
            // Explicit select: the join's unqualified id would collide with
            // words.id in the fetched row.
            fn () => DB::table('forms as f')
                ->join('words as w', 'w.id', '=', 'f.word_id')
                ->whereIn('w.language_id', $langIds)
                ->select('f.id as scan_id', 'f.word_id', 'f.form'),
            function (object $row) use (&$candidates): void {
                $stripped = StressMarks::strip($row->form);
                if ($stripped === $row->form) {
                    return;
                }
                $candidates[(int) $row->scan_id] = [
                    'word_id' => (int) $row->word_id,
                    'form' => $row->form,
                    'stripped' => $stripped,
                ];
            },
        );
        if ($candidates === []) {
            return;
        }

        // Plain twins already stored per (word, stripped form) — the forms
        // unique key. A marks-only artifact drops outright (it stores
        // nothing, per the LemmaForms rule).
        $plainExists = [];
        $byWord = [];
        foreach ($candidates as $id => $candidate) {
            if ($candidate['stripped'] === '') {
                $this->stats['f_marks_only']++;
                $candidates[$id]['marks_only'] = true;

                continue;
            }
            $byWord[$candidate['word_id']][$candidate['stripped']] = true;
        }
        foreach (array_chunk(array_keys($byWord), self::STATEMENT_CHUNK) as $wordIds) {
            $values = [];
            foreach ($wordIds as $wordId) {
                foreach (array_keys($byWord[$wordId]) as $stripped) {
                    $values[$stripped] = true;
                }
            }
            $rows = DB::table('forms')
                ->whereIn('word_id', $wordIds)
                ->whereIn('form', array_keys($values))
                ->get(['word_id', 'form']);
            foreach ($rows as $row) {
                $plainExists[$row->word_id.'|'.$row->form] = true;
            }
        }

        $collapse = []; // the group's plain twin pre-exists: every variant dies
        $dedupe = []; // extra variants of one strip group: die, lowest id survives
        $keeps = []; // [form_id, plain, l_word]
        $groups = [];
        foreach ($candidates as $id => $candidate) {
            if (isset($candidate['marks_only'])) {
                $collapse[] = $id;

                continue;
            }
            $groups[$candidate['word_id'].'|'.$candidate['stripped']][] = $id;
        }
        foreach ($groups as $key => $groupIds) {
            [$wordId, $stripped] = explode('|', $key, 2);
            if (isset($plainExists[$key])) {
                foreach ($groupIds as $id) {
                    $collapse[] = $id;
                }

                continue;
            }
            sort($groupIds);
            $keeps[] = [array_shift($groupIds), $stripped, WordLookupKey::normalize($stripped)];
            foreach ($groupIds as $id) {
                $dedupe[] = $id;
            }
        }

        $this->stats['f_collapsed'] += count($collapse);
        $this->stats['f_deduped'] += count($dedupe);
        $this->stats['f_stripped'] += count($keeps);
        if ($this->dry) {
            return;
        }

        foreach (array_chunk(array_merge($collapse, $dedupe), self::STATEMENT_CHUNK) as $ids) {
            DB::table('forms')->whereIn('id', $ids)->delete();
        }
        foreach (array_chunk($keeps, self::STATEMENT_CHUNK) as $chunk) {
            [$placeholders, $bindings] = $this->valuesPlaceholders($chunk);
            DB::affectingStatement(
                'update forms as f set form = v.plain::varchar, l_word = v.lw::varchar'
                .' from (values '.$placeholders.') as v(form_id, plain, lw)'
                .' where f.id = v.form_id::bigint',
                $bindings,
            );
        }
    }

    // ------------------------------------------------------------------
    // Phase 2 — l_word recompute + duplicate merge
    // ------------------------------------------------------------------

    /**
     * Recompute every `words.l_word` to the current WordLookupKey formula,
     * then merge the duplicate (l_word, language_id, word_class_id) rows the
     * recompute exposes. Detection runs on the effective (post-recompute)
     * keys — a stale-key row folding onto an existing key is a duplicate
     * pair the stored keys alone cannot show — and the key updates apply
     * only after the merge, so under either schema an update never collides
     * with the words unique it is meant to satisfy.
     */
    private function rekeyAndMergeWords(): void
    {
        $this->info('Phase 2 — words.l_word recompute + duplicate merge...');
        $bounds = $this->idBounds('words');
        if ($bounds === null) {
            return;
        }

        DB::statement('drop table if exists rekey_map');
        DB::statement('create temp table rekey_map (word_id bigint primary key, new_key varchar)');

        // Phase 1's merges have not applied in a dry run — their absorbed
        // rows are still stored. A real run never sees them here, so the
        // detection must skip them too, or the dry counts would drift from
        // the real run's. The snapshot lives until the forms recompute.
        $this->snapshotAbsorbedWords();

        $found = $this->scanKeySlices('words', 'word', $bounds, function (array $tuples): void {
            $this->insertTempTuples('rekey_map', 'word_id, new_key', $tuples);
        });
        if ($found === 0) {
            DB::statement('drop table if exists rekey_map');

            return;
        }
        DB::statement('delete from rekey_map r using absorbed_earlier a where r.word_id = a.absorbed_id');

        // The key each row carries after the recompute: changed rows carry
        // their target, the rest their stored key.
        DB::statement('drop table if exists eff_keys');
        DB::statement(
            'create temp table eff_keys as'
            .' select w.id, w.language_id, w.word_class_id, coalesce(r.new_key, w.l_word) as eff_key'
            .' from words w left join rekey_map r on r.word_id = w.id'
            .' where (w.l_word is not null or r.word_id is not null)'
            .'   and not exists (select 1 from absorbed_earlier a where a.absorbed_id = w.id)',
        );
        DB::statement('drop table if exists dup_groups');
        DB::statement(
            'create temp table dup_groups as'
            .' select eff_key, language_id, word_class_id from eff_keys'
            .' group by eff_key, language_id, word_class_id having count(*) > 1',
        );

        // Survivor preference: a display containing ё first (the fullest
        // spelling survives — the user edits the display later), then most
        // definitions, then the lowest id.
        DB::statement('drop table if exists dup_survivors');
        DB::statement(
            'create temp table dup_survivors as'
            .' select distinct on (k.eff_key, k.language_id, k.word_class_id)'
            .'   k.eff_key, k.language_id, k.word_class_id, k.id as survivor_id'
            .' from eff_keys k'
            .' join dup_groups d on d.eff_key = k.eff_key'
            .'   and d.language_id = k.language_id and d.word_class_id = k.word_class_id'
            .' join words w on w.id = k.id'
            .' order by k.eff_key, k.language_id, k.word_class_id,'
            .'   (case when position(? in w.word) + position(? in w.word) > 0 then 0 else 1 end),'
            .'   (select count(*) from definitions df where df.word_id = w.id) desc,'
            .'   k.id',
            ['ё', 'Ё'],
        );

        $this->beginMergeMap();
        DB::statement(
            'insert into merge_map (absorbed_id, survivor_id)'
            .' select k.id, s.survivor_id from eff_keys k'
            .' join dup_groups d on d.eff_key = k.eff_key'
            .'   and d.language_id = k.language_id and d.word_class_id = k.word_class_id'
            .' join dup_survivors s on s.eff_key = k.eff_key'
            .'   and s.language_id = k.language_id and s.word_class_id = k.word_class_id'
            .' where k.id <> s.survivor_id',
        );
        $this->stats['dup_merged'] += $this->mergeMapCount();
        $this->applyMerge();

        // The absorbed rows' updates die with them; every remaining target
        // key is now held by exactly one row (its group was merged away or
        // was never a group), so the updates are collision-free.
        DB::statement('delete from rekey_map r using merge_map m where r.word_id = m.absorbed_id');
        $this->stats['kw_rekeyed'] += (int) DB::selectOne('select count(*) as n from rekey_map')->n;
        if (! $this->dry) {
            $this->applyRekeyMap('rekey_map', 'word_id', 'words');
        }

        foreach (['rekey_map', 'eff_keys', 'dup_groups', 'dup_survivors'] as $table) {
            DB::statement('drop table if exists '.$table);
        }
    }

    /**
     * The forms side of the recompute: forms carry no unique on l_word, so
     * the recomputed keys apply directly.
     */
    private function recomputeFormKeys(): void
    {
        $this->info('Phase 2 — forms.l_word recompute...');
        $bounds = $this->idBounds('forms');
        if ($bounds === null) {
            return;
        }

        // The forms of rows phase 1 has already absorbed take no part: a
        // real run moved or deduped them before this phase reads.
        $this->stats['kf_rekeyed'] += $this->scanKeySlices('forms', 'form', $bounds, function (array $tuples): void {
            if ($this->dry) {
                return;
            }
            foreach (array_chunk($tuples, self::STATEMENT_CHUNK) as $batch) {
                [$placeholders, $bindings] = $this->valuesPlaceholders($batch);
                DB::affectingStatement(
                    'update forms as f set l_word = v.lw::varchar'
                    .' from (values '.$placeholders.') as v(row_id, lw)'
                    .' where f.id = v.row_id::bigint',
                    $bindings,
                );
            }
        }, '(select 1 from absorbed_earlier a where a.absorbed_id = t.word_id)');
        DB::statement('drop table if exists absorbed_earlier');
    }

    /**
     * Chunked id-range scan over a table's rekey candidates — rows whose
     * WordLookupKey differs from the stored l_word — feeding each slice's
     * tuples to the handler, so memory stays at one slice. A computed key of
     * '' (a marks-only or format-only display) is skipped: those rows stay
     * unkeyed, like phase 1 leaves them. $excludeSql, when given, is a
     * NOT EXISTS subquery over the row alias "t" naming rows to leave alone.
     *
     * @param  array{0: int, 1: int}  $bounds
     * @param  callable(list<array{int, string}>): void  $each
     */
    private function scanKeySlices(string $table, string $sourceColumn, array $bounds, callable $each, ?string $excludeSql = null): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $batches = (int) ceil(($bounds[1] - $bounds[0] + 1) / $chunk);
        $bar = $this->scanBar($batches);

        $found = 0;
        for ($start = $bounds[0]; $start <= $bounds[1]; $start += $chunk) {
            $tuples = $this->rekeyTuples($table, $sourceColumn, $start, min($start + $chunk - 1, $bounds[1]), $excludeSql);
            $found += count($tuples);
            if ($tuples !== []) {
                $each($tuples);
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        return $found;
    }

    /**
     * The rekey candidates of one id-range slice: [id, new_key] tuples.
     *
     * @return list<array{int, string}>
     */
    private function rekeyTuples(string $table, string $sourceColumn, int $start, int $end, ?string $excludeSql = null): array
    {
        $tuples = [];
        $rows = DB::table($table.' as t')
            ->whereBetween('t.id', [$start, $end])
            ->when($excludeSql !== null, fn ($query) => $query->whereRaw('not exists '.$excludeSql))
            ->get(['t.id', 't.'.$sourceColumn, 't.l_word']);
        foreach ($rows as $row) {
            $key = WordLookupKey::normalize((string) $row->{$sourceColumn});
            if ($key === '' || $key === $row->l_word) {
                continue;
            }
            $tuples[] = [(int) $row->id, $key];
        }

        return $tuples;
    }

    /**
     * The rows the earlier phases' merges have absorbed. A real run deletes
     * them before the later phases read; a dry run keeps them stored — the
     * snapshot is what keeps the dry counts identical to the real run's.
     * The current merge_map may not exist (phase 1 early-returns before
     * creating it when it finds nothing), and Schema::hasTable cannot see
     * temp tables — to_regclass resolves the session's temp schema first.
     */
    private function snapshotAbsorbedWords(): void
    {
        DB::statement('drop table if exists absorbed_earlier');
        $mergeMapExists = (bool) DB::selectOne("select to_regclass('merge_map') is not null as e")->e;
        DB::statement($mergeMapExists
            ? 'create temp table absorbed_earlier as select absorbed_id from merge_map'
            : 'create temp table absorbed_earlier as select 0::bigint as absorbed_id where false');
    }

    /**
     * Apply a temp rekey map to its table in statement-sized keyset chunks.
     */
    private function applyRekeyMap(string $map, string $mapIdColumn, string $table): void
    {
        $lastId = 0;
        do {
            $pairs = DB::table($map)
                ->where($mapIdColumn, '>', $lastId)
                ->orderBy($mapIdColumn)
                ->limit(self::STATEMENT_CHUNK)
                ->get();
            if ($pairs->isEmpty()) {
                break;
            }
            [$placeholders, $bindings] = $this->valuesPlaceholders($pairs->map(
                fn (object $row): array => [(int) $row->{$mapIdColumn}, $row->new_key],
            )->all());
            DB::affectingStatement(
                "update {$table} as t set l_word = v.lw::varchar"
                .' from (values '.$placeholders.') as v(row_id, lw)'
                .' where t.id = v.row_id::bigint',
                $bindings,
            );
            $lastId = (int) $pairs->last()->{$mapIdColumn};
        } while ($pairs->count() === self::STATEMENT_CHUNK);
    }

    private function scanBar(int $batches, string $message = 'Scanning...'): ProgressBar
    {
        $bar = $this->output->createProgressBar($batches);
        $bar->setFormat('[%current%/%max% passes] [%elapsed%] %message%');
        $bar->setMessage($message);
        $bar->start();

        return $bar;
    }

    // ------------------------------------------------------------------
    // Phase 3 — unknown-class twins
    // ------------------------------------------------------------------

    private function mergeUnknownTwins(): void
    {
        $this->info('Phase 3 — unknown-class twins...');
        $this->beginMergeMap();
        DB::statement(
            'insert into merge_map (absorbed_id, survivor_id)'
            .' select u.id, s.sid from words u'
            .' join word_classes uc on uc.id = u.word_class_id and uc.slug = ?'
            .' join ('
            .'   select c.language_id, c.l_word, min(c.id) as sid from words c'
            .'   join word_classes cc on cc.id = c.word_class_id and cc.slug <> ?'
            .'   where c.l_word is not null'
            .'   group by c.language_id, c.l_word'
            .' ) s on s.language_id = u.language_id and s.l_word = u.l_word'
            .' where u.l_word is not null',
            ['unknown', 'unknown'],
        );
        $this->stats['twins'] = $this->mergeMapCount();
        $this->applyMerge();
    }

    // ------------------------------------------------------------------
    // Phase 4 — alias word classes (adj → adjective)
    // ------------------------------------------------------------------

    private function mergeAliasClasses(): void
    {
        $this->info('Phase 4 — alias word classes...');
        $aliases = WordClass::query()
            ->get()
            ->filter(fn (WordClass $class): bool => ($canonical = PosAliases::canonical($class->slug)) !== null
                && $canonical !== $class->slug);
        if ($aliases->isEmpty()) {
            return;
        }

        /** @var array<string, list<array{int, int}>> $perCanonical canonical slug => [[alias class id, language id]] */
        $perCanonical = [];
        foreach ($aliases as $alias) {
            $perCanonical[PosAliases::canonical($alias->slug)][] = [(int) $alias->id, (int) $alias->language_id];
        }

        foreach ($perCanonical as $canonical => $classRows) {
            $byLanguage = [];
            foreach ($classRows as [$classId, $langId]) {
                $byLanguage[$langId][] = $classId;
            }
            foreach ($byLanguage as $langId => $aliasIds) {
                $target = WordClass::query()
                    ->where('language_id', $langId)
                    ->where('slug', $canonical)
                    ->first();
                if ($target === null) {
                    if ($this->dry) {
                        // No canonical class: no twin can exist — every alias
                        // word would reclass (creating the class) and the
                        // alias rows would drop.
                        $this->stats['alias_reclassed'] += DB::table('words')->whereIn('word_class_id', $aliasIds)->count();
                        $this->stats['alias_classes_deleted'] += count($aliasIds);

                        continue;
                    }
                    $target = WordClass::query()->create([
                        'language_id' => $langId,
                        'slug' => $canonical,
                        'title' => ucfirst($canonical),
                    ]);
                }
                $targetId = (int) $target->id;

                // Twin merges: an alias word whose lookup key exists in the
                // canonical class merges into the lowest-id canonical row.
                // The join keys on l_word — the identity under the
                // (l_word, language, class) unique — so a case/ё variant
                // twin folds too; a display-exact join would leave it
                // twinless and the reclass below would collide with the
                // unique key.
                $this->beginMergeMap();
                DB::statement(
                    'insert into merge_map (absorbed_id, survivor_id)'
                    .' select a.id, s.sid from words a'
                    .' join ('
                    .'   select c.language_id, c.l_word, min(c.id) as sid from words c'
                    .'   where c.word_class_id = ? and c.l_word is not null'
                    .'   group by c.language_id, c.l_word'
                    .' ) s on s.language_id = a.language_id and s.l_word = a.l_word'
                    .' where a.l_word is not null and a.word_class_id in ('.implode(', ', array_fill(0, count($aliasIds), '?')).')',
                    [$targetId, ...$aliasIds],
                );
                $this->stats['alias_merged'] += $this->mergeMapCount();
                $this->applyMerge();

                // Twinless leftovers: collapse same-display rows among
                // themselves (two alias classes of one canonical target),
                // then reclass every survivor in place — unique-safe, none of
                // them has a canonical-class twin any more.
                $pairs = $this->resolveLeftoverAliasWords($aliasIds);
                if ($pairs !== []) {
                    $this->beginMergeMap($pairs);
                    $this->applyMerge();
                }
                $this->stats['alias_merged'] += count($pairs);
                if ($this->dry) {
                    // Approximation: includes rows a real run merged above.
                    $this->stats['alias_reclassed'] += DB::table('words')->whereIn('word_class_id', $aliasIds)->count();
                    $this->stats['alias_classes_deleted'] += count($aliasIds);
                } else {
                    $this->stats['alias_reclassed'] += DB::affectingStatement(
                        'update words set word_class_id = ? where word_class_id in ('.implode(', ', array_fill(0, count($aliasIds), '?')).')',
                        [$targetId, ...$aliasIds],
                    );
                    $this->stats['alias_classes_deleted'] += WordClass::query()->whereIn('id', $aliasIds)->delete();
                }
            }
        }
    }

    /**
     * The alias-class words with no canonical twin: the lowest id of each
     * l_word group survives (to reclass), the rest merge onto it. NULL-l_word
     * rows group by their display instead (NULLs never collide with the
     * unique).
     *
     * @param  list<int>  $aliasIds
     * @return list<array{int, int}> merge pairs [absorbed, survivor]
     */
    private function resolveLeftoverAliasWords(array $aliasIds): array
    {
        $bounds = $this->idBounds('words', $aliasIds, 'word_class_id');
        if ($bounds === null) {
            return [];
        }

        $groups = [];
        $this->scanChunked(
            'words as w',
            'w.id',
            $bounds,
            fn () => DB::table('words as w')->whereIn('w.word_class_id', $aliasIds),
            function (object $row) use (&$groups): void {
                // Rows group by their lookup key (the identity the unique
                // index and the reclass see). A NULL l_word never conflicts
                // with the unique, so those rows group by display instead —
                // different words must not collapse.
                $groups[(int) $row->language_id.'|'.($row->l_word ?? 'w:'.$row->word)][] = (int) $row->id;
            },
        );

        $pairs = [];
        foreach ($groups as $groupIds) {
            sort($groupIds);
            $keep = array_shift($groupIds);
            foreach ($groupIds as $id) {
                $pairs[] = [$id, $keep];
            }
        }

        return $pairs;
    }

    // ------------------------------------------------------------------
    // Phase 5 — entity stamp recompute
    // ------------------------------------------------------------------

    /**
     * Recompute the entity tokenizer's `entity_words.l_word` stamps to the
     * current WordLookupKey formula (the stamp source is the token; the old
     * tokenizer never folded ё). Stamps are unique per (entity_id, l_word),
     * so a recomputed key can only collide with another stamp of the same
     * entity — two pre-fold spellings of one word: the counts fold into the
     * already-correct stamp (or the lowest id), adopting a linked word when
     * the survivor has none, and the loser deletes.
     */
    private function recomputeEntityStamps(): void
    {
        if (! $this->hasChild('entity_words')) {
            return;
        }
        $this->info('Phase 5 — entity_words.l_word recompute...');
        $bounds = $this->idBounds('entity_words');
        if ($bounds === null) {
            return;
        }

        DB::statement('drop table if exists rekey_ew');
        DB::statement('create temp table rekey_ew (entity_word_id bigint primary key, new_key varchar)');
        $found = $this->scanKeySlices('entity_words', 'token', $bounds, function (array $tuples): void {
            $this->insertTempTuples('rekey_ew', 'entity_word_id, new_key', $tuples);
        });
        if ($found === 0) {
            DB::statement('drop table if exists rekey_ew');

            return;
        }

        // The effective keys of the changed rows plus any stamp already
        // holding a targeted key. Stored keys alone are unique — the
        // (entity_id, l_word) unique guarantees that — so only recomputed
        // keys can collide, and only within one entity.
        DB::statement('drop table if exists ew_eff');
        DB::statement(
            'create temp table ew_eff as'
            .' select e.id, e.entity_id, r.new_key as eff_key, false as is_holder'
            .' from entity_words e join rekey_ew r on r.entity_word_id = e.id'
            .' union all'
            .' select e.id, e.entity_id, e.l_word, true'
            .' from entity_words e'
            .' where not exists (select 1 from rekey_ew r where r.entity_word_id = e.id)'
            .'   and e.l_word in (select new_key from rekey_ew)',
        );
        DB::statement('drop table if exists ew_groups');
        DB::statement(
            'create temp table ew_groups as'
            .' select entity_id, eff_key from ew_eff'
            .' group by entity_id, eff_key having count(*) > 1',
        );
        DB::statement('drop table if exists ew_survivors');
        DB::statement(
            'create temp table ew_survivors as'
            .' select distinct on (k.entity_id, k.eff_key) k.entity_id, k.eff_key, k.id as survivor_id'
            .' from ew_eff k'
            .' join ew_groups g on g.entity_id = k.entity_id and g.eff_key = k.eff_key'
            .' order by k.entity_id, k.eff_key, k.is_holder desc, k.id',
        );

        $absorbed = 'from ew_eff k'
            .' join ew_groups g on g.entity_id = k.entity_id and g.eff_key = k.eff_key'
            .' join ew_survivors m on m.entity_id = k.entity_id and m.eff_key = k.eff_key'
            .' where k.id <> m.survivor_id';
        $this->stats['ew_folded'] += (int) DB::selectOne('select count(*) as n '.$absorbed)->n;
        if (! $this->dry) {
            DB::affectingStatement(
                'update entity_words s'
                .' set count = s.count + f.folded, word_id = coalesce(s.word_id, f.word_id), updated_at = now()'
                .' from ('
                .'   select m.survivor_id, sum(e.count)::int as folded, min(e.word_id) as word_id'
                .'   from entity_words e'
                .'   join ew_eff k on k.id = e.id'
                .'   join ew_groups g on g.entity_id = k.entity_id and g.eff_key = k.eff_key'
                .'   join ew_survivors m on m.entity_id = k.entity_id and m.eff_key = k.eff_key'
                .'   where k.id <> m.survivor_id'
                .'   group by m.survivor_id'
                .' ) f'
                .' where s.id = f.survivor_id',
            );
            DB::affectingStatement(
                'delete from entity_words e'
                .' using ew_eff k'
                .' join ew_groups g on g.entity_id = k.entity_id and g.eff_key = k.eff_key'
                .' join ew_survivors m on m.entity_id = k.entity_id and m.eff_key = k.eff_key'
                .' where e.id = k.id and k.id <> m.survivor_id',
            );
        }

        // The absorbed stamps' updates die with them; every remaining target
        // key is now held by exactly one stamp of its entity.
        DB::statement(
            'delete from rekey_ew r'
            .' using ew_eff k'
            .' join ew_groups g on g.entity_id = k.entity_id and g.eff_key = k.eff_key'
            .' join ew_survivors m on m.entity_id = k.entity_id and m.eff_key = k.eff_key'
            .' where r.entity_word_id = k.id and k.id <> m.survivor_id',
        );
        $this->stats['ew_rekeyed'] += (int) DB::selectOne('select count(*) as n from rekey_ew')->n;
        if (! $this->dry) {
            $this->applyRekeyMap('rekey_ew', 'entity_word_id', 'entity_words');
        }

        foreach (['rekey_ew', 'ew_eff', 'ew_groups', 'ew_survivors'] as $table) {
            DB::statement('drop table if exists '.$table);
        }
    }

    // ------------------------------------------------------------------
    // Phase 6 — lemma-form backfill
    // ------------------------------------------------------------------

    private function backfillLemmaForms(): void
    {
        $this->info('Phase 6 — lemma forms...');
        $strips = Language::query()->get(['id', 'code'])
            ->mapWithKeys(fn (Language $language): array => [(int) $language->id => StressMarks::stripsFor($language->code)]);
        $bounds = $this->idBounds('words');
        if ($bounds === null) {
            return;
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $batches = (int) ceil(($bounds[1] - $bounds[0] + 1) / $chunk);
        $bar = $this->output->createProgressBar($batches);
        $bar->setFormat('[%current%/%max% passes] [%elapsed%] %message%');
        $bar->setMessage('Backfilling...');
        $bar->start();

        $inserted = 0;
        for ($start = $bounds[0]; $start <= $bounds[1]; $start += $chunk) {
            $rows = DB::table('words')
                ->whereBetween('id', [$start, min($start + $chunk - 1, $bounds[1])])
                ->get(['id', 'word', 'language_id']);
            $tuples = [];
            foreach ($rows as $row) {
                $lemma = LemmaForms::rows($row->word, [], (int) $row->id, $strips[$row->language_id] ?? false);
                if ($lemma === []) {
                    continue; // a marks-only display stores nothing
                }
                $tuples[] = [$lemma[0]['form'], $lemma[0]['l_word'], $lemma[0]['word_id']];
            }
            if ($tuples !== []) {
                if ($this->dry) {
                    $inserted += $this->countMissingForms($tuples);
                } else {
                    foreach (array_chunk($tuples, self::INSERT_CHUNK) as $batch) {
                        $stamp = now();
                        $inserted += DB::table('forms')->insertOrIgnore(array_map(
                            fn (array $tuple): array => [
                                'form' => $tuple[0],
                                'l_word' => $tuple[1],
                                'word_id' => $tuple[2],
                                'created_at' => $stamp,
                                'updated_at' => $stamp,
                            ],
                            $batch,
                        ));
                    }
                }
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $this->stats['lemmas'] += $inserted;
    }

    /**
     * How many of the (form, l_word, word_id) tuples are missing from forms —
     * the dry-run twin of the insert-or-ignore.
     *
     * @param  list<array{string, string, int}>  $tuples
     */
    private function countMissingForms(array $tuples): int
    {
        [$placeholders, $bindings] = $this->valuesPlaceholders($tuples);

        return (int) DB::selectOne(
            'select count(*) as n from (values '.$placeholders.') as v(form, lw, word_id)'
            .' where not exists (select 1 from forms f where f.word_id = v.word_id::bigint and f.form = v.form)',
            $bindings,
        )->n;
    }

    // ------------------------------------------------------------------
    // Shared merge machinery (temp merge_map -> set-based child moves)
    // ------------------------------------------------------------------

    /**
     * @param  list<array{int, int}>  $pairs  [absorbed word id, survivor word id]
     */
    private function beginMergeMap(array $pairs = []): void
    {
        DB::statement('drop table if exists merge_map');
        DB::statement('create temp table merge_map (absorbed_id bigint, survivor_id bigint)');
        foreach (array_chunk($pairs, self::STATEMENT_CHUNK) as $chunk) {
            [$placeholders, $bindings] = $this->valuesPlaceholders($chunk);
            DB::statement(
                'insert into merge_map (absorbed_id, survivor_id) values '.$placeholders,
                $bindings,
            );
        }
    }

    private function mergeMapCount(): int
    {
        return (int) DB::selectOne('select count(*) as n from merge_map')->n;
    }

    private function hasChild(string $table): bool
    {
        return $this->childTables[$table] ?? false;
    }

    /**
     * Apply the temp merge_map: children first, the absorbed word rows last
     * (the delete cascades whatever a table-existence guard skipped).
     */
    private function applyMerge(): void
    {
        if ($this->mergeMapCount() === 0) {
            return;
        }

        $this->movePlainChild('definitions');
        $this->movePlainChild('etymologies');
        $this->moveTranscriptions();
        $this->moveUniqueChild('forms', ['form', 'l_word'], ['form']);
        $this->moveUniqueChild('examples', ['example'], ['example']);
        $this->moveUniqueChild('pronunciations', ['path'], ['path']);
        $this->relinkWordTranslations();
        $this->mergeStagedTranslations();
        $this->foldFrequency();
        $this->repointEntityWords();
        $this->moveUniqueChild('tagged_words', ['tag_id'], ['tag_id']);
        $this->moveUniqueChild('user_word', ['user_id', 'familiarity'], ['user_id']);
        $this->moveUniqueChild('user_word_event', ['user_id', 'row_key', 'kind'], ['user_id', 'row_key', 'kind']);
        $this->moveFetchLedger();
        $this->deleteAbsorbedWords();
    }

    /**
     * A child table with no unique key beyond its word reference: every row
     * moves in place.
     */
    private function movePlainChild(string $table): void
    {
        if (! $this->hasChild($table)) {
            return;
        }
        if ($this->dry) {
            $this->stats[$table] += (int) DB::selectOne(
                "select count(*) as n from {$table} d join merge_map m on d.word_id = m.absorbed_id",
            )->n;

            return;
        }
        $this->stats[$table] += DB::affectingStatement(
            "update {$table} d set word_id = m.survivor_id from merge_map m where d.word_id = m.absorbed_id",
        );
    }

    /**
     * A child table whose unique key spans word_id plus identity columns:
     * the move is insert-select ON CONFLICT DO NOTHING (collapsing onto the
     * survivor's existing rows and deduping within the map) + delete of the
     * originals. The conflict target lists the unique key's columns — Postgres
     * matches it as a set, so order beside word_id is free.
     *
     * @param  list<string>  $carryColumns  copied verbatim onto the survivor row
     * @param  list<string>  $identityColumns  the unique key's columns beside word_id
     */
    private function moveUniqueChild(string $table, array $carryColumns, array $identityColumns): void
    {
        if (! $this->hasChild($table)) {
            return;
        }
        $dupe = implode(' and ', array_map(fn (string $column): string => "s.{$column} = c.{$column}", $identityColumns));
        if ($this->dry) {
            $this->stats[$table] += (int) DB::selectOne(
                "select count(*) as n from {$table} c join merge_map m on c.word_id = m.absorbed_id"
                ." where not exists (select 1 from {$table} s where s.word_id = m.survivor_id and {$dupe})",
            )->n;

            return;
        }
        $columns = array_merge(['word_id'], $carryColumns, ['created_at', 'updated_at']);
        $selects = array_merge(['m.survivor_id'], array_map(fn (string $column): string => "c.{$column}", $carryColumns), ['c.created_at', 'c.updated_at']);
        $moved = (int) DB::selectOne(
            'with ins as ('
            ." insert into {$table} (".implode(', ', $columns).')'
            .' select '.implode(', ', $selects)
            ." from {$table} c join merge_map m on c.word_id = m.absorbed_id"
            .' on conflict ('.implode(', ', array_merge(['word_id'], $identityColumns)).') do nothing'
            .' returning 1) select count(*) as n from ins'
        )->n;
        DB::affectingStatement(
            "delete from {$table} c using merge_map m where c.word_id = m.absorbed_id",
        );
        $this->stats[$table] += $moved;
    }

    /**
     * Transcriptions move under the cmudict convention: a survivor already
     * carrying a stress-marked (ˈ) IPA transcription is final — the absorbed
     * row's transcriptions die with the merge instead of stacking on top
     * (Kaikki wins where it has stress data).
     */
    private function moveTranscriptions(): void
    {
        if (! $this->hasChild('transcriptions')) {
            return;
        }
        $gate = 'exists (select 1 from transcriptions g'
            .' join transcription_types gt on gt.id = g.transcription_type_id'
            .' where g.word_id = m.survivor_id and gt.slug = ? and g.transcription like ?)';
        $gateBindings = ['ipa', '%ˈ%'];
        if ($this->dry) {
            $total = (int) DB::selectOne(
                'select count(*) as n from transcriptions t join merge_map m on t.word_id = m.absorbed_id',
            )->n;
            $gated = (int) DB::selectOne(
                'select count(*) as n from transcriptions t join merge_map m on t.word_id = m.absorbed_id where '.$gate,
                $gateBindings,
            )->n;
            $this->stats['transcriptions_gated'] += $gated;
            // Pre-database approximation: within-map duplicates uncounted.
            $this->stats['transcriptions'] += $total - $gated;

            return;
        }
        $this->stats['transcriptions_gated'] += DB::affectingStatement(
            'delete from transcriptions t using merge_map m where t.word_id = m.absorbed_id and '.$gate,
            $gateBindings,
        );
        $moved = (int) DB::selectOne(
            'with ins as ('
            .' insert into transcriptions (transcription, word_id, transcription_type_id, created_at, updated_at)'
            .' select t.transcription, m.survivor_id, t.transcription_type_id, t.created_at, t.updated_at'
            .' from transcriptions t join merge_map m on t.word_id = m.absorbed_id'
            .' on conflict (transcription, word_id, transcription_type_id) do nothing'
            .' returning 1) select count(*) as n from ins'
        )->n;
        DB::affectingStatement('delete from transcriptions t using merge_map m where t.word_id = m.absorbed_id');
        $this->stats['transcriptions'] += $moved;
    }

    /**
     * The fetch ledger's unique key spans (word_id, target_language_id) and
     * the row carries provider/attempts/outcome state — a dedicated move
     * keeps the columns.
     */
    private function moveFetchLedger(): void
    {
        if (! $this->hasChild('word_translation_fetches')) {
            return;
        }
        if ($this->dry) {
            $this->stats['word_translation_fetches'] += (int) DB::selectOne(
                'select count(*) as n from word_translation_fetches c'
                .' join merge_map m on c.word_id = m.absorbed_id'
                .' where not exists (select 1 from word_translation_fetches s'
                .' where s.word_id = m.survivor_id and s.target_language_id = c.target_language_id)',
            )->n;

            return;
        }
        $moved = (int) DB::selectOne(
            'with ins as ('
            .' insert into word_translation_fetches'
            .' (word_id, target_language_id, provider, status, attempts, last_attempted_at, linked_count, staged_count, created_at, updated_at)'
            .' select m.survivor_id, c.target_language_id, c.provider, c.status, c.attempts, c.last_attempted_at,'
            .' c.linked_count, c.staged_count, c.created_at, c.updated_at'
            .' from word_translation_fetches c join merge_map m on c.word_id = m.absorbed_id'
            .' on conflict (word_id, target_language_id) do nothing'
            .' returning 1) select count(*) as n from ins'
        )->n;
        DB::affectingStatement(
            'delete from word_translation_fetches c using merge_map m where c.word_id = m.absorbed_id',
        );
        $this->stats['word_translation_fetches'] += $moved;
    }

    /**
     * Both word_translations arms re-point through the map: every link
     * touching an absorbed row is deleted and re-inserted as its canonical
     * mapped pair (insert-or-ignore dedupes onto pre-existing pairs and
     * within the map); a pair collapsing onto itself (absorbed → own
     * survivor, two absorbed rows of one survivor) is dropped.
     */
    private function relinkWordTranslations(): void
    {
        $mapped = 'select coalesce(m1.survivor_id, wt.word_a_id) as sa, coalesce(m2.survivor_id, wt.word_b_id) as sb'
            .' from word_translations wt'
            .' left join merge_map m1 on m1.absorbed_id = wt.word_a_id'
            .' left join merge_map m2 on m2.absorbed_id = wt.word_b_id'
            .' where m1.absorbed_id is not null or m2.absorbed_id is not null';
        DB::statement('drop table if exists relink');
        DB::statement('create temp table relink (word_a_id bigint, word_b_id bigint)');
        DB::statement('insert into relink select distinct least(sa, sb), greatest(sa, sb) from ('.$mapped.') t where sa <> sb');
        $this->stats['links_self_dropped'] += (int) DB::selectOne(
            'select count(*) as n from ('.$mapped.') t where sa = sb',
        )->n;

        $pairCount = (int) DB::selectOne('select count(*) as n from relink')->n;
        if ($this->dry) {
            $this->stats['links_repointed'] += $pairCount; // pre-database approximation

            return;
        }
        DB::affectingStatement(
            'delete from word_translations wt using merge_map m'
            .' where wt.word_a_id = m.absorbed_id or wt.word_b_id = m.absorbed_id',
        );
        $inserted = (int) DB::selectOne(
            'with ins as ('
            .' insert into word_translations (word_a_id, word_b_id, created_at, updated_at)'
            .' select word_a_id, word_b_id, now(), now() from relink'
            .' on conflict (word_a_id, word_b_id) do nothing returning 1)'
            .' select count(*) as n from ins'
        )->n;
        $this->stats['links_repointed'] += $inserted;
        $this->stats['links_dedupe'] += $pairCount - $inserted;
        DB::statement('drop table if exists relink');
    }

    /**
     * The absorbed row's staged translations JSON union-merges onto the
     * survivor (the shared StagedTranslations semantics) — before the
     * absorbed rows go.
     */
    private function mergeStagedTranslations(): void
    {
        $rows = DB::table('words as absorbed')
            ->join('merge_map', 'merge_map.absorbed_id', '=', 'absorbed.id')
            ->join('words as survivor', 'survivor.id', '=', 'merge_map.survivor_id')
            ->whereNotNull('absorbed.translations')
            ->orderBy('absorbed.id')
            ->get(['absorbed.id', 'absorbed.translations as absorbed_json', 'survivor.id as survivor_id', 'survivor.translations as survivor_json']);

        foreach ($rows as $row) {
            $absorbed = json_decode((string) $row->absorbed_json, true);
            if (! is_array($absorbed)) {
                continue;
            }
            $survivor = json_decode((string) $row->survivor_json, true);
            $current = is_array($survivor) ? $survivor : null;
            $merged = StagedTranslations::merge($current, $absorbed);
            if ($merged === $current) {
                continue;
            }
            if (! $this->dry) {
                DB::table('words')->where('id', $row->survivor_id)
                    ->update(['translations' => $merged === null ? null : json_encode($merged)]);
            }
            $this->stats['staged']++;
        }
    }

    /**
     * The survivor's rank folds the absorbed rank in as LEAST — lower is more
     * common, the fold never raises a rank, and the 0 "no rank" column
     * default (cmudict/entity adoption rows) never folds onto a ranked
     * survivor either. An unranked (sentinel) survivor inherits a better
     * absorbed rank; a ranked survivor never loses to a worse one.
     */
    private function foldFrequency(): void
    {
        if ($this->dry) {
            $this->stats['freq'] += (int) DB::selectOne(
                'select count(*) as n from merge_map m'
                .' join words a on a.id = m.absorbed_id'
                .' join words s on s.id = m.survivor_id'
                .' where a.frequency > 0 and a.frequency < s.frequency',
            )->n;

            return;
        }
        $this->stats['freq'] += DB::affectingStatement(
            'update words s set frequency = least(s.frequency, a.frequency)'
            .' from merge_map m join words a on a.id = m.absorbed_id'
            .' where s.id = m.survivor_id and a.frequency > 0 and a.frequency < s.frequency',
        );
    }

    private function repointEntityWords(): void
    {
        if (! $this->hasChild('entity_words')) {
            return;
        }
        if ($this->dry) {
            $this->stats['entity_words'] += (int) DB::selectOne(
                'select count(*) as n from entity_words ew join merge_map m on ew.word_id = m.absorbed_id'
                .' where ew.word_id is not null',
            )->n;

            return;
        }
        $this->stats['entity_words'] += DB::affectingStatement(
            'update entity_words as ew set word_id = m.survivor_id'
            .' from merge_map m where ew.word_id = m.absorbed_id',
        );
    }

    private function deleteAbsorbedWords(): void
    {
        $count = $this->mergeMapCount();
        $this->stats['deleted'] += $count;
        if ($this->dry) {
            return;
        }
        DB::affectingStatement('delete from words w using merge_map m where w.id = m.absorbed_id');
    }

    // ------------------------------------------------------------------
    // Scan plumbing
    // ------------------------------------------------------------------

    /**
     * [min, max] of a table's ids, scoped to language or class ids; null when
     * nothing to scan. $scopeJoin scopes through the owning words row (forms
     * carries no language_id of its own).
     *
     * @param  list<int>  $scopeIds
     * @return array{0: int, 1: int}|null
     */
    private function idBounds(string $table, array $scopeIds = [], string $scopeColumn = 'language_id', ?string $scopeJoin = null): ?array
    {
        $applyScope = fn ($query) => $scopeIds === []
            ? $query
            : ($scopeJoin === null
                ? $query->whereIn($scopeColumn, $scopeIds)
                : $query->join($scopeJoin.' as s', 's.id', '=', $table.'.word_id')
                    ->whereIn('s.'.$scopeColumn, $scopeIds));

        $min = $applyScope(DB::table($table))->min($table.'.id');
        if ($min === null) {
            return null;
        }
        $max = $applyScope(DB::table($table))->max($table.'.id');

        return [(int) $min, (int) $max];
    }

    /**
     * Chunked id-range scan with a progress bar. The loop is bounded by the
     * pre-computed [min, max] id range — no polling, no drain loop.
     *
     * @param  array{0: int, 1: int}  $bounds
     */
    private function scanChunked(
        string $from,
        string $idColumn,
        array $bounds,
        callable $baseQuery,
        callable $each,
    ): void {
        $chunk = max(1, (int) $this->option('chunk'));
        $batches = (int) ceil(($bounds[1] - $bounds[0] + 1) / $chunk);
        $bar = $this->output->createProgressBar($batches);
        $bar->setFormat('[%current%/%max% passes] [%elapsed%] %message%');
        $bar->setMessage('Scanning...');
        $bar->start();

        for ($start = $bounds[0]; $start <= $bounds[1]; $start += $chunk) {
            $rows = $baseQuery()
                ->whereBetween($idColumn, [$start, min($start + $chunk - 1, $bounds[1])])
                ->get();
            foreach ($rows as $row) {
                $each($row);
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
    }

    /**
     * Chunked insert of tuple rows into a temp table.
     *
     * @param  list<list<mixed>>  $tuples
     */
    private function insertTempTuples(string $table, string $columns, array $tuples): void
    {
        foreach (array_chunk($tuples, self::STATEMENT_CHUNK) as $chunk) {
            [$placeholders, $bindings] = $this->valuesPlaceholders($chunk);
            DB::statement(
                'insert into '.$table.' ('.$columns.') values '.$placeholders,
                $bindings,
            );
        }
    }

    /**
     * @param  list<list<mixed>>  $tuples
     * @return array{0: string, 1: list<mixed>}
     */
    private function valuesPlaceholders(array $tuples): array
    {
        $placeholders = implode(', ', array_map(
            fn (array $tuple): string => '('.implode(', ', array_fill(0, count($tuple), '?')).')',
            $tuples,
        ));

        return [$placeholders, array_merge(...array_map('array_values', $tuples))];
    }
}
