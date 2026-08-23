<?php

namespace App\Classes;

use App\Contracts\AiProviderInterface;
use App\Exceptions\AiProviderException;
use App\Models\AiModel;
use App\Models\AiProvider as AiProviderRecord;
use Illuminate\Support\Facades\Auth;
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
     * The registered provider keys.
     *
     * @return array<string>
     */
    public function keys(): array
    {
        return array_keys($this->providerClasses);
    }

    /**
     * The fully-qualified provider class for a given key.
     *
     * @return class-string<AiProviderInterface>
     */
    public function providerClass(string $key): string
    {
        return $this->providerClasses[$key];
    }

    /**
     * Get the models the authenticated user can use, grouped by provider.
     *
     * A provider is "available" to the user when it is admin-enabled AND the
     * user has stored a User key for it. Within each provider the models are
     * sorted by price ascending (cheapest first); provider groups are ordered
     * by their cheapest model so the globally cheapest model is first overall.
     *
     * Format: ['Provider Name' => ['provider:model' => 'model_display_name', ...], ...]
     *
     * @return array<string, array<string, string>>
     */
    public function getGroupedModels(): array
    {
        $user = Auth::user();

        if ($user === null) {
            return [];
        }

        $groups = [];      // providerKey => ['name' => string, 'models' => array<string,string>]
        $providerIds = []; // providerKey => ai_provider_id

        foreach ($this->providerClasses as $key => $class) {
            $record = AiProviderRecord::forKey($key)->first();

            if ($record === null || ! $record->is_enabled) {
                continue;
            }

            if (! $user->hasApiKeyForProvider($key)) {
                continue;
            }

            $provider = $this->getProvider($key);
            $models = [];

            foreach ($provider->getModels() as $modelKey => $modelName) {
                $models[$key.':'.$modelKey] = $modelName;
            }

            if (empty($models)) {
                continue;
            }

            $groups[$key] = [
                'name' => $class::getProviderName(),
                'models' => $models,
            ];
            $providerIds[$key] = $record->id;
        }

        if (empty($groups)) {
            return [];
        }

        $orderedKeys = $this->orderProvidersByCheapestModel($providerIds);

        $grouped = [];
        foreach ($orderedKeys as $key) {
            $grouped[$groups[$key]['name']] = $groups[$key]['models'];
        }

        return $grouped;
    }

    /**
     * Order provider keys by their cheapest enabled model (ascending).
     *
     * @param  array<string, int>  $providerIds
     * @return list<string>
     */
    protected function orderProvidersByCheapestModel(array $providerIds): array
    {
        if (empty($providerIds)) {
            return [];
        }

        $minPrices = AiModel::query()
            ->whereIn('ai_provider_id', array_values($providerIds))
            ->where('is_enabled', true)
            ->selectRaw('ai_provider_id, MIN(pricing_prompt + pricing_completion) as min_price')
            ->groupBy('ai_provider_id')
            ->pluck('min_price', 'ai_provider_id');

        $keyToMin = [];
        foreach ($providerIds as $key => $id) {
            $keyToMin[$key] = (float) ($minPrices[$id] ?? PHP_FLOAT_MAX);
        }

        $keys = array_keys($providerIds);
        usort($keys, fn (string $a, string $b): int => $keyToMin[$a] <=> $keyToMin[$b]);

        return $keys;
    }

    /**
     * The first model key from the user-scoped grouped list (globally cheapest),
     * or null when the user has no usable providers.
     */
    public function firstModelKey(): ?string
    {
        foreach ($this->getGroupedModels() as $models) {
            $keys = array_keys($models);
            if (! empty($keys)) {
                return $keys[0];
            }
        }

        return null;
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
     * Ask AI using the specified provider:model, keyed with the user's own key.
     */
    public function ask(
        string $modelString,
        string $instruction,
        string $question
    ): ?string {
        $parsed = $this->parseModelString($modelString);
        $provider = $this->getProvider($parsed['provider']);

        $user = Auth::user();
        $userKey = $user?->apiKeyForProvider($parsed['provider']);

        if ($userKey === null) {
            throw new AiProviderException(
                'You have not added an API key for this provider.',
                403,
            );
        }

        $provider = $provider->withApiKey($userKey->api_key);

        return $provider->askForContext($instruction, $question, $parsed['model']);
    }

    /**
     * Ask AI using the specified provider:model, keyed with the user's own key,
     * streaming raw markdown chunks to $onChunk as they arrive.
     *
     * @param  callable(string $chunk): void  $onChunk
     */
    public function askStreamed(
        string $modelString,
        string $instruction,
        string $question,
        callable $onChunk
    ): void {
        $parsed = $this->parseModelString($modelString);
        $provider = $this->getProvider($parsed['provider']);

        $user = Auth::user();
        $userKey = $user?->apiKeyForProvider($parsed['provider']);

        if ($userKey === null) {
            throw new AiProviderException(
                'You have not added an API key for this provider.',
                403,
            );
        }

        $provider = $provider->withApiKey($userKey->api_key);

        $provider->askForContextStreamed($instruction, $question, $parsed['model'], $onChunk);
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
