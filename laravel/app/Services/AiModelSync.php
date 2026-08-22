<?php

namespace App\Services;

use App\Contracts\ModelSync;
use App\Models\AiModel;
use Illuminate\Support\Facades\Http;

abstract class AiModelSync implements ModelSync
{
    protected string $modelsEndpoint;

    public function __construct()
    {
        $this->modelsEndpoint = (string) config("services.{$this->provider()}.models_url", '');
    }

    public function sync(): int
    {
        $provider = $this->provider();

        /** @var array<int, array<string, mixed>> $items */
        $items = [];

        if ($this->modelsEndpoint !== '') {
            $response = Http::timeout(60)->get($this->modelsEndpoint);

            if (! $response->successful()) {
                throw new \RuntimeException(
                    "{$provider} models API returned HTTP {$response->status()}"
                );
            }

            /** @var array<int, array<string, mixed>> $items */
            $items = $response->json('data', []);
        }

        $seenExternalIds = [];

        foreach ($items as $item) {
            $pricing = $item['pricing'] ?? [];

            $attributes = [
                'provider' => $provider,
                'canonical_slug' => $item['canonical_slug'] ?? null,
                'name' => $item['name'] ?? $item['id'],
                'description' => $item['description'] ?? null,
                'context_length' => $item['context_length'] ?? null,
                'pricing_prompt' => $pricing['prompt'] ?? null,
                'pricing_completion' => $pricing['completion'] ?? null,
                'reasoning' => $item['reasoning'] ?? null,
                'expiration_date' => ! empty($item['expiration_date'])
                    ? $item['expiration_date']
                    : null,
                'api_created_at' => ! empty($item['created'])
                    ? now()->createFromTimestamp($item['created'])
                    : null,
            ];

            AiModel::query()->updateOrCreate(
                [
                    'provider' => $provider,
                    'external_id' => $item['id'],
                ],
                $attributes,
            );

            $seenExternalIds[] = $item['id'];
        }

        AiModel::query()
            ->forProvider($provider)
            ->whereNotIn('external_id', $seenExternalIds)
            ->delete();

        return count($seenExternalIds);
    }

    abstract public function provider(): string;
}
