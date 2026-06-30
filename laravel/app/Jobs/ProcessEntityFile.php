<?php

namespace App\Jobs;

use App\Classes\TextSignatureService;
use App\Models\EnEntity;
use App\Models\RuEntity;
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

    private const LANG_MODELS = [
        'en' => EnEntity::class,
        'ru' => RuEntity::class,
    ];

    public function __construct(
        private int $entityId,
        private string $filePath,
        private string $lang,
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
        $modelClass = self::LANG_MODELS[$this->lang] ?? throw new \InvalidArgumentException(
            "Unsupported language: {$this->lang}"
        );

        $entity = $modelClass::findOrFail($this->entityId);
        $signatureService = TextSignatureService::create();

        $tRead = microtime(true);
        $content = TextSignatureService::readFileFromLocalPath($this->filePath);
        $readMs = (int) round((microtime(true) - $tRead) * 1000);

        $tEmbed = microtime(true);
        $signature = $signatureService->generateSignature($content);
        $embedMs = (int) round((microtime(true) - $tEmbed) * 1000);

        if ($signature === null) {
            throw new \RuntimeException(
                "Failed to generate signature for entity {$this->entityId}"
            );
        }

        $entity->update(['signature' => json_encode($signature)]);

        $tDedup = microtime(true);
        $isDuplicate = $signatureService->hasSimilar($entity, $this->lang);
        $dedupMs = (int) round((microtime(true) - $tDedup) * 1000);
        $signatureJobMs = (int) round((microtime(true) - $tPipeline) * 1000);

        Log::info('ProcessEntityFile signature and deduplication', [
            'entity_id' => $this->entityId,
            'lang' => $this->lang,
            'read_ms' => $readMs,
            'embed_ms' => $embedMs,
            'has_similar_ms' => $dedupMs,
            'total_signature_job_ms' => $signatureJobMs,
            'duplicate' => $isDuplicate,
        ]);

        if ($isDuplicate) {
            $entity->delete();

            return;
        }

        SplitEntityFileSentences::dispatch($this->entityId, $this->filePath, $this->lang);
    }
}
