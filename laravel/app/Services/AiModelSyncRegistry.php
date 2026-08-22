<?php

namespace App\Services;

use App\Contracts\ModelSync;
use InvalidArgumentException;

class AiModelSyncRegistry
{
    /**
     * Map of provider key => ModelSync implementation.
     *
     * @var array<string, class-string<ModelSync>>
     */
    protected array $syncerClasses = [
        'openrouter' => OpenRouterModelSync::class,
        'gemini' => GeminiModelSync::class,
        'huggingface' => HuggingFaceModelSync::class,
        'cohere' => CohereModelSync::class,
        'perplexity' => PerplexityModelSync::class,
        'groq' => GroqModelSync::class,
    ];

    /**
     * Cache of instantiated syncers.
     *
     * @var array<string, ModelSync>
     */
    protected array $syncers = [];

    /**
     * All registered syncers keyed by provider key.
     *
     * @return array<string, ModelSync>
     */
    public function all(): array
    {
        foreach ($this->syncerClasses as $key => $class) {
            if (! isset($this->syncers[$key])) {
                $this->syncers[$key] = new $class;
            }
        }

        return $this->syncers;
    }

    /**
     * The registered provider keys.
     *
     * @return array<string>
     */
    public function keys(): array
    {
        return array_keys($this->syncerClasses);
    }

    /**
     * Resolve a single syncer by provider key.
     */
    public function for(string $provider): ModelSync
    {
        if (! isset($this->syncerClasses[$provider])) {
            throw new InvalidArgumentException("Unknown model-sync provider: {$provider}");
        }

        if (! isset($this->syncers[$provider])) {
            $this->syncers[$provider] = new $this->syncerClasses[$provider];
        }

        return $this->syncers[$provider];
    }
}
