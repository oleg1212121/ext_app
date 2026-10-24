<?php

namespace App\Classes;

use App\Classes\Wiktionary\FormOfClassifier;
use App\Models\Definition;
use App\Models\Etymology;
use App\Models\Form;
use App\Models\Language;
use App\Models\Transcription;
use App\Models\TranscriptionType;
use App\Models\Word;
use App\Models\WordClass;
use App\Support\DefinitionLanguage;
use App\Support\LemmaForms;
use App\Support\PosAliases;
use App\Support\StressMarks;
use App\Support\TranslationTargets;
use App\Support\UsageLabels;
use App\Support\WordLookupKey;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

class WiktionaryParser
{
    private string $lang;

    /** @var list<string> */
    private array $targetLangs;

    private int $batchSize;

    private int $languageId;

    /** Display headwords and forms of this source language drop their
     * stress marks (ru/be/uk). */
    private bool $stripsDisplayMarks;

    private array $wordClassMap = [];

    private array $transcriptionTypeMap = [];

    private array $stats = [
        'lines_read' => 0,
        'words_imported' => 0,
        'relays_demoted' => 0,
        'relay_bases_created' => 0,
        'lookups_created' => 0,
        'batches_flushed' => 0,
        'definitions_skipped_foreign' => 0,
        'definitions_labels_updated' => 0,
    ];

    public function __construct(string $lang, string|array $targetLang = 'ru', int $batchSize = 500)
    {
        $this->lang = $lang;
        $this->stripsDisplayMarks = StressMarks::stripsFor($lang);

        $targets = is_string($targetLang) ? [$targetLang] : array_values($targetLang);
        if ($targets === []) {
            throw new \InvalidArgumentException('At least one target language is required.');
        }
        if (in_array($lang, $targets, true)) {
            throw new \InvalidArgumentException('Target languages must differ from the source language.');
        }
        $this->targetLangs = $targets;

        $this->batchSize = $batchSize;

        $language = Language::query()->where('code', $lang)->first();

        if ($language === null) {
            throw new \InvalidArgumentException("Unsupported language: {$lang}");
        }

        $this->languageId = $language->id;
    }

    public function import(string $path, ?OutputInterface $output = null): array
    {
        if (! file_exists($path)) {
            throw new \InvalidArgumentException("File not found: {$path}");
        }

        $this->loadLookupMaps();

        $progressBar = null;
        if ($output) {
            $progressBar = new ProgressBar($output);
            $progressBar->setFormat('[%current% lines] [%elapsed%] %message%');
            $progressBar->setMessage('Parsing...');
            $progressBar->start();
        }

        $batch = [];
        $currentKey = null;
        $currentRecord = null;

        foreach ($this->parseFile($path) as $parsed) {
            $this->stats['lines_read']++;

            $pos = $parsed['pos'];
            // Adjacent lines merge on the lookup key: two spellings of one
            // word (ёлка/елка, until the case fold) union their senses and
            // staged candidates, and the first display spelling stands.
            $key = $parsed['l_word'].'|'.$pos;

            if (! isset($this->wordClassMap[$pos])) {
                $this->ensureWordClass($pos);
            }

            if ($key === $currentKey) {
                $currentRecord = $this->mergeRecord($currentRecord, $parsed);
            } else {
                if ($currentRecord !== null) {
                    $batch[] = $currentRecord;
                    if (count($batch) >= $this->batchSize) {
                        $this->flushBatch($batch);
                        $this->stats['batches_flushed']++;
                        $batch = [];
                    }
                }
                $currentKey = $key;
                $currentRecord = $parsed;
            }

            if ($this->stats['lines_read'] % 1000 === 0) {
                if ($progressBar) {
                    $progressBar->setProgress($this->stats['lines_read']);
                    $progressBar->setMessage("Words: {$this->stats['words_imported']}");
                }

                if (memory_get_usage(true) > 100 * 1024 * 1024 && ! empty($batch)) {
                    $this->flushBatch($batch);
                    $this->stats['batches_flushed']++;
                    $batch = [];
                }
            }
        }

        if ($currentRecord !== null) {
            $batch[] = $currentRecord;
        }

        if (! empty($batch)) {
            $this->flushBatch($batch);
            $this->stats['batches_flushed']++;
        }

        if ($progressBar) {
            $progressBar->finish();
            $output->writeln('');
        }

        // The dictionary just grew: previously unmatchable entity tokens of
        // this language get another linking chance.
        $this->stats['unmatched_cleared'] = EntityWordLinker::clearUnmatchedForLanguage($this->languageId);

        return $this->stats;
    }

