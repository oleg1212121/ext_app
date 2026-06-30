<?php

namespace App\Classes;

use App\Models\EnEntity;
use App\Models\EnEntitySentence;
use App\Models\RuEntity;
use App\Models\RuEntitySentence;
use App\Models\SentenceType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SentenceSplitter
{
    private const BATCH_SIZE = 500;

    private const DEFAULT_CHUNK_SIZE = 262_144;

    private const TITLE_MAX_LENGTH = 120;

    private const SENTENCE_TERMINATORS = ['.' => true, '!' => true, '?' => true, '…' => true];

    private const CLOSING_BOUNDARY_CHARS = [
        '"' => true,
        "'" => true,
        ')' => true,
        ']' => true,
        '}' => true,
        '»' => true,
        '”' => true,
        '’' => true,
    ];

    private const LANG_CONFIG = [
        'en' => [
            'entity_model' => EnEntity::class,
            'sentence_model' => EnEntitySentence::class,
            'entity_fk' => 'en_entity_id',
            'abbreviations' => [
                'mr', 'mrs', 'ms', 'dr', 'prof', 'sr', 'jr',
                'st', 'ave', 'blvd', 'rd', 'ln', 'ct',
                'vol', 'rev', 'gen', 'sgt', 'cpl', 'pvt',
                'est', 'dept', 'govt', 'inc', 'ltd', 'corp',
                'vs', 'etc', 'approx', 'dept', 'min', 'max',
                'jan', 'feb', 'mar', 'apr', 'jun', 'jul',
                'aug', 'sep', 'oct', 'nov', 'dec',
                'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun',
                'no', 'nos', 'al', 'approx', 'apt',
                'e.g', 'i.e', 'cf', 'viz', 're',
            ],
        ],
        'ru' => [
            'entity_model' => RuEntity::class,
            'sentence_model' => RuEntitySentence::class,
            'entity_fk' => 'ru_entity_id',
            'abbreviations' => [
                'г', 'гг', 'в', 'вв', 'т', 'тт', 'д', 'др',
                'гл', 'обр', 'стр', 'ст', 'стр', 'см',
                'им', 'напр', 'пр', 'и.о', 'т.е', 'т.к', 'т.н',
                'ж.д', 'р-н', 'обл', 'ул', 'пер', 'пр',
                'н.э', 'до.н.э', 'руб', 'коп', 'тыс', 'млн', 'млрд',
                'кв', 'куб', 'м', 'см', 'мм', 'км',
                'с', 'р', 'ок', 'ч', 'чч',
            ],
        ],
    ];

    private array $sentenceTypeMap = [];

    public function process(int $entityId, string $filePath, string $lang, ?string $fileContent = null): array
    {
        $config = self::LANG_CONFIG[$lang] ?? throw new \InvalidArgumentException("Unsupported language: {$lang}");

        $entityModel = $config['entity_model'];
        $entity = $entityModel::findOrFail($entityId);

        $this->loadSentenceTypeMap();

        $entity->sentences()->delete();

        if ($fileContent === null) {
            return $this->insertSentencesFromFile($entityId, $filePath, $lang, $config);
        }

        return $this->insertSentences($entityId, $fileContent, $lang, $config);
    }

    private function loadSentenceTypeMap(): void
    {
        $this->sentenceTypeMap = SentenceType::pluck('id', 'name')->toArray();

        if (empty($this->sentenceTypeMap)) {
            throw new \RuntimeException('No sentence types found. Run the SentenceTypeSeeder first.');
        }
    }

    private function insertSentences(int $entityId, string $content, string $lang, array $config): array
    {
        $sentenceModel = $config['sentence_model'];
        $entityFk = $config['entity_fk'];
        $abbreviations = $config['abbreviations'];

        $defaultTypeId = $this->sentenceTypeMap['sentence'];
        $batch = [];
        $order = 0;
        $stats = ['sentences' => 0, 'batches' => 0, 'bytes_read' => strlen($content), 'max_buffer_bytes' => strlen($content)];

        foreach ($this->split($content, $lang, $abbreviations) as $sentence) {
            $this->appendSentenceToBatch($sentence, $entityId, $entityFk, $defaultTypeId, $batch, $order);

            if (count($batch) >= self::BATCH_SIZE) {
                $this->flushBatch($sentenceModel, $batch, $stats);
            }
        }

        $this->flushBatch($sentenceModel, $batch, $stats);
        $stats['sentences'] = $order;

        return $stats;
    }

    private function insertSentencesFromFile(int $entityId, string $filePath, string $lang, array $config): array
    {
        $sentenceModel = $config['sentence_model'];
        $entityFk = $config['entity_fk'];
        $abbreviations = $config['abbreviations'];
        $defaultTypeId = $this->sentenceTypeMap['sentence'];
        $chunkSize = max(1, (int) config('services.embedding.sentence_split_chunk_bytes', self::DEFAULT_CHUNK_SIZE));

        $fullPath = Storage::disk('local')->path($filePath);
        if (! file_exists($fullPath)) {
            throw new \RuntimeException("File not found: {$fullPath}");
        }

        $handle = fopen($fullPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Cannot read file: {$fullPath}");
        }

        $batch = [];
        $buffer = '';
        $order = 0;
        $stats = ['sentences' => 0, 'batches' => 0, 'bytes_read' => 0, 'max_buffer_bytes' => 0];

        try {
            while (! feof($handle)) {
                $chunk = fread($handle, $chunkSize);
                if ($chunk === false) {
                    throw new \RuntimeException("Cannot read file chunk: {$fullPath}");
                }

                if ($chunk === '') {
                    continue;
                }

                $stats['bytes_read'] += strlen($chunk);
                $buffer = $this->normalizeStreamingWhitespace($buffer.$chunk);
                $stats['max_buffer_bytes'] = max($stats['max_buffer_bytes'], strlen($buffer));

                foreach ($this->extractCompleteSentences($buffer, $abbreviations) as $sentence) {
                    $this->appendSentenceToBatch($sentence, $entityId, $entityFk, $defaultTypeId, $batch, $order);

                    if (count($batch) >= self::BATCH_SIZE) {
                        $this->flushBatch($sentenceModel, $batch, $stats);
                    }
                }
            }

            foreach ($this->split($buffer, $lang, $abbreviations) as $sentence) {
                $this->appendSentenceToBatch($sentence, $entityId, $entityFk, $defaultTypeId, $batch, $order);

                if (count($batch) >= self::BATCH_SIZE) {
                    $this->flushBatch($sentenceModel, $batch, $stats);
                }
            }

            $this->flushBatch($sentenceModel, $batch, $stats);
            $stats['sentences'] = $order;
        } finally {
            fclose($handle);
        }

        return $stats;
    }

    /**
     * @param  array{content: string, type: string}  $sentence
     */
    private function appendSentenceToBatch(array $sentence, int $entityId, string $entityFk, int $defaultTypeId, array &$batch, int &$order): void
    {
        $order++;
        $typeId = $this->sentenceTypeMap[$sentence['type']] ?? $defaultTypeId;

        $batch[] = [
            $entityFk => $entityId,
            'sentence_type_id' => $typeId,
            'content' => $sentence['content'],
            'order' => $order,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function flushBatch(string $sentenceModel, array &$batch, array &$stats): void
    {
        if ($batch === []) {
            return;
        }

        DB::transaction(fn () => $sentenceModel::insert($batch));

        $stats['batches']++;
        $batch = [];
    }

    public function split(string $content, string $lang, array $abbreviations): \Generator
    {
        $content = $this->normalizeWhitespace($content);

        foreach ($this->splitCompleteSegments($content, $abbreviations) as $sentence) {
            yield $sentence;
        }
    }

    private function extractCompleteSentences(string &$buffer, array $abbreviations): \Generator
    {
        foreach ($this->splitCompleteSegments($buffer, $abbreviations, true) as $sentence) {
            yield $sentence;
        }
    }

    private function splitCompleteSegments(string &$content, array $abbreviations, bool $keepRemainder = false): \Generator
    {
        $chars = preg_split('//u', $content, -1, PREG_SPLIT_NO_EMPTY);

        if ($chars === false || $chars === []) {
            return;
        }

        $segmentStart = 0;
        $count = count($chars);

        for ($index = 0; $index < $count; $index++) {
            if ($chars[$index] === "\n") {
                $line = $this->normalizeSentenceContent($chars, $segmentStart, $index - 1);

                if (
                    $this->isLikelyTitleLine($line)
                    && $this->hasNonWhitespaceAfter($chars, $index + 1)
                ) {
                    yield [
                        'content' => $line,
                        'type' => 'title',
                    ];

                    $segmentStart = $this->skipWhitespace($chars, $index + 1);
                    $index = $segmentStart - 1;
                }

                continue;
            }

            $boundaryEnd = $this->sentenceBoundaryEnd($chars, $index, $abbreviations);

            if ($boundaryEnd === null) {
                continue;
            }

            if ($keepRemainder && $boundaryEnd + 1 >= $count) {
                break;
            }

            $sentence = $this->normalizeSentenceContent($chars, $segmentStart, $boundaryEnd);

            if ($sentence !== '') {
                yield [
                    'content' => $sentence,
                    'type' => $this->predictType($sentence),
                ];
            }

            $segmentStart = $this->skipWhitespace($chars, $boundaryEnd + 1);
            $index = $segmentStart - 1;
        }

        $remainder = implode('', array_slice($chars, $segmentStart));

        if ($keepRemainder) {
            $content = ltrim($remainder);

            return;
        }

        $sentence = $this->normalizeSentenceContent($chars, $segmentStart, $count - 1);

        if ($sentence !== '') {
            yield [
                'content' => $sentence,
                'type' => $this->isLikelyTitleLine($sentence) ? 'title' : $this->predictType($sentence),
            ];
        }
    }

    private function normalizeWhitespace(string $text): string
    {
        $text = $this->normalizeStreamingWhitespace($text);
        $text = trim($text);

        return $text;
    }

    private function normalizeStreamingWhitespace(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return $text;
    }

    private function sentenceBoundaryEnd(array $chars, int $index, array $abbreviations): ?int
    {
        if (! isset(self::SENTENCE_TERMINATORS[$chars[$index]])) {
            return null;
        }

        if ($chars[$index] === '.' && $this->isAbbreviationPeriod($chars, $index, $abbreviations)) {
            return null;
        }

        $boundaryEnd = $index;
        $count = count($chars);

        while (
            $boundaryEnd + 1 < $count
            && isset(self::CLOSING_BOUNDARY_CHARS[$chars[$boundaryEnd + 1]])
        ) {
            $boundaryEnd++;
        }

        if ($boundaryEnd + 1 >= $count || $this->isWhitespace($chars[$boundaryEnd + 1])) {
            return $boundaryEnd;
        }

        return null;
    }

    private function isAbbreviationPeriod(array $chars, int $periodIndex, array $abbreviations): bool
    {
        $start = $periodIndex;

        while (
            $start > 0
            && preg_match('/[\p{L}.]/u', $chars[$start - 1]) === 1
        ) {
            $start--;
        }

        $candidate = mb_strtolower(rtrim(
            implode('', array_slice($chars, $start, $periodIndex - $start + 1)),
            '.'
        ));

        foreach ($abbreviations as $abbreviation) {
            if ($candidate === mb_strtolower(rtrim($abbreviation, '.'))) {
                return true;
            }
        }

        return false;
    }

    private function normalizeSentenceContent(array $chars, int $start, int $end): string
    {
        if ($end < $start) {
            return '';
        }

        $text = trim(implode('', array_slice($chars, $start, $end - $start + 1)));
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text ?? '');
    }

    private function skipWhitespace(array $chars, int $index): int
    {
        $count = count($chars);

        while ($index < $count && $this->isWhitespace($chars[$index])) {
            $index++;
        }

        return $index;
    }

    private function hasNonWhitespaceAfter(array $chars, int $index): bool
    {
        $count = count($chars);

        while ($index < $count) {
            if (! $this->isWhitespace($chars[$index])) {
                return true;
            }

            $index++;
        }

        return false;
    }

    private function isWhitespace(string $char): bool
    {
        return preg_match('/^\s$/u', $char) === 1;
    }

    private function isLikelyTitleLine(string $line): bool
    {
        $line = trim($line);
        $length = mb_strlen($line);

        if ($length === 0 || $length > self::TITLE_MAX_LENGTH) {
            return false;
        }

        if (! preg_match('/\p{L}/u', $line)) {
            return false;
        }

        if (preg_match('/[.!?…:;,]$/u', $line)) {
            return false;
        }

        if (preg_match('/^[\p{Ll}]/u', $line)) {
            return false;
        }

        if (preg_match('/^(chapter|part|book|volume|section|глава|часть|книга|раздел)\b/iu', $line)) {
            return true;
        }

        $letters = preg_replace('/[^\p{L}]/u', '', $line) ?? '';
        $upperLetters = preg_replace('/[^\p{Lu}]/u', '', $line) ?? '';

        if (mb_strlen($letters) > 3 && mb_strlen($upperLetters) / mb_strlen($letters) > 0.7) {
            return true;
        }

        $words = preg_split('/\s+/u', $line, -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false || count($words) > 12) {
            return false;
        }

        $titleCaseWords = 0;

        foreach ($words as $word) {
            if (preg_match('/^(?:[\p{Lu}\d]|[IVXLCDM]+$)/u', $word) === 1) {
                $titleCaseWords++;
            }
        }

        return $titleCaseWords / count($words) >= 0.6;
    }

    private function predictType(string $sentence): string
    {
        $length = mb_strlen(trim($sentence));

        if ($length < 80) {
            $trimmed = preg_replace('/^[\p{Pi}\p{Pf}\s]+|[\p{Pi}\p{Pf}\s]+$/u', '', $sentence);
            $letters = preg_replace('/[^A-Za-zА-Яа-яЁё]/u', '', $trimmed);
            $upperLetters = preg_replace('/[^A-ZА-ЯЁ]/u', '', $trimmed);

            if (mb_strlen($letters) > 3 && mb_strlen($upperLetters) / mb_strlen($letters) > 0.7) {
                return 'title';
            }
        }

        $quotePatterns = [
            '/^"[^"]+"$/u',
            "/^'[^']+'$/u",
            '/^[\x{00AB}][^\x{00BB}]+[\x{00BB}]$/u',
            '/^[\x{201C}][^\x{201D}]+[\x{201D}]$/u',
            '/^[\x{2018}][^\x{2019}]+[\x{2019}]$/u',
        ];

        foreach ($quotePatterns as $pattern) {
            if (preg_match($pattern, $sentence)) {
                return 'quote';
            }
        }

        return 'sentence';
    }
}
