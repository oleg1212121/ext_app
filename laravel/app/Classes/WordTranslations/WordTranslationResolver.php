<?php

namespace App\Classes\WordTranslations;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ordered provider chain for auto-fetching word translations: Yandex first
 * (dictionary-grade, multiple candidates with parts of speech), Google as
 * the fallback (a single machine-translation candidate). The first provider
 * that returns candidates wins. A failing provider is logged and skipped —
 * but when no provider produced a translation and at least one could not
 * answer, the lookup throws instead of reporting "no translation", so the
 * word is retried rather than excluded.
 */
class WordTranslationResolver
{
    /** @var list<WordTranslationProvider> */
    private readonly array $providers;

    public function __construct()
    {
        $this->providers = array_values(array_filter(
            [new YandexDictionaryProvider, new GoogleTranslateProvider],
            fn (WordTranslationProvider $provider): bool => $provider->isConfigured(),
        ));
    }

    public function hasProvider(): bool
    {
        return $this->providers !== [];
    }

    /**
     * @return array{provider: string|null, candidates: list<array{text: string, pos: string|null}>}
     */
    public function fetch(string $word, string $fromCode, string $toCode): array
    {
        $exceptions = [];

        foreach ($this->providers as $provider) {
            try {
                $candidates = $provider->fetch($word, $fromCode, $toCode);
            } catch (Throwable $exception) {
                $exceptions[] = $exception;
                Log::warning('Word translation provider failed', [
                    'provider' => $provider->name(),
                    'error' => $exception->getMessage(),
                ]);

                continue;
            }

            if ($candidates !== []) {
                return ['provider' => $provider->name(), 'candidates' => $candidates];
            }
        }

        if ($exceptions !== []) {
            throw $exceptions[0];
        }

        return ['provider' => null, 'candidates' => []];
    }
}
