<?php

namespace App\Classes;

use App\Models\EnEntity;
use App\Models\EnEntitySentence;
use App\Models\RuEntity;
use App\Models\RuEntitySentence;
use App\Models\SentenceType;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SentenceSplitter
{
    private const BATCH_SIZE = 500;

    private const DEFAULT_CHUNK_SIZE = 262_144;

    private const RETRY_DELAYS_MS = [500, 1_500, 3_000];

    private const LANG_CONFIG = [
        'en' => [
            'entity_model' => EnEntity::class,
            'sentence_model' => EnEntitySentence::class,
            'entity_fk' => 'en_entity_id',
        ],
        'ru' => [
            'entity_model' => RuEntity::class,
            'sentence_model' => RuEntitySentence::class,
            'entity_fk' => 'ru_entity_id',
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

        if ($fileContent !== null) {
            return $this->insertSentences($entityId, $fileContent, $lang, $config);
        }

        return $this->insertSentencesFromFile($entityId, $filePath, $lang, $config);
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

        $defaultTypeId = $this->sentenceTypeMap['sentence'];
        $batch = [];
        $order = 0;
        $stats = ['sentences' => 0, 'batches' => 0, 'bytes_read' => strlen($content), 'max_buffer_bytes' => strlen($content)];

        $result = $this->splitViaPython($content, $lang, true);

        foreach ($result['sentences'] as $sentence) {
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
        $defaultTypeId = $this->sentenceTypeMap['sentence'];
        $chunkSize = max(1, (int) config('services.python.sentence_split_chunk_bytes', self::DEFAULT_CHUNK_SIZE));

        $fullPath = Storage::disk('local')->path($filePath);
        if (! file_exists($fullPath)) {
            throw new \RuntimeException("File not found: {$fullPath}");
        }

        $handle = fopen($fullPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Cannot read file: {$fullPath}");
        }

        $batch = [];
        $remainder = '';
        $rawCarry = '';
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

                // fread may cut a multi-byte UTF-8 character at the chunk edge;
                // hold the incomplete trailing bytes for the next iteration.
                $chunk = $rawCarry.$chunk;
                $carry = $this->carryIncompleteTrailingBytes($chunk);
                $chunk = substr($chunk, 0, strlen($chunk) - strlen($carry));
                $rawCarry = $carry;

                $buffer = $remainder.$chunk;
                $stats['max_buffer_bytes'] = max($stats['max_buffer_bytes'], strlen($buffer));

                if ($buffer === '') {
                    continue;
                }

                $result = $this->splitViaPython($buffer, $lang, false);
                $remainder = $result['remainder'];

                foreach ($result['sentences'] as $sentence) {
                    $this->appendSentenceToBatch($sentence, $entityId, $entityFk, $defaultTypeId, $batch, $order);

                    if (count($batch) >= self::BATCH_SIZE) {
                        $this->flushBatch($sentenceModel, $batch, $stats);
                    }
                }
            }

            $remainder .= $rawCarry;

            if (trim($remainder) !== '') {
                $result = $this->splitViaPython($remainder, $lang, true);

                foreach ($result['sentences'] as $sentence) {
                    $this->appendSentenceToBatch($sentence, $entityId, $entityFk, $defaultTypeId, $batch, $order);

                    if (count($batch) >= self::BATCH_SIZE) {
                        $this->flushBatch($sentenceModel, $batch, $stats);
                    }
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
     * Returns the incomplete trailing UTF-8 sequence of $chunk (at most 3 bytes)
     * so it can be carried over to the next chunk. mb_strcut() keeps a partial
     * trailing character, so it cannot be used to trim chunk edges.
     */
    private function carryIncompleteTrailingBytes(string $chunk): string
    {
        $length = strlen($chunk);

        for ($i = $length - 1; $i >= max(0, $length - 4); $i--) {
            $byte = ord($chunk[$i]);

            if ($byte < 0x80) {
                break;
            }

            if ($byte >= 0xC0) {
                $expected = $byte >= 0xF0 ? 3 : ($byte >= 0xE0 ? 2 : 1);

                if ($length - ($i + 1) < $expected) {
                    return substr($chunk, $i);
                }

                break;
            }
        }

        return '';
    }

    /**
     * @return array{sentences: list<array{content: string, type: string}>, remainder: string}
     */
    private function splitViaPython(string $text, string $lang, bool $finalize): array
    {
        $response = Http::timeout((int) config('services.python.timeout', 30))
            ->retry(
                self::RETRY_DELAYS_MS,
                0,
                fn (Throwable $exception, PendingRequest $request): bool => $exception instanceof ConnectionException,
                false,
            )
            ->post(config('services.python.url', 'http://ext_python:8000').'/split', [
                'text' => $text,
                'language' => $lang,
                'finalize' => $finalize,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Python split service error: {$response->status()} - {$response->body()}"
            );
        }

        return [
            'sentences' => $response->json('sentences', []),
            'remainder' => (string) $response->json('remainder', ''),
        ];
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
}
