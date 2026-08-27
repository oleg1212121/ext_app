<?php

namespace App\Services;

use App\Contracts\ModelSync;
use App\Models\AiModel;
use App\Models\AiProvider as AiProviderRecord;
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

        // The ai_models.ai_provider_id column is NOT NULL, so a provider row
        // must exist before syncing. Sync is a catalog mirror: it never
        // consults the provider's is_enabled flag. A disabled provider is still
        // refreshed so its model catalog stays faithful to the upstream API;
        // availability is enforced separately in the runtime resolver.
        $providerId = AiProviderRecord::forKey($provider)->value('id');

        if ($providerId === null) {
            throw new \RuntimeException(
                "Cannot sync {$provider} models: no AiProvider row exists. Run the provider seeder first."
            );
        }

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
                'ai_provider_id' => $providerId,
                'canonical_slug' => $item['canonical_slug'] ?? null,
                'name' => $item['name'] ?? $item['id'],
                'description' => $item['description'] ?? null,
                'context_length' => $item['context_length'] ?? null,
                'pricing_prompt' => static::normalizePrice($pricing['prompt'] ?? null),
                'pricing_completion' => static::normalizePrice($pricing['completion'] ?? null),
                'reasoning' => $item['reasoning'] ?? null,
                'expiration_date' => ! empty($item['expiration_date'])
                    ? $item['expiration_date']
                    : null,
                'api_created_at' => ! empty($item['created'])
                    ? now()->createFromTimestamp($item['created'])
                    : null,
            ];

            // is_enabled is intentionally omitted so an admin's enable/disable
            // choice survives re-syncs (updateOrCreate leaves it untouched on
            // update, and new rows default to disabled).
            AiModel::query()->updateOrCreate(
                ['ai_provider_id' => $providerId, 'external_id' => $item['id']],
                $attributes
            );

            $seenExternalIds[] = $item['id'];
        }

        // Delete catalog-missing rows for this provider.
        if ($seenExternalIds !== []) {
            AiModel::query()
                ->forProvider($providerId)
                ->whereNotIn('external_id', $seenExternalIds)
                ->delete();
        }

        return count($seenExternalIds);
    }

    abstract public function provider(): string;

    protected static function normalizePrice(mixed $value): mixed
    {
        if ($value !== null && (float) $value < 0) {
            return null;
        }

        return $value;
    }
}
