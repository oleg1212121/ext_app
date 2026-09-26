<?php

namespace App\Jobs;

use App\Classes\EntityTextHasher;
use App\Classes\TextSignatureService;
use App\Models\Entity;
use App\Models\EntityWord;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Final stage of the upload pipeline, chained after the sentence split:
 * computes the entity's text hash, then fills in the remaining derivations —
 * copying the signature vector and word statistics from an exact-copy source
 * when one exists (skipping the Python embed call entirely), or generating the
 * signature from the uploaded file (ADR 0033).
 */
#[Queue(QueueLane::DEFAULT)]
class FinalizeEntityDerivations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public int $tries = 5;

    public function __construct(
        private readonly int $entityId,
        private readonly string $filePath,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 60, 120, 300];
    }

    public function handle(EntityTextHasher $hasher): void
    {
        $entity = Entity::with('language')->findOrFail($this->entityId);

        $textHash = $hasher->refreshIfStale($entity);

        $source = Entity::query()
            ->where('language_id', $entity->language_id)
            ->where('id', '!=', $entity->id)
            ->where('text_hash', $textHash)
            ->whereHas('sentences')
            ->orderByRaw('signature IS NULL')
            ->orderByRaw('words_indexed_at IS NULL')
            ->orderByDesc('id')
            ->first();

        $copiedSignature = $this->copySignature($entity, $source);
        $copiedWords = $this->copyWordStatistics($entity, $source);

        if (! $copiedSignature && $entity->signature === null) {
            $content = TextSignatureService::readFileFromLocalPath($this->filePath);
            $signature = TextSignatureService::create()
                ->generateSignature($content, $entity->language->code);

            if ($signature === null) {
                throw new \RuntimeException(
                    "Failed to generate signature for entity {$this->entityId}"
                );
            }

            $entity->update(['signature' => json_encode($signature)]);
        }

        Log::info('FinalizeEntityDerivations completed', [
            'entity_id' => $this->entityId,
            'text_hash' => $textHash,
            'copy_source_id' => $source?->id,
            'copied_signature' => $copiedSignature,
            'copied_words' => $copiedWords,
        ]);
    }

    private function copySignature(Entity $entity, ?Entity $source): bool
    {
        if ($source === null || $source->signature === null || $entity->signature !== null) {
            return false;
        }

        $entity->update(['signature' => $source->signature]);

        return true;
    }

    /**
     * Copy the crossword/reader word statistics when the entity has none of
     * its own yet and the exact-copy source has a built index.
     */
    private function copyWordStatistics(Entity $entity, ?Entity $source): bool
    {
        if ($source === null
            || $source->words_indexed_at === null
            || $entity->entityWords()->exists()) {
            return false;
        }

        $now = now();

        $source->entityWords()
            ->chunk(500, function ($words) use ($entity, $now): void {
                EntityWord::query()->insert($words->map(fn ($word): array => [
                    'entity_id' => $entity->id,
                    'word_id' => $word->word_id,
                    'l_word' => $word->l_word,
                    'token' => $word->token,
                    'count' => $word->count,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });

        $entity->update(['words_indexed_at' => $source->words_indexed_at]);

        return true;
    }
}
