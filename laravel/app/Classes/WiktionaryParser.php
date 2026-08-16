<?php

namespace App\Classes;

use App\Models\EnDefinition;
use App\Models\EnEtymology;
use App\Models\EnForm;
use App\Models\EnTranscription;
use App\Models\EnTranscriptionType;
use App\Models\EnWord;
use App\Models\EnWordClass;
use App\Models\RuDefinition;
use App\Models\RuEtymology;
use App\Models\RuForm;
use App\Models\RuTranscription;
use App\Models\RuTranscriptionType;
use App\Models\RuWord;
use App\Models\RuWordClass;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

class WiktionaryParser
{
    private const LANG_CONFIG = [
        'en' => [
            'word_model' => EnWord::class,
            'word_class_model' => EnWordClass::class,
            'word_class_fk' => 'en_word_class_id',
            'definition_model' => EnDefinition::class,
            'form_model' => EnForm::class,
            'etymology_model' => EnEtymology::class,
            'transcription_model' => EnTranscription::class,
            'transcription_type_model' => EnTranscriptionType::class,
            'transcription_type_fk' => 'en_transcription_type_id',
            'word_fk' => 'en_word_id',
        ],
        'ru' => [
            'word_model' => RuWord::class,
            'word_class_model' => RuWordClass::class,
            'word_class_fk' => 'ru_word_class_id',
            'definition_model' => RuDefinition::class,
            'form_model' => RuForm::class,
            'etymology_model' => RuEtymology::class,
            'transcription_model' => RuTranscription::class,
            'transcription_type_model' => RuTranscriptionType::class,
            'transcription_type_fk' => 'ru_transcription_type_id',
            'word_fk' => 'ru_word_id',
        ],
    ];

    private string $lang;

    private string $targetLang;

    private int $batchSize;

    private array $config;

    private array $wordClassMap = [];

    private array $transcriptionTypeMap = [];

    private array $stats = [
        'lines_read' => 0,
        'words_imported' => 0,
        'words_skipped_pos' => 0,
        'batches_flushed' => 0,
    ];

