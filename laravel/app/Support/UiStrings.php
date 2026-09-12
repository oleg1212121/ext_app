<?php

namespace App\Support;

use App\Models\Language;
use App\Models\UiStringKey;
use Illuminate\Support\Facades\Cache;

class UiStrings
{
    public static function mapFor(string $locale): array
    {
        return Cache::rememberForever("ui_strings.map.{$locale}", fn () => self::build($locale));
    }

    public static function flush(): void
    {
        foreach (Language::query()->pluck('code') as $code) {
            Cache::forget("ui_strings.map.{$code}");
        }
    }

    /**
     * Flat key => text map. English rows are laid down first so any missing
     * locale value silently falls back to English.
     */
    private static function build(string $locale): array
    {
        $rows = UiStringKey::query()
            ->join('ui_strings', 'ui_strings.ui_string_key_id', '=', 'ui_string_keys.id')
            ->join('languages', 'languages.id', '=', 'ui_strings.language_id')
            ->whereIn('languages.code', $locale === 'en' ? ['en'] : ['en', $locale])
            ->get(['ui_string_keys.key as key', 'languages.code as code', 'ui_strings.text as text']);

        $map = [];
        foreach ($rows as $row) {
            $map[$row->key] = $row->text;
        }

        return $map;
    }
}