    public function parseFile(string $path): \Generator
    {
        // Transparent gzip streaming: the wrapper decompresses on the fly,
        // so fgets() still yields one JSON object per line.
        $source = str_ends_with(strtolower($path), '.gz') ? 'compress.zlib://'.$path : $path;
        $handle = fopen($source, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open file: {$path}");
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line);
                if ($decoded === null || ! isset($decoded->word)) {
                    continue;
                }

                $parsed = $this->parseLine($decoded);
                if ($parsed !== null) {
                    yield $parsed;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    public function parseLine(object $line): ?array
    {
        $word = $line->word ?? null;
        if ($word === null) {
            return null;
        }

        // Headwords trim at storage: only checking emptiness left padded
        // dump artifacts (" ёлка ") storing verbatim, fragmenting lookups.
        $word = trim((string) $word);
        if ($word === '') {
            return null;
        }

        // ru/be/uk headwords store as plain letters: the combining stress
        // acutes leave the stored display word too (the ADR 0078 kaikki
        // carve-out is reversed — a stressed dump variant collapses into
        // the same row as the plain word). Definition prose keeps its
        // marks.
        if ($this->stripsDisplayMarks) {
            $word = StressMarks::strip($word);
        }

        // A headword that is nothing but combining marks (a marks-only dump
        // artifact) strips to the empty string: store nothing.
        if ($word === '') {
            return null;
        }

        // Raw kaikki pos slugs canonicalize through the shared alias table:
        // 'adj' joins 'adjective' instead of minting a parallel taxonomy,
        // and the aliased lines merge into one word row.
        $rawPos = $line->pos ?? 'unknown';
        $pos = PosAliases::canonical($rawPos) ?? $rawPos;

        // A relay entry (every sense a form-of pointer — "четвереньках:
        // prepositional of четвере́ньки") demotes to a FORM of its base word
        // instead of a word row: its relay glosses, transcriptions,
        // etymology and staged translations store nothing. Mixed entries
        // (some relay senses + some real glosses) stay words — the shared
        // classifier holds the verdict.
        $relay = FormOfClassifier::classify($line);
        $relayBase = null;
        if ($relay['relay']) {
            $base = $this->stripsDisplayMarks ? StressMarks::strip((string) $relay['base']) : (string) $relay['base'];
            // The base stores as a proxy headword's display word: trimmed
            // like every headword. A base that strips to nothing (a
            // marks-only artifact) demotes to nothing: keep the entry a
            // word instead of dropping it.
            $base = trim($base);
            if ($base !== '') {
                $relayBase = $base;
            }
        }

        if ($relayBase !== null) {
            return [
                'word' => $word,
                'l_word' => WordLookupKey::normalize($word),
                'pos' => $pos,
                'relay_base' => $relayBase,
                'definitions' => [],
                'forms' => [],
                'sounds' => [],
                'etymology' => null,
                'translations' => [],
                'labels' => [],
            ];
        }

        $forms = [];
        foreach ($line->forms ?? [] as $form) {
            // Latin transliterations ("četverénʹkax" on a ru word) are
            // tagged romanization: junk for a script-keyed dictionary.
            if (in_array('romanization', (array) ($form->tags ?? []), true)) {
                continue;
            }
            if (($form->form ?? null) !== null && trim($form->form) !== '') {
                $forms[] = $form->form;
            }
        }

        $sounds = [];
        foreach ($line->sounds ?? [] as $sound) {
            if (($sound->ipa ?? null) !== null && trim($sound->ipa) !== '') {
                $sounds[] = ['value' => $sound->ipa, 'type' => 'ipa'];
            }
            if (($sound->enpr ?? null) !== null && trim($sound->enpr) !== '') {
                $sounds[] = ['value' => $sound->enpr, 'type' => 'enpr'];
            }
        }

        $definitions = [];
        foreach ($line->senses ?? [] as $sense) {
            $rawGlosses = $sense->raw_glosses ?? [];
            if (! empty($rawGlosses)) {
                $gloss = implode(' ', $rawGlosses);
            } else {
                $gloss = implode(' ', $sense->glosses ?? []);
            }
            if (trim($gloss) !== '') {
                // The sense's usage/register tags ride the definition: only
                // the closed set survives from the tags array (the rest is
                // grammar/gender noise), in the dump's own order. Both gloss
                // paths label — raw_glosses and glosses share the sense.
                $definitions[] = [
                    'text' => $gloss,
                    'labels' => UsageLabels::fromKaikkiTags((array) ($sense->tags ?? [])),
                ];
            }
        }

        $translations = $this->extractTranslations($line);

        // The word-level usage mark — the label Wiktionary prints under the
        // part-of-speech heading ("decimate (obsolete)" on the noun). kaikki
        // keeps it out of senses[]: it rides the entry-level tags array
        // (absent from this edition's dump, but in the wiktextract schema)
        // and the headword-label head template's args — tlb, or its
        // term-label alias. Arg 1 is the language code; the numbered args
        // after it are the labels, grammar words included ("transitive,
        // chiefly archaic") — the closed-set filter drops the noise and
        // keeps the mark the word itself carries, never an aggregate of its
        // senses.
        $headwordTags = (array) ($line->tags ?? []);
        foreach ($line->head_templates ?? [] as $template) {
            if (in_array($template->name ?? null, ['tlb', 'term-label'], true)) {
                foreach ((array) ($template->args ?? []) as $key => $value) {
                    if ($key !== '1' && is_string($value) && preg_match('/^\d+$/', (string) $key) === 1) {
                        $headwordTags[] = $value;
                    }
                }
            }
        }

        // The en edition carries one etymology string (`etymology_text`);
        // the ru edition an ARRAY of text blocks (`etymology_texts`) — one
        // block per etymology hypothesis. A populated singular wins when
        // both appear; an empty/whitespace one (a dump artifact) falls
        // through to the array. The array's non-empty parts join with
        // newlines, the same multi-line convention the en edition's
        // strings already carry.
        $etymology = $line->etymology_text ?? null;
        if ($etymology === null || trim((string) $etymology) === '') {
            $parts = array_values(array_filter(
                (array) ($line->etymology_texts ?? []),
                fn ($part) => is_string($part) && trim($part) !== '',
            ));
            $etymology = $parts === [] ? null : implode("\n", $parts);
        }

        return [
            'word' => $word,
            'l_word' => WordLookupKey::normalize($word),
            'pos' => $pos,
            'relay_base' => null,
            'definitions' => $this->uniqueDefinitions($definitions),
            'forms' => array_values(array_unique($forms)),
            'sounds' => $sounds,
            'etymology' => $etymology,
            'translations' => array_values(array_unique($translations)),
            'labels' => UsageLabels::fromKaikkiTags($headwordTags),
        ];
    }

    /**
     * Definitions dedupe on their TEXT (first occurrence wins, its labels
     * standing): the same gloss from two senses is one dictionary row
     * whatever the tags said — the same exact-text rule array_unique
     * applied when the records carried bare strings.
     *
     * @param  list<array{text: string, labels: list<string>}>  $definitions
     * @return list<array{text: string, labels: list<string>}>
     */
    private function uniqueDefinitions(array $definitions): array
    {
        $seen = [];
        $result = [];
        foreach ($definitions as $definition) {
            if (! isset($seen[$definition['text']])) {
                $seen[$definition['text']] = true;
                $result[] = $definition;
            }
        }

        return $result;
    }

    public function extractTranslations(object $line): array
    {
        $translations = [];
        foreach ($line->senses ?? [] as $sense) {
            foreach ($sense->translations ?? [] as $translation) {
                $candidate = $this->stagedCandidate($translation);
                if ($candidate !== null) {
                    $translations[] = $candidate;
                }
            }
        }
        foreach ($line->translations ?? [] as $translation) {
            $candidate = $this->stagedCandidate($translation);
            if ($candidate !== null) {
                $translations[] = $candidate;
            }
        }

        return array_values(array_unique($translations));
    }

    /**
     * One staged translation candidate: kept only for this parser's target
     * languages, trimmed at storage (only checking emptiness padded the
     * stored string), and stored plain — a candidate whose target language
     * is a StressMarks one (ru/be/uk) loses its combining marks HERE, at
     * staging time (spec rule 1). The link pass strips at pairing time too
     * and the auto-fetch strips before staging, but an unmatched candidate
     * sits in the staged JSON verbatim until some pass converts it, so the
     * stored string itself must never carry the marks. A candidate that is
     * nothing but whitespace or combining marks stages nothing.
     *
     * The target language reads `code`, falling back to `lang_code`: en-edition
     * translation objects carry both (equal values); the ru edition's carry
     * only `lang_code`.
     */
    private function stagedCandidate(object $translation): ?string
    {
        $code = $translation->code ?? $translation->lang_code ?? null;
        if (! in_array($code, $this->targetLangs, true)) {
            return null;
        }

        $word = $translation->word ?? null;
        if ($word === null) {
            return null;
        }

        $word = trim((string) $word);
        if ($word === '') {
            return null;
        }

        if (StressMarks::stripsFor((string) $code)) {
            $word = StressMarks::strip($word);
            if ($word === '') {
                return null;
            }
        }

        return $word;
    }

    private function loadLookupMaps(): void
    {
        $this->wordClassMap = WordClass::query()
            ->where('language_id', $this->languageId)
            ->pluck('id', 'slug')
            ->toArray();

        $this->transcriptionTypeMap = TranscriptionType::query()
            ->where('language_id', $this->languageId)
            ->pluck('id', 'slug')
            ->toArray();

        // flushBatch falls back to this class for records whose pos has no mapping.
        $this->ensureWordClass('unknown');
    }

    private function ensureWordClass(string $slug): int
    {
        if (! isset($this->wordClassMap[$slug])) {
            $class = WordClass::query()->firstOrCreate(
                ['language_id' => $this->languageId, 'slug' => $slug],
                ['title' => $slug],
            );
            $this->wordClassMap[$slug] = $class->id;
            $this->stats['lookups_created']++;
        }

        return $this->wordClassMap[$slug];
    }

    private function ensureTranscriptionType(string $slug): int
    {
        if (! isset($this->transcriptionTypeMap[$slug])) {
            $type = TranscriptionType::query()->firstOrCreate(
                ['language_id' => $this->languageId, 'slug' => $slug],
                ['title' => $slug],
            );
            $this->transcriptionTypeMap[$slug] = $type->id;
            $this->stats['lookups_created']++;
        }

        return $this->transcriptionTypeMap[$slug];
    }

    public function mergeRecord(array $existing, array $incoming): array
    {
        // Relay demotion merges by union-of-senses semantics: a relay line
        // merged with a real line is a mixed entry — it stays a word. Two
        // relay lines demote together only when they point at the same base
        // (compared on the normalized key: stress and case cannot divide
        // them); disagreeing bases stay a word.
        $existingBase = $existing['relay_base'] ?? null;
        $incomingBase = $incoming['relay_base'] ?? null;
        if ($existingBase !== null && $incomingBase !== null
            && WordLookupKey::normalize($existingBase) === WordLookupKey::normalize($incomingBase)) {
            $existing['relay_base'] = $existingBase;
        } else {
            // Both real, one of each (mixed), or disagreeing bases: a word.
            $existing['relay_base'] = null;
        }

        // Definitions dedupe on text across the merged lines, first
        // occurrence (and its labels) standing — the same union the bare
        // string lists got.
        $existing['definitions'] = $this->uniqueDefinitions(array_merge($existing['definitions'], $incoming['definitions']));
        $existing['forms'] = array_values(array_unique(array_merge($existing['forms'], $incoming['forms'])));
        $existing['sounds'] = array_values(array_unique(array_merge($existing['sounds'], $incoming['sounds']), SORT_REGULAR));
        $existing['translations'] = array_values(array_unique(array_merge($existing['translations'], $incoming['translations'])));
        $existing['labels'] = array_values(array_unique(array_merge($existing['labels'], $incoming['labels'])));

        if ($existing['etymology'] === null && $incoming['etymology'] !== null) {
            $existing['etymology'] = $incoming['etymology'];
        }

        return $existing;
    }

    public function flushBatch(array $batch): void
    {
        DB::transaction(function () use ($batch) {
            $defaultWordClassId = $this->wordClassMap['unknown'] ?? reset($this->wordClassMap);

            // Relay records never become word rows; they attach as forms of
            // their bases below.
            $wordRecords = [];
            $relayRecords = [];
            foreach ($batch as $record) {
                if (($record['relay_base'] ?? null) !== null) {
                    $relayRecords[] = $record;
                } else {
                    $wordRecords[] = $record;
                }
            }

            $wordUpserts = [];
            foreach ($wordRecords as $record) {
                $posId = $this->wordClassMap[$record['pos']] ?? $defaultWordClassId;
                $wordUpserts[] = [
                    'word' => $record['word'],
                    'l_word' => $record['l_word'],
                    'language_id' => $this->languageId,
                    'word_class_id' => $posId,
                    'translations' => ! empty($record['translations']) ? json_encode($record['translations']) : null,
                    // The word-level mark stores like a definition's: the
                    // raw JSON string, null when nothing remains (the
                    // column's "unlabelled" shape).
                    'labels' => ! empty($record['labels']) ? json_encode($record['labels']) : null,
                    // Provenance rides the INSERT only: the update list below
                    // never touches source, so the first importer wins
                    // (ADR 0084).
                    'source' => Word::SOURCE_WIKTIONARY,
                ];
            }

            $this->uniqueByCompound($wordUpserts, ['l_word', 'language_id', 'word_class_id']);
            if ($wordUpserts !== []) {
                // Uniqueness keys on (l_word, language_id, word_class_id): a
                // second source's spelling of one word attaches to the first
                // source's row. On conflict refresh the key, the staged
                // candidates and the word-level mark — the re-import is the
                // labels backfill, a source that stopped marking the
                // headword unmarks the row again — while the stored display
                // word and the frequency rank are never clobbered (the
                // ADR 0080 merge: the first spelling stands, the staged JSON
                // is the edition's refresh).
                Word::upsert($wordUpserts, ['l_word', 'language_id', 'word_class_id'], ['l_word', 'translations', 'labels']);

                // The fill-if-null backdoor: a re-run badges the pre-column
                // rows this batch touched, without claiming a row another
                // creator already owns.
                Word::fillSourceIfNull(Word::SOURCE_WIKTIONARY, $this->languageId, $wordUpserts);
            }

            $this->stats['words_imported'] += count($wordUpserts);

            $this->demoteRelays($relayRecords, $defaultWordClassId);

            // The id map keys on the lookup key: a record whose display
            // spelling differs from the stored row's (елка arriving when ёлка
            // is stored) must still resolve to that one row.
            $wordIds = Word::query()
                ->where('language_id', $this->languageId)
                ->whereIn('l_word', collect($wordRecords)->pluck('l_word')->unique()->toArray())
                ->get(['id', 'l_word', 'word_class_id'])
                ->keyBy(fn ($w) => $w->l_word.'|'.$w->word_class_id);

            $this->flushDefinitions($wordRecords, $wordIds, $defaultWordClassId);
            $this->flushForms($wordRecords, $wordIds, $defaultWordClassId);
            $this->flushEtymologies($wordRecords, $wordIds, $defaultWordClassId);
            $this->flushTranscriptions($wordRecords, $wordIds, $defaultWordClassId);
        });
    }

    /**
     * A relay record becomes a form of its base word — never a word row.
     * The base resolves among this language's existing rows by l_word with
     * the shared class preference (same-class row, else lowest id; an
     * unknown pos skips the preference). A base the dump never lists as a
     * lemma is created here as a proxy headword — still a dictionary
     * headword, so it carries itself as its lemma form.
     *
     * @param  list<array<string, mixed>>  $relays
     */
    private function demoteRelays(array $relays, int $defaultWordClassId): void
    {
        if ($relays === []) {
            return;
        }

        $wanted = [];
        $displayByLWord = [];
        foreach ($relays as $relay) {
            $baseLWord = WordLookupKey::normalize((string) $relay['relay_base']);
            $displayByLWord[$baseLWord] ??= (string) $relay['relay_base'];
            if (! array_key_exists($baseLWord, $wanted)) {
                // The goldendict resolver semantics: the relay's pos prefers
                // the same-class base row, else the lowest id wins — but an
                // unknown pos (or a pos without a class) skips the
                // preference entirely, so the placeholder-`unknown` twin of
                // a properly classed base never wins it.
                $wanted[$baseLWord] = $relay['pos'] === 'unknown'
                    ? null
                    : ($this->wordClassMap[$relay['pos']] ?? null);
            }
        }

        $resolved = TranslationTargets::forLanguage($this->languageId, $wanted);

        $missing = array_diff_key($wanted, $resolved);
        $formRows = [];
        if ($missing !== []) {
            $baseRows = [];
            foreach ($missing as $baseLWord => $classId) {
                $baseRows[] = [
                    'word' => $displayByLWord[$baseLWord],
                    'l_word' => $baseLWord,
                    'language_id' => $this->languageId,
                    'word_class_id' => $classId ?? $defaultWordClassId,
                    'translations' => null,
                ];
            }
            // A base the dump never lists is keyed uniquely by its l_word —
            // the same triple the word upserts conflict on.
            Word::upsert($baseRows, ['l_word', 'language_id', 'word_class_id']);
            $this->stats['relay_bases_created'] += count($baseRows);

            $resolved += TranslationTargets::forLanguage($this->languageId, $missing);

            // A proxy headword is a dictionary headword like any other: it
            // carries itself as its lemma form through the shared helper.
            foreach ($missing as $baseLWord => $classId) {
                $baseId = $resolved[$baseLWord] ?? null;
                if ($baseId !== null) {
                    array_push($formRows, ...LemmaForms::rows($displayByLWord[$baseLWord], [], $baseId, $this->stripsDisplayMarks));
                }
            }
        }

        foreach ($relays as $relay) {
            $baseId = $resolved[WordLookupKey::normalize((string) $relay['relay_base'])] ?? null;
            if ($baseId === null) {
                continue;
            }
            // The relay headword becomes the base's form — the same
            // cap/strip/dedupe conventions as every other form row.
            array_push($formRows, ...LemmaForms::rows((string) $relay['word'], [], $baseId, $this->stripsDisplayMarks));
            $this->stats['relays_demoted']++;
        }

        if ($formRows !== []) {
            $this->uniqueByCompound($formRows, ['form', 'word_id']);
            Form::upsert($formRows, ['form', 'word_id']);
        }
    }

    private function flushDefinitions(array $batch, object $wordIds, int $defaultWordClassId): void
    {
        $rows = [];
        foreach ($batch as $record) {
            $wordId = $this->lookupWordId($record, $wordIds, $defaultWordClassId);
            if ($wordId === null) {
                continue;
            }
            foreach ($record['definitions'] as $definition) {
                // The majority-script guard: a definition is only imported
                // when it is written in the word language's own script —
                // the en edition's English glosses on ru words (and vice
                // versa) are the other language's prose, not definitions.
                // Import-time only: stored texts are never rewritten (the
                // labels of a same-text row are, see mergeDefinitionRows).
                if (! DefinitionLanguage::keeps($this->lang, $definition['text'])) {
                    $this->stats['definitions_skipped_foreign']++;

                    continue;
                }
                $rows[] = [
                    'definition' => mb_substr($definition['text'], 0, 500),
                    'word_id' => $wordId,
                    // An empty label list stores as null — the column's
                    // "unlabelled" shape, identical to the pre-label rows.
                    'labels' => $definition['labels'] === [] ? null : json_encode($definition['labels']),
                ];
            }
        }
        if (! empty($rows)) {
            $this->uniqueByCompound($rows, ['definition', 'word_id']);
            $this->mergeDefinitionRows($rows);
        }
    }

    /**
     * The definitions flush's merge step — the etymologies keep the plain
     * {@see insertNewOnly}. New texts insert as before, and a
     * case-insensitively same-text row (the very key insertNewOnly skips
     * on) has its labels refreshed to the incoming value, null included:
     * the re-import is the labels backfill, and a source that stopped
     * tagging a sense unlabels it again. The ask-AI enrichment's rows
     * (source='ai') are another writer's — their labels stand. Every
     * changed row counts in definitions_labels_updated, the summary's
     * backfill gauge; a converged repeat import updates nothing.
     *
     * @param  list<array{definition: string, word_id: int, labels: string|null}>  $rows
     */
    private function mergeDefinitionRows(array $rows): void
    {
        $wordIds = collect($rows)->pluck('word_id')->unique()->toArray();

        // Existing rows keyed like insertNewOnly keys them — word_id plus
        // the lowercased text — with id/labels/source riding along for the
        // update step. definitions.word_id is indexed for exactly this.
        // One row per case-folded text is assumed (the map's last fetch
        // would win a case-variant duplicate — definitions carry no such
        // unique index).
        $existing = [];
        foreach (array_chunk($wordIds, 100) as $chunk) {
            foreach (Definition::query()->whereIn('word_id', $chunk)->get(['id', 'word_id', 'definition', 'labels', 'source']) as $row) {
                $existing[$row->word_id.'|'.mb_strtolower($row->definition)] = $row;
            }
        }

        $newRows = [];
        $updatesById = [];
        foreach ($rows as $row) {
            $current = $existing[$row['word_id'].'|'.mb_strtolower($row['definition'])] ?? null;

            if ($current === null) {
                $newRows[] = $row;

                continue;
            }
            if ($current->source === Word::SOURCE_AI) {
                continue;
            }
            $incoming = $row['labels'] === null ? null : json_decode($row['labels'], true);
            // List comparison, order-sensitive: storage keeps the order the
            // source listed the tags in, so a converged dump compares equal.
            if ($current->labels !== $incoming) {
                $updatesById[$current->id] = $row['labels'];
                // One batch can carry two spellings of the same text: the
                // second compares against the first's value, not the stale
                // pre-batch one.
                $current->labels = $incoming;
                $this->stats['definitions_labels_updated']++;
            }
        }

        if ($newRows !== []) {
            Definition::insert($newRows);
        }

        // One UPDATE per distinct incoming value, not per row — the full
        // re-import touches millions of rows but only a handful of label
        // lists. Raw JSON strings: the same convention the word upserts use
        // for jsonb columns. The null group stays separate — PHP casts null
        // array keys to '', and '' is not JSON Postgres would take.
        $updatesByJson = [];
        $nullLabelIds = [];
        foreach ($updatesById as $id => $labels) {
            if ($labels === null) {
                $nullLabelIds[] = $id;
            } else {
                $updatesByJson[$labels][] = $id;
            }
        }
        if ($nullLabelIds !== []) {
            Definition::query()->whereIn('id', $nullLabelIds)->update(['labels' => null]);
        }
        foreach ($updatesByJson as $labels => $ids) {
            Definition::query()->whereIn('id', $ids)->update(['labels' => $labels]);
        }
    }

    private function flushForms(array $batch, object $wordIds, int $defaultWordClassId): void
    {
        $rows = [];
        foreach ($batch as $record) {
            $wordId = $this->lookupWordId($record, $wordIds, $defaultWordClassId);
            if ($wordId === null) {
                continue;
            }
            // Every word stores itself as its lemma form first (the forms
            // table alone must resolve lookups), then the dump's inflected
            // forms: capped, stress-stripped, deduped — the shared helper.
            array_push($rows, ...LemmaForms::rows($record['word'], $record['forms'], $wordId, $this->stripsDisplayMarks));
        }
        if (! empty($rows)) {
            $this->uniqueByCompound($rows, ['form', 'word_id']);
            Form::upsert($rows, ['form', 'word_id']);
        }
    }

    private function flushEtymologies(array $batch, object $wordIds, int $defaultWordClassId): void
    {
        $rows = [];
        foreach ($batch as $record) {
            $wordId = $this->lookupWordId($record, $wordIds, $defaultWordClassId);
            if ($wordId === null || $record['etymology'] === null) {
                continue;
            }
            $rows[] = [
                'etymology' => mb_substr($record['etymology'], 0, 1000),
                'word_id' => $wordId,
            ];
        }
        if (! empty($rows)) {
            $this->uniqueByCompound($rows, ['etymology', 'word_id']);
            $this->insertNewOnly(Etymology::class, $rows, 'etymology', 'word_id');
        }
    }

    private function flushTranscriptions(array $batch, object $wordIds, int $defaultWordClassId): void
    {
        $rows = [];
        foreach ($batch as $record) {
            $wordId = $this->lookupWordId($record, $wordIds, $defaultWordClassId);
            if ($wordId === null) {
                continue;
            }
            foreach ($record['sounds'] as $sound) {
                $typeId = $this->ensureTranscriptionType($sound['type']);
                $rows[] = [
                    'transcription' => mb_substr($sound['value'], 0, 100),
                    'word_id' => $wordId,
                    'transcription_type_id' => $typeId,
                ];
            }
        }
        if (! empty($rows)) {
            $this->uniqueByCompound($rows, ['transcription', 'word_id', 'transcription_type_id']);
            Transcription::upsert($rows, ['transcription', 'word_id', 'transcription_type_id']);
        }
    }

    private function lookupWordId(array $record, object $wordIds, int $defaultWordClassId): ?int
    {
        $posId = $this->wordClassMap[$record['pos']] ?? $defaultWordClassId;
        // Same key the upsert conflicted on — the computed l_word, not the
        // display word (which a second source may spell differently).
        $lookupKey = $record['l_word'].'|'.$posId;

        return $wordIds[$lookupKey]->id ?? null;
    }

    /**
     * The etymologies' insert-new-only merge (definitions route through
     * {@see mergeDefinitionRows} instead — their same-text rows carry
     * updatable labels): a row whose case-insensitive text the database
     * already stores is skipped, every other row inserts.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function insertNewOnly(string $model, array $rows, string $textColumn, string $fkColumn): void
    {
        if (empty($rows)) {
            return;
        }

        $wordIds = collect($rows)->pluck($fkColumn)->unique()->toArray();

        $existing = [];
        foreach (array_chunk($wordIds, 100) as $chunk) {
            foreach ($model::whereIn($fkColumn, $chunk)->get([$fkColumn, $textColumn]) as $row) {
                // A foreach, not an each(arrow fn): arrow functions capture
                // by value, so the write would never reach $existing.
                $existing[$row->{$fkColumn}.'|'.mb_strtolower($row->{$textColumn})] = true;
            }
        }

        $newRows = array_filter($rows, function ($row) use ($existing, $textColumn, $fkColumn) {
            $key = $row[$fkColumn].'|'.mb_strtolower($row[$textColumn]);

            return ! isset($existing[$key]);
        });

        if (! empty($newRows)) {
            $model::insert(array_values($newRows));
        }
    }

    public function uniqueByCompound(array &$rows, array $keyColumns): void
    {
        $seen = [];
        $result = [];
        foreach ($rows as $row) {
            $key = implode('|', array_map(fn ($col) => (string) ($row[$col] ?? ''), $keyColumns));
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $row;
            }
        }
        $rows = $result;
    }
}