    public function __construct(string $lang = 'en', string $targetLang = 'ru', int $batchSize = 500)
    {
        $this->lang = $lang;
        $this->targetLang = $targetLang;
        $this->batchSize = $batchSize;
        $this->config = self::LANG_CONFIG[$lang] ?? throw new \InvalidArgumentException("Unsupported language: {$lang}");
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
                $this->stats['words_skipped_pos']++;

                continue;
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
        $handle = fopen($path, 'r');
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
            'l_word' => mb_strtolower($word),
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
                if (($translation->code ?? null) === $this->targetLang && ($translation->word ?? null) !== null && trim($translation->word) !== '') {
                    $translations[] = $translation->word;
                }
            }
        }
        foreach ($line->translations ?? [] as $translation) {
            if (($translation->code ?? null) === $this->targetLang && ($translation->word ?? null) !== null && trim($translation->word) !== '') {
                $translations[] = $translation->word;
            }
        }

        return array_values(array_unique($translations));
    }

    private function loadLookupMaps(): void
    {
        $wordClassModel = $this->config['word_class_model'];
        $this->wordClassMap = $wordClassModel::pluck('id', 'slug')->toArray();

        if (empty($this->wordClassMap)) {
            throw new \RuntimeException('No word classes found. Run the word class seeder first.');
        }

        $transcriptionTypeModel = $this->config['transcription_type_model'];
        $this->transcriptionTypeMap = $transcriptionTypeModel::pluck('id', 'slug')->toArray();
        if (empty($this->transcriptionTypeMap)) {
            throw new \RuntimeException('No transcription types found. Run the transcription type seeder first.');
        }
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
            $wordModel = $this->config['word_model'];
            $definitionModel = $this->config['definition_model'];
            $formModel = $this->config['form_model'];
            $etymologyModel = $this->config['etymology_model'];
            $transcriptionModel = $this->config['transcription_model'];
            $wordFk = $this->config['word_fk'];
            $wordClassFk = $this->config['word_class_fk'];
            $transcriptionTypeFk = $this->config['transcription_type_fk'];

            $defaultWordClassId = $this->wordClassMap['unknown'] ?? reset($this->wordClassMap);

            $wordUpserts = [];
            foreach ($batch as $record) {
                $posId = $this->wordClassMap[$record['pos']] ?? $defaultWordClassId;
                $wordUpserts[] = [
                    'word' => $record['word'],
                    'l_word' => $record['l_word'],
                    $wordClassFk => $posId,
                    'translations' => ! empty($record['translations']) ? json_encode($record['translations']) : null,
                ];
            }

            $this->uniqueByCompound($wordUpserts, ['word', $wordClassFk]);
            $wordModel::upsert($wordUpserts, ['word', $wordClassFk]);

            $this->stats['words_imported'] += count($wordUpserts);

            $wordIds = $wordModel::whereIn('word', collect($batch)->pluck('word')->unique()->toArray())
                ->get(['id', 'word', $wordClassFk])
                ->keyBy(fn ($w) => mb_strtolower($w->word).'|'.$w->{$wordClassFk});

            $this->flushDefinitions($batch, $wordIds, $wordClassFk, $defaultWordClassId, $definitionModel, $wordFk);
            $this->flushForms($batch, $wordIds, $wordClassFk, $defaultWordClassId, $formModel, $wordFk);
            $this->flushEtymologies($batch, $wordIds, $wordClassFk, $defaultWordClassId, $etymologyModel, $wordFk);
            $this->flushTranscriptions($batch, $wordIds, $wordClassFk, $defaultWordClassId, $transcriptionModel, $wordFk, $transcriptionTypeFk);
        });
    }

    private function flushDefinitions(array $batch, object $wordIds, string $wordClassFk, int $defaultWordClassId, string $definitionModel, string $wordFk): void
    {
        $rows = [];
        foreach ($batch as $record) {
            $posId = $this->wordClassMap[$record['pos']] ?? $defaultWordClassId;
            $lookupKey = mb_strtolower($record['word']).'|'.$posId;
            $wordId = $wordIds[$lookupKey]->id ?? null;
            if ($wordId === null) {
                continue;
            }
            foreach ($record['definitions'] as $definition) {
                $rows[] = [
                    'definition' => mb_substr($definition, 0, 500),
                    $wordFk => $wordId,
                ];
            }
        }
        if (! empty($rows)) {
            $this->uniqueByCompound($rows, ['definition', $wordFk]);
            $this->insertNewOnly($definitionModel, $rows, 'definition', $wordFk);
        }
    }

    private function flushForms(array $batch, object $wordIds, string $wordClassFk, int $defaultWordClassId, string $formModel, string $wordFk): void
    {
        $rows = [];
        foreach ($batch as $record) {
            $posId = $this->wordClassMap[$record['pos']] ?? $defaultWordClassId;
            $lookupKey = mb_strtolower($record['word']).'|'.$posId;
            $wordId = $wordIds[$lookupKey]->id ?? null;
            if ($wordId === null) {
                continue;
            }
            foreach ($record['forms'] as $form) {
                $rows[] = [
                    'form' => mb_substr($form, 0, 256),
                    'l_word' => mb_strtolower(mb_substr($form, 0, 256)),
                    $wordFk => $wordId,
                ];
            }
        }
        if (! empty($rows)) {
            $this->uniqueByCompound($rows, ['form', $wordFk]);
            $formModel::upsert($rows, ['form', $wordFk]);
        }
    }

    private function flushEtymologies(array $batch, object $wordIds, string $wordClassFk, int $defaultWordClassId, string $etymologyModel, string $wordFk): void
    {
        $rows = [];
        foreach ($batch as $record) {
            $posId = $this->wordClassMap[$record['pos']] ?? $defaultWordClassId;
            $lookupKey = mb_strtolower($record['word']).'|'.$posId;
            $wordId = $wordIds[$lookupKey]->id ?? null;
            if ($wordId === null || $record['etymology'] === null) {
                continue;
            }
            $rows[] = [
                'etymology' => mb_substr($record['etymology'], 0, 1000),
                $wordFk => $wordId,
            ];
        }
        if (! empty($rows)) {
            $this->uniqueByCompound($rows, ['etymology', $wordFk]);
            $this->insertNewOnly($etymologyModel, $rows, 'etymology', $wordFk);
        }
    }

    private function flushTranscriptions(array $batch, object $wordIds, string $wordClassFk, int $defaultWordClassId, string $transcriptionModel, string $wordFk, string $transcriptionTypeFk): void
    {
        $rows = [];
        foreach ($batch as $record) {
            $posId = $this->wordClassMap[$record['pos']] ?? $defaultWordClassId;
            $lookupKey = mb_strtolower($record['word']).'|'.$posId;
            $wordId = $wordIds[$lookupKey]->id ?? null;
            if ($wordId === null) {
                continue;
            }
            foreach ($record['sounds'] as $sound) {
                $typeSlug = $sound['type'];
                $typeId = $this->transcriptionTypeMap[$typeSlug] ?? null;
                if ($typeId === null) {
                    continue;
                }
                $rows[] = [
                    'transcription' => mb_substr($sound['value'], 0, 100),
                    $wordFk => $wordId,
                    $transcriptionTypeFk => $typeId,
                ];
            }
        }
        if (! empty($rows)) {
            $this->uniqueByCompound($rows, ['transcription', $wordFk, $transcriptionTypeFk]);
            $transcriptionModel::upsert($rows, ['transcription', $wordFk, $transcriptionTypeFk]);
        }
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
