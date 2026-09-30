<?php

namespace App\Classes\WordTranslations;

/**
 * Google Cloud Translation v2: a single machine-translation candidate per
 * word, no part-of-speech information.
 */
class GoogleTranslateProvider extends WordTranslationProvider
{
    public function name(): string
    {
        return 'google';
    }

    public function isConfigured(): bool
    {
        return (string) config('services.google_translate.key') !== '';
    }

    public function fetch(string $word, string $fromCode, string $toCode): array
    {
        $key = (string) config('services.google_translate.key');

        $response = $this->post(
            rtrim((string) config('services.google_translate.url'), '/').'?key='.urlencode($key),
            ['q' => $word, 'source' => $fromCode, 'target' => $toCode, 'format' => 'text'],
            ['Accept' => 'application/json'],
            (int) config('services.google_translate.timeout', 30),
        );

        $text = $this->normalizeCandidate(
            html_entity_decode((string) $response->json('data.translations.0.translatedText', ''), ENT_QUOTES | ENT_HTML5),
            $toCode,
        );

        return $text === null ? [] : [['text' => $text, 'pos' => null]];
    }
}
