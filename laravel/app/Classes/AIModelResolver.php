<?php

namespace App\Classes;

use App\Contracts\AiProviderInterface;
use InvalidArgumentException;

class AIModelResolver
{
    /**
     * Registry of all available provider classes
     *
     * @var array<string, class-string<AiProviderInterface>>
     */
    protected array $providerClasses = [
        'openrouter' => OpenRouter::class,
        'gemini' => Gemini::class,
        'huggingface' => HuggingFace::class,
        'cohere' => Cohere::class,
        'perplexity' => Perplexity::class,
        'groq' => Groq::class,
    ];

    /**
     * Cache of instantiated providers
     *
     * @var array<string, AiProviderInterface>
     */
    protected array $providers = [];

    /**
     * Get all available models grouped by provider
     * Format: ['Provider Name' => ['provider:model' => 'model_display_name', ...], ...]
     *
     * @return array<string, array<string, string>>
     */
    public function getGroupedModels(): array
    {
        $grouped = [];

        foreach ($this->providerClasses as $key => $class) {
            $provider = $this->getProvider($key);

            if (! $provider->isConfigured()) {
                continue;
            }

            $providerName = $class::getProviderName();
            $models = [];

            foreach ($provider->getModels() as $modelKey => $modelName) {
                $fullKey = $key.':'.$modelKey;
                $models[$fullKey] = $modelName;
            }

            if (! empty($models)) {
                $grouped[$providerName] = $models;
            }
        }

        return $grouped;
    }

    /**
     * Get flat array of all models for validation
     *
     * @return array<string> List of 'provider:model' strings
     */
    public function getAllModelKeys(): array
    {
        $keys = [];
        foreach ($this->getGroupedModels() as $models) {
            $keys = array_merge($keys, array_keys($models));
        }

        return $keys;
    }

    /**
     * Parse a 'provider:model' string into components
     *
     * @return array{provider: string, model: string}
     */
    public function parseModelString(string $modelString): array
    {
        $colonPos = strpos($modelString, ':');

        if ($colonPos === false) {
            throw new InvalidArgumentException(
                "Invalid model format. Expected 'provider:model', got: {$modelString}"
            );
        }

        return [
            'provider' => substr($modelString, 0, $colonPos),
            'model' => substr($modelString, $colonPos + 1),
        ];
    }

    /**
     * Resolve provider instance from 'provider:model' string
     */
    public function resolveProvider(string $modelString): AiProviderInterface
    {
        $parsed = $this->parseModelString($modelString);

        return $this->getProvider($parsed['provider']);
    }

    /**
     * Ask AI using the specified provider:model
     */
    public function ask(
        string $modelString,
        string $instruction,
        string $question
    ): ?string {
        $parsed = $this->parseModelString($modelString);
        $provider = $this->getProvider($parsed['provider']);

        return $provider->askForContext($instruction, $question, $parsed['model']);
    }

    /**
     * Get or create a provider instance
     */
    protected function getProvider(string $key): AiProviderInterface
    {
        if (! isset($this->providerClasses[$key])) {
            throw new InvalidArgumentException("Unknown provider: {$key}");
        }

        if (! isset($this->providers[$key])) {
            $this->providers[$key] = new $this->providerClasses[$key];
        }

        return $this->providers[$key];
    }

    /**
     * Check if a model string is valid
     */
    public function isValidModel(string $modelString): bool
    {
        try {
            $parsed = $this->parseModelString($modelString);
            if (! isset($this->providerClasses[$parsed['provider']])) {
                return false;
            }
            $provider = $this->getProvider($parsed['provider']);

            return array_key_exists($parsed['model'], $provider->getModels());
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
