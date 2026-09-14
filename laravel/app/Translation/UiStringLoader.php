<?php

namespace App\Translation;

use App\Support\UiStrings;
use Illuminate\Translation\FileLoader;

class UiStringLoader extends FileLoader
{
    /**
     * Overlay DB-backed UI strings onto the file-based group, so
     * ui_string_keys rows (e.g. "nav.library") resolve through __()
     * under their group ("nav") alongside framework lines like auth.failed.
     */
    public function load($locale, $group, $namespace = null): array
    {
        $file = parent::load($locale, $group, $namespace);

        if ($namespace !== '*' && $namespace !== null) {
            return $file;
        }

        $prefix = $group.'.';
        $nested = [];
        foreach (UiStrings::mapFor($locale) as $key => $text) {
            if (! str_starts_with($key, $prefix)) {
                continue;
            }

            $ref = &$nested;
            foreach (explode('.', substr($key, strlen($prefix))) as $segment) {
                $ref = &$ref[$segment];
            }
            $ref = $text;
            unset($ref);
        }

        return array_merge($file, $nested);
    }
}
