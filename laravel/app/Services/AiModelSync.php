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

        // Sync is a catalog mirror: it never consults the provider's
        // is_enabled flag. A disabled provider is still refreshed so its
        // model catalog stays faithful to the upstream API; availability is
        // enforced separately in the runtime resolver.
        $providerId = AiProviderRecord::forKey($provider)->value('id');

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

        $fetchedExternalIds = array_column($items, 'id');

        if ($providerId !== null) {
            // Before upserting, adopt any orphaned rows (ai_provider_id IS
            // NULL) whose external_id appears in this provider's catalog.
            // Those rows predate the ai_providers table — their FK was left
            // NULL at migration time — so adopt them (rather than discard them
            // as duplicates) to preserve their is_enabled and any admin edits.
            // Only adopt when no live (provider, external_id) row already
            // exists, otherwise the FK unique index would be violated.
            $claimed = AiModel::query()
                ->forProvider($providerId)
                ->whereIn('external_id', $fetchedExternalIds)
                ->pluck('external_id')
                ->all();

            $adoptable = array_values(array_diff($fetchedExternalIds, $claimed));

            if ($adoptable !== []) {
                AiModel::query()
                    ->whereNull('ai_provider_id')
                    ->whereIn('external_id', $adoptable)
                    ->update(['ai_provider_id' => $providerId]);
            }
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

            // When no provider row exists yet (e.g. the seeder has not run),
            // match on external_id alone and leave ai_provider_id null. The
            // row is associated on a later resync after seeding.
            $match = $providerId === null
                ? ['external_id' => $item['id']]
                : ['ai_provider_id' => $providerId, 'external_id' => $item['id']];

            AiModel::query()->updateOrCreate($match, $attributes);

            $seenExternalIds[] = $item['id'];
        }

        if ($providerId !== null) {
            // Delete catalog-missing rows for this provider.
            AiModel::query()
                ->forProvider($providerId)
                ->whereNotIn('external_id', $seenExternalIds)
                ->delete();

            // Any NULL-provider rows still in the fetched set are duplicates of
            // a live row (left over from a sync that ran before adoption
            // existed). Carry their is_enabled flag onto the live row so an
            // enabled orphan is not silently lost, then remove the duplicate so
            // the (ai_provider_id, external_id) unique index stays satisfied.
            $enabledOrphans = AiModel::query()
                ->whereNull('ai_provider_id')
                ->where('is_enabled', true)
                ->whereIn('external_id', $seenExternalIds)
                ->pluck('external_id')
                ->all();

            if ($enabledOrphans !== []) {
                AiModel::query()
                    ->forProvider($providerId)
                    ->whereIn('external_id', $enabledOrphans)
                    ->update(['is_enabled' => true]);
            }

            AiModel::query()
                ->whereNull('ai_provider_id')
                ->whereIn('external_id', $seenExternalIds)
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
