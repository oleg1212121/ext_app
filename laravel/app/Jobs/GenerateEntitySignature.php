<?php

namespace App\Jobs;

use App\Classes\TextSignatureService;
use App\Models\Entity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

#[Queue(QueueLane::DEFAULT)]
class GenerateEntitySignature implements ShouldQueue
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

    public function handle(): void
    {
        $service = app(TextSignatureService::class);

        $entity = Entity::with('language')->findOrFail($this->entityId);

        $content = TextSignatureService::readFileFromLocalPath($this->filePath);

        $signature = $service->generateSignature($content, $entity->language->code);

        if ($signature === null) {
            throw new \RuntimeException(
                "Failed to generate signature for entity {$this->entityId}"
            );
        }

        $entity->update(['signature' => json_encode($signature), 'status' => 'completed']);
    }

    /**
     * The embedding pass gave up after its retries: hand the entity's
     * processing slot back (ADR 0044). The Filament Signature action or the
     * entity:generate-signatures sweep can put it back in processing.
     */
    public function failed(\Throwable $e): void
    {
        Entity::query()
            ->whereKey($this->entityId)
            ->whereNull('signature')
            ->update(['status' => 'failed']);
    }
}
