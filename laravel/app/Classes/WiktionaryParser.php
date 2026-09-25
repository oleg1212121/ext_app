<?php

namespace App\Classes;

use App\Models\Definition;
use App\Models\Etymology;
use App\Models\Form;
use App\Models\Language;
use App\Models\Transcription;
use App\Models\TranscriptionType;
use App\Models\Word;
use App\Models\WordClass;
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

    private array $wordClassMap = [];

    private array $transcriptionTypeMap = [];

    private array $stats = [
        'lines_read' => 0,
        'words_imported' => 0,
        'lookups_created' => 0,
        'batches_flushed' => 0,
    ];

    public function __construct(string $lang, string|array $targetLang = 'ru', int $batchSize = 500)
    {
        $this->lang = $lang;

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

            $word = $parsed['word'];
            $pos = $parsed['pos'];
            $key = mb_strtolower($word).'|'.$pos;

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
        if ($word === null || trim($word) === '') {
            return null;
        }

        $pos = $line->pos ?? 'unknown';

        $forms = [];
        foreach ($line->forms ?? [] as $form) {
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
                $definitions[] = $gloss;
            }
        }

        $translations = $this->extractTranslations($line);

        $etymology = $line->etymology_text ?? null;
        if ($etymology !== null && trim($etymology) === '') {
            $etymology = null;
        }

        return [
            'word' => $word,
            'l_word' => $this->normalizeLookupKey($word),
            'pos' => $pos,
            'definitions' => array_values(array_unique($definitions)),
            'forms' => array_values(array_unique($forms)),
            'sounds' => $sounds,
            'etymology' => $etymology,
            'translations' => array_values(array_unique($translations)),
        ];
    }

    public function extractTranslations(object $line): array
    {
        $translations = [];
        foreach ($line->senses ?? [] as $sense) {
            foreach ($sense->translations ?? [] as $translation) {
                if (in_array($translation->code ?? null, $this->targetLangs, true) && ($translation->word ?? null) !== null && trim($translation->word) !== '') {
                    $translations[] = $translation->word;
                }
            }
        }
        foreach ($line->translations ?? [] as $translation) {
            if (in_array($translation->code ?? null, $this->targetLangs, true) && ($translation->word ?? null) !== null && trim($translation->word) !== '') {
                $translations[] = $translation->word;
            }
        }

        return array_values(array_unique($translations));
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
        $existing['definitions'] = array_values(array_unique(array_merge($existing['definitions'], $incoming['definitions'])));
        $existing['forms'] = array_values(array_unique(array_merge($existing['forms'], $incoming['forms'])));
        $existing['sounds'] = array_values(array_unique(array_merge($existing['sounds'], $incoming['sounds']), SORT_REGULAR));
        $existing['translations'] = array_values(array_unique(array_merge($existing['translations'], $incoming['translations'])));

        if ($existing['etymology'] === null && $incoming['etymology'] !== null) {
            $existing['etymology'] = $incoming['etymology'];
        }

        return $existing;
    }

    public function flushBatch(array $batch): void
    {
        DB::transaction(function () use ($batch) {
            $defaultWordClassId = $this->wordClassMap['unknown'] ?? reset($this->wordClassMap);

            $wordUpserts = [];
            foreach ($batch as $record) {
                $posId = $this->wordClassMap[$record['pos']] ?? $defaultWordClassId;
                $wordUpserts[] = [
                    'word' => $record['word'],
                    'l_word' => $record['l_word'],
                    'language_id' => $this->languageId,
                    'word_class_id' => $posId,
                    'translations' => ! empty($record['translations']) ? json_encode($record['translations']) : null,
                ];
            }

            $this->uniqueByCompound($wordUpserts, ['word', 'language_id', 'word_class_id']);
            Word::upsert($wordUpserts, ['word', 'language_id', 'word_class_id']);

            $this->stats['words_imported'] += count($wordUpserts);

            $wordIds = Word::query()
                ->where('language_id', $this->languageId)
                ->whereIn('word', collect($batch)->pluck('word')->unique()->toArray())
                ->get(['id', 'word', 'word_class_id'])
                ->keyBy(fn ($w) => mb_strtolower($w->word).'|'.$w->word_class_id);

            $this->flushDefinitions($batch, $wordIds, $defaultWordClassId);
            $this->flushForms($batch, $wordIds, $defaultWordClassId);
            $this->flushEtymologies($batch, $wordIds, $defaultWordClassId);
            $this->flushTranscriptions($batch, $wordIds, $defaultWordClassId);
        });
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
                $rows[] = [
                    'definition' => mb_substr($definition, 0, 500),
                    'word_id' => $wordId,
                ];
            }
        }
        if (! empty($rows)) {
            $this->uniqueByCompound($rows, ['definition', 'word_id']);
            $this->insertNewOnly(Definition::class, $rows, 'definition', 'word_id');
        }
    }

    /**
     * Lookup key for matching: lowercased with combining marks (Russian
     * headwords' stress marks) stripped. The display `word` keeps them.
     */
    private function normalizeLookupKey(string $word): string
    {
        return (string) preg_replace('/\p{M}/u', '', mb_strtolower($word));
    }

    private function flushForms(array $batch, object $wordIds, int $defaultWordClassId): void
    {
        $rows = [];
        foreach ($batch as $record) {
            $wordId = $this->lookupWordId($record, $wordIds, $defaultWordClassId);
            if ($wordId === null) {
                continue;
            }
            foreach ($record['forms'] as $form) {
                $rows[] = [
                    'form' => mb_substr($form, 0, 256),
                    'l_word' => $this->normalizeLookupKey(mb_substr($form, 0, 256)),
                    'word_id' => $wordId,
                ];
            }
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
        $lookupKey = mb_strtolower($record['word']).'|'.$posId;

        return $wordIds[$lookupKey]->id ?? null;
    }

    private function insertNewOnly(string $model, array $rows, string $textColumn, string $fkColumn): void
    {
        if (empty($rows)) {
            return;
        }

        $wordIds = collect($rows)->pluck($fkColumn)->unique()->toArray();

        $existing = [];
        foreach (array_chunk($wordIds, 100) as $chunk) {
            $model::whereIn($fkColumn, $chunk)
                ->get([$fkColumn, $textColumn])
                ->each(fn ($r) => $existing[$r->{$fkColumn}.'|'.mb_strtolower($r->{$textColumn})] = true);
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
