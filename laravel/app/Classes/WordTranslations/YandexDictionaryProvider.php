<?php

namespace App\Classes\WordTranslations;

/**
 * Yandex Cloud Dictionary Lookup API: dictionary-grade translations with
 * parts of speech. The response groups senses under def[] (pos of the source
 * word) whose tr[] entries carry the translations, sometimes with their own
 * pos.
 */
class YandexDictionaryProvider extends WordTranslationProvider
{
    public function name(): string
    {
        return 'yandex';
    }

    public function isConfigured(): bool
    {
        return (string) config('services.yandex_translate.key') !== '';
    }

    public function fetch(string $word, string $fromCode, string $toCode): array
    {
        $response = $this->post(
            rtrim((string) config('services.yandex_translate.url'), '/').'/dictionary/lookup',
            array_filter([
                'texts' => [$word],
                'sourceLanguageCode' => $fromCode,
                'targetLanguageCode' => $toCode,
                'folderId' => config('services.yandex_translate.folder_id'),
            ], fn (mixed $value): bool => $value !== null && $value !== ''),
            [
                'Authorization' => 'Api-Key '.config('services.yandex_translate.key'),
                'Accept' => 'application/json',
            ],
            (int) config('services.yandex_translate.timeout', 30),
        );

        $candidates = [];
        $seen = [];

        foreach ($response->json('def', []) as $definition) {
            $definitionPos = $definition['pos'] ?? null;

            foreach ($definition['tr'] ?? [] as $translation) {
                $text = $this->normalizeCandidate((string) ($translation['text'] ?? ''), $toCode);

                if ($text === null) {
                    continue;
                }

                $key = mb_strtolower($text);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $candidates[] = ['text' => $text, 'pos' => $translation['pos'] ?? $definitionPos];

                if (count($candidates) >= self::MAX_CANDIDATES) {
                    break 2;
                }
            }
        }

        return $candidates;
    }
}
