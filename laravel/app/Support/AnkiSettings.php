<?php

namespace App\Support;

use App\Models\Language;
use App\Models\User;

/**
 * The profile's Anki tab settings: the connection (AnkiConnect URL, port),
 * the card field toggles and the per-language deck/fields map, resolved over
 * defaults — the server-side twin of the browser-side AnkiConnect client
 * config. Stored sparsely in the `anki` section of the per-user
 * `ui_settings` blob, fully separate from popup/simulator/reader; absent
 * keys fall back to the defaults here. Laravel only ever stores these values
 * — the browser calls AnkiConnect directly.
 *
 * Deck and fields are per-language: `ui_settings.anki.languages` holds a
 * sparse map keyed by enabled language code, while the legacy global
 * `deck`/`fields` keys stay readable as the fallback base — a user who never
 * customized a language resolves every entry to today's global values.
 */
class AnkiSettings
{
    public const DEFAULT_URL = 'http://127.0.0.1';

    public const DEFAULT_PORT = 8765;

    public const DEFAULT_DECK = 'ExtApp';

    /**
     * The resolved entry's key for words whose language_code is unknown or
     * missing — a member of the resolved `languages` map, never a persistable
     * language code (the endpoint's whitelist is the enabled languages).
     */
    public const DEFAULT_LANGUAGE_KEY = 'default';

    /**
     * Every card field toggle, in the note type's fixed field order — the
     * canonical server-side list the JS template module mirrors
     * (resources/js/lib/ankiTemplate.js). Absent keys mean on, the Popups
     * tab's convention. Schema v2: `body` is the 13th field (Body, last) —
     * the value-carrier of the popup-interleaved dictionary body; like the
     * other body section toggles it is applied at SEND time (the senders
     * pass the resolved map), while the scalar toggles stay template-side.
     */
    public const FIELDS = [
        'word',
        'transcription',
        'part_of_speech',
        'frequency',
        'definitions',
        'translations',
        'examples',
        'etymology',
        'word_family',
        'tags',
        'familiarity',
        'language',
        'body',
    ];

    /**
     * The user's saved settings resolved over the defaults: the connection,
     * the legacy global base (deck + field toggles, all on unless the user
     * opted out) and the per-language map — one resolved entry per enabled
     * language plus `default`.
     *
     * @return array{url: string, port: int, deck: string, fields: array<string, bool>, languages: array<string, array{deck: string, fields: array<string, bool>}>}
     */
    public static function for(?User $user): array
    {
        $saved = $user?->settings?->ui_settings['anki'] ?? [];

        return [
            'url' => $saved['url'] ?? self::DEFAULT_URL,
            'port' => $saved['port'] ?? self::DEFAULT_PORT,
            'deck' => $saved['deck'] ?? self::DEFAULT_DECK,
            'fields' => self::fields($saved['fields'] ?? []),
            // The sparse per-language map resolved over the legacy base —
            // the shared ankiSettings prop's word-level lookup table.
            'languages' => self::languages($saved),
        ];
    }

    /**
     * The field toggles for one level of the fallback chain: every canonical
     * field key present, with an optional next level to fall back to
     * (per-language entry → legacy base → all-on, the Popups tab's
     * absent-key-means-on convention).
     *
     * @param  array<string, mixed>  $saved
     * @param  array<string, bool>  $fallback
     * @return array<string, bool>
     */
    private static function fields(array $saved, array $fallback = []): array
    {
        $fields = [];
        foreach (self::FIELDS as $field) {
            $fields[$field] = $saved[$field] ?? $fallback[$field] ?? true;
        }

        return $fields;
    }

    /**
     * One resolved deck+fields entry per enabled language plus `default`,
     * each walking the chain saved per-language → legacy global base →
     * defaults, so a user with only legacy settings resolves every entry to
     * today's global deck/fields (the agreed migration: global values become
     * defaults). `default` — for words whose language_code is unknown or
     * missing — is computed from the legacy base and is never persistable.
     *
     * @param  array<string, mixed>  $saved  the stored `anki` section
     * @return array<string, array{deck: string, fields: array<string, bool>}>
     */
    private static function languages(array $saved): array
    {
        $base = [
            'deck' => $saved['deck'] ?? self::DEFAULT_DECK,
            'fields' => self::fields($saved['fields'] ?? []),
        ];

        $entries = [];
        $codes = Language::query()
            ->enabled()
            ->orderBy('sort_order')
            ->orderBy('code')
            ->pluck('code');
        foreach ($codes as $code) {
            $savedEntry = $saved['languages'][$code] ?? [];
            $entries[(string) $code] = [
                'deck' => $savedEntry['deck'] ?? $base['deck'],
                'fields' => self::fields($savedEntry['fields'] ?? [], $base['fields']),
            ];
        }

        $entries[self::DEFAULT_LANGUAGE_KEY] = $base;

        return $entries;
    }
}
