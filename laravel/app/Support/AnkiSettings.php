<?php

namespace App\Support;

use App\Models\User;

/**
 * The profile's Anki tab settings: the connection (AnkiConnect URL, port,
 * target deck) and the card field toggles, resolved over defaults — the
 * server-side twin of the browser-side AnkiConnect client config. Stored
 * sparsely in the `anki` section of the per-user `ui_settings` blob, fully
 * separate from popup/simulator/reader; absent keys fall back to the defaults
 * here. Laravel only ever stores these values — the browser calls AnkiConnect
 * directly.
 */
class AnkiSettings
{
    public const DEFAULT_URL = 'http://127.0.0.1';

    public const DEFAULT_PORT = 8765;

    public const DEFAULT_DECK = 'ExtApp';

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
     * The user's saved settings resolved over the defaults: the connection
     * plus the field toggles (all on unless the user opted out).
     *
     * @return array{url: string, port: int, deck: string, fields: array<string, bool>}
     */
    public static function for(?User $user): array
    {
        $saved = $user?->settings?->ui_settings['anki'] ?? [];

        $fields = [];
        foreach (self::FIELDS as $field) {
            $fields[$field] = $saved['fields'][$field] ?? true;
        }

        return [
            'url' => $saved['url'] ?? self::DEFAULT_URL,
            'port' => $saved['port'] ?? self::DEFAULT_PORT,
            'deck' => $saved['deck'] ?? self::DEFAULT_DECK,
            'fields' => $fields,
        ];
    }
}
