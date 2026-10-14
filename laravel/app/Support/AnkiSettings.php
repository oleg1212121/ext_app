<?php

namespace App\Support;

use App\Models\User;

/**
 * The profile's Anki tab connection settings (AnkiConnect URL, port, target
 * deck), resolved over defaults — the server-side twin of the browser-side
 * AnkiConnect client config. Stored sparsely in the `anki` section of the
 * per-user `ui_settings` blob, fully separate from popup/simulator/reader;
 * absent keys fall back to the defaults here. Laravel only ever stores these
 * values — the browser calls AnkiConnect directly.
 */
class AnkiSettings
{
    public const DEFAULT_URL = 'http://127.0.0.1';

    public const DEFAULT_PORT = 8765;

    public const DEFAULT_DECK = 'ExtApp';

    /**
     * The user's saved connection resolved over the defaults.
     */
    public static function for(?User $user): array
    {
        $saved = $user?->settings?->ui_settings['anki'] ?? [];

        return [
            'url' => $saved['url'] ?? self::DEFAULT_URL,
            'port' => $saved['port'] ?? self::DEFAULT_PORT,
            'deck' => $saved['deck'] ?? self::DEFAULT_DECK,
        ];
    }
}
