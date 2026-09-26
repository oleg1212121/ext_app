<?php

namespace App\Classes;

use App\Models\Entity;
use App\Models\EntitySentence;
use App\Models\SentenceType;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SentenceSplitter
{
    public function __construct(private readonly SparseOrderService $sparseOrder) {}

    private const BATCH_SIZE = 500;

    private const DEFAULT_CHUNK_SIZE = 262_144;

    /**
     * Byte chunks one run feeds to python before handing control back to the
     * queue (0 = split the whole file in one run). A resumed run continues
     * from the offset and remainder the last committed chunk persisted, so
     * retries and re-dispatches never re-split committed text.
     */
    private const DEFAULT_MAX_CHUNKS_PER_RUN = 8;

    private const RETRY_DELAYS_MS = [500, 1_500, 3_000];

    private array $sentenceTypeMap = [];

    public function process(int $entityId, string $filePath, ?string $fileContent = null): array
    {
        $entity = Entity::with('language')->findOrFail($entityId);
        $lang = $entity->language->code;

        $this->loadSentenceTypeMap();

        $stats = $fileContent !== null
            ? $this->insertSentences($entityId, $fileContent, $lang)
            : $this->insertSentencesFromFile($entity, $filePath, $lang);

        // Bulk inserts bypass model events, so mark the sentence set changed
        // explicitly — the entity's text hash is now stale.
        $entity->touchSentences();

        return $stats;
    }

    private function loadSentenceTypeMap(): void
    {
        $this->sentenceTypeMap = SentenceType::pluck('id', 'name')->toArray();

        if (empty($this->sentenceTypeMap)) {
            throw new \RuntimeException('No sentence types found. Run the SentenceTypeSeeder first.');
        }
    }

    private function insertSentences(int $entityId, string $content, string $lang): array
    {
        $defaultTypeId = $this->sentenceTypeMap['sentence'];
        $batch = [];
        $order = 0;
        $stats = ['sentences' => 0, 'batches' => 0, 'bytes_read' => strlen($content), 'max_buffer_bytes' => strlen($content), 'eof' => true];

        $result = $this->splitViaPython($content, $lang, true);

        foreach ($result['sentences'] as $sentence) {
            $this->appendSentenceToBatch($sentence, $entityId, $defaultTypeId, $batch, $order);

            if (count($batch) >= self::BATCH_SIZE) {
                $this->flushBatch($batch, $stats);
            }
        }

        $this->flushBatch($batch, $stats);
        $stats['sentences'] = $order;

        return $stats;
    }

    /**
     * Split the file in bounded runs. Each byte chunk commits its sentences
     * together with the resume point (consumed byte offset + python's
     * unsplittable remainder) in one transaction, so a crash or run-budget
     * stop never duplicates or loses sentences: on resume the file is
     * re-opened at the offset (the trailing UTF-8 carry bytes are re-read as
     * part of the next chunk) and the remainder is re-fed ahead of it.
     */
    private function insertSentencesFromFile(Entity $entity, string $filePath, string $lang): array
    {
        $defaultTypeId = $this->sentenceTypeMap['sentence'];
        $chunkSize = max(1, (int) config('services.python.sentence_split_chunk_bytes', self::DEFAULT_CHUNK_SIZE));
        $maxChunks = max(0, (int) config('services.python.sentence_split_chunks_per_run', self::DEFAULT_MAX_CHUNKS_PER_RUN));

        $fullPath = Storage::disk('local')->path($filePath);
        if (! file_exists($fullPath)) {
            throw new \RuntimeException("File not found: {$fullPath}");
        }

        $offset = $entity->split_offset;
        $remainder = (string) $entity->split_remainder;
        $freshRun = $offset === 0 && $remainder === '';

        $handle = fopen($fullPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Cannot read file: {$fullPath}");
        }

        if (fseek($handle, $offset) !== 0) {
            fclose($handle);
            throw new \RuntimeException("Cannot seek to offset {$offset} in {$fullPath}");
        }

        $batch = [];
        $rawCarry = '';
        $order = $freshRun ? 0 : $entity->sentences()->count();
        $chunksThisRun = 0;
        $eof = false;
        $stats = ['sentences' => 0, 'batches' => 0, 'bytes_read' => 0, 'max_buffer_bytes' => 0, 'eof' => false];

        try {
            while (! feof($handle)) {
                $raw = fread($handle, $chunkSize);
                if ($raw === false) {
                    throw new \RuntimeException("Cannot read file chunk: {$fullPath}");
                }

                if ($raw === '') {
                    $eof = true;

                    break;
                }

                $stats['bytes_read'] += strlen($raw);

                // fread may cut a multi-byte UTF-8 character at the chunk edge;
                // hold the incomplete trailing bytes for the next iteration.
                $combined = $rawCarry.$raw;
                $carry = $this->carryIncompleteTrailingBytes($combined);
                $rawCarry = $carry;
                $chunk = substr($combined, 0, strlen($combined) - strlen($carry));

                $buffer = $remainder.$chunk;
                $stats['max_buffer_bytes'] = max($stats['max_buffer_bytes'], strlen($buffer));

                if (trim($buffer) !== '') {
                    $result = $this->splitViaPython($buffer, $lang, false);
                    $remainder = $result['remainder'];
                    $this->appendSentences($result['sentences'], $entity->id, $defaultTypeId, $batch, $order);
                }

                $chunksThisRun++;
                $this->commitChunk($entity->id, $batch, (int) ftell($handle) - strlen($rawCarry), $remainder, $freshRun);
                $freshRun = false;

                if ($maxChunks > 0 && $chunksThisRun >= $maxChunks) {
                    break;
                }
            }

            if ($eof || feof($handle)) {
                // End of file: the carry and the tail remainder were never
                // sentence-delimited — finalize them through python, then
                // persist the completion point (offset = file size, no
                // remainder) so no run ever revisits this file.
                $remainder .= $rawCarry;

                if (trim($remainder) !== '') {
                    $result = $this->splitViaPython($remainder, $lang, true);
                    $this->appendSentences($result['sentences'], $entity->id, $defaultTypeId, $batch, $order);
                }

                $this->commitChunk($entity->id, $batch, (int) filesize($fullPath), '');
                $eof = true;
            }

            $stats['sentences'] = $order;
            $stats['eof'] = $eof;
        } finally {
            fclose($handle);
        }

        return $stats;
    }

    /**
     * @param  list<array{content: string, type: string}>  $sentences
     */
    private function appendSentences(array $sentences, int $entityId, int $defaultTypeId, array &$batch, int &$order): void
    {
        foreach ($sentences as $sentence) {
            $this->appendSentenceToBatch($sentence, $entityId, $defaultTypeId, $batch, $order);
        }
    }

    /**
     * Commit one chunk atomically: whatever sentences accumulated for it
     * (a fresh run first clears the entity's previous sentence set in the
     * same transaction) plus the resume point. A run that dies mid-chunk
     * resumes from the previous chunk boundary.
     */
    private function commitChunk(int $entityId, array &$batch, int $offset, string $remainder, bool $freshRun = false): void
    {
        DB::transaction(function () use ($entityId, &$batch, $offset, $remainder, $freshRun): void {
            if ($freshRun) {
                EntitySentence::query()->where('entity_id', $entityId)->delete();
            }

            if ($batch !== []) {
                EntitySentence::insert($batch);
            }

            Entity::query()
                ->whereKey($entityId)
                ->update([
                    'split_offset' => $offset,
                    'split_remainder' => $remainder,
                    'sentences_updated_at' => now(),
                ]);

            $batch = [];
        });
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

    private function decodeHtmlEntities(string $text): string
    {
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
    }

    /**
     * @return array{sentences: list<array{content: string, type: string}>, remainder: string}
     */
    private function splitViaPython(string $text, string $lang, bool $finalize): array
    {
        $text = $this->decodeHtmlEntities($text);

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
    private function appendSentenceToBatch(array $sentence, int $entityId, int $defaultTypeId, array &$batch, int &$order): void
    {
        $typeId = $this->sentenceTypeMap[$sentence['type']] ?? $defaultTypeId;

        $batch[] = [
            'entity_id' => $entityId,
            'sentence_type_id' => $typeId,
            'content' => $sentence['content'],
            'order' => $this->sparseOrder->initial($order),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $order++;
    }

    private function flushBatch(array &$batch, array &$stats): void
    {
        if ($batch === []) {
            return;
        }

        DB::transaction(fn () => EntitySentence::insert($batch));

        $stats['batches']++;
        $batch = [];
    }
}
