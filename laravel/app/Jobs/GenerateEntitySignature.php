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

class GenerateEntitySignature implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public int $tries = 5;

    private const LANG_MODELS = [
        'en' => EnEntity::class,
        'ru' => RuEntity::class,
    ];

    public function __construct(
        private readonly int $entityId,
        private readonly string $filePath,
        private readonly string $lang,
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
        $service = TextSignatureService::create();

        $modelClass = self::LANG_MODELS[$this->lang] ?? throw new \InvalidArgumentException(
            "Unsupported language: {$this->lang}"
        );

        $entity = $modelClass::findOrFail($this->entityId);

        $content = TextSignatureService::readFileFromLocalPath($this->filePath);

        $signature = $service->generateSignature($content);

        if ($signature === null) {
            throw new \RuntimeException(
                "Failed to generate signature for entity {$this->entityId}"
            );
        }

        $entity->update(['signature' => json_encode($signature)]);
    }
}
