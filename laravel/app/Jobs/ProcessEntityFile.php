<?php

namespace App\Jobs;

use App\Classes\EntityAccessService;
use App\Classes\TextSignatureService;
use App\Models\Entity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessEntityFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 5;

    public function __construct(
        private int $entityId,
        private string $filePath,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 60, 120, 300];
    }

    public function handle(): void
    {
        $tPipeline = microtime(true);

        $entity = Entity::with('language')->findOrFail($this->entityId);
        $signatureService = TextSignatureService::create();

        $tRead = microtime(true);
        $content = TextSignatureService::readFileFromLocalPath($this->filePath);
        $readMs = (int) round((microtime(true) - $tRead) * 1000);

        $signature = $entity->signature !== null
            ? json_decode($entity->signature, true)
            : $signatureService->generateSignature($content, $entity->language->code);
        $embedMs = (int) round((microtime(true) - $tRead) * 1000);

        if ($signature === null) {
            throw new \RuntimeException(
                "Failed to generate signature for entity {$this->entityId}"
            );
        }

        if ($entity->signature === null) {
            $entity->update(['signature' => json_encode($signature)]);
        }

        $tDedup = microtime(true);
        $match = $signatureService->findSimilarToEntity($entity);
        $dedupMs = (int) round((microtime(true) - $tDedup) * 1000);
        $signatureJobMs = (int) round((microtime(true) - $tPipeline) * 1000);

        $isDuplicate = $match !== null;

        Log::info('ProcessEntityFile signature and deduplication', [
            'entity_id' => $this->entityId,
            'lang' => $entity->language->code,
            'read_ms' => $readMs,
            'embed_ms' => $embedMs,
            'has_similar_ms' => $dedupMs,
            'total_signature_job_ms' => $signatureJobMs,
            'duplicate' => $isDuplicate,
        ]);

        if ($isDuplicate) {
            $this->resolveDuplicate($entity, $match['entity'], (float) $match['similarity']);

            return;
        }

        SplitEntityFileSentences::dispatch($this->entityId, $this->filePath);
    }

    /**
     * A near-duplicate entity slipped past the synchronous check (e.g. a race).
     * Migrate its access grants onto the surviving entity, then delete it so
     * the uploader keeps their access to the canonical text.
     */
    private function resolveDuplicate(Entity $duplicate, Entity $survivor, float $similarity): void
    {
        $access = new EntityAccessService;

        foreach ($duplicate->grantedUsers()->get() as $user) {
            $access->grant($user, $survivor, $similarity);
        }

        $duplicate->delete();
    }
}
