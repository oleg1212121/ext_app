<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Reader of the user's saved UI settings (ADR 0024): the clamped prop
 * seeding both reading surfaces do at render time. One home for the clamp
 * and the annotation-preference loop so the surfaces cannot drift on the
 * defaults or the camelCased prop names.
 */
final class SavedUiSettings
{
    /**
     * The bounded integer settings, one home for the numbers: the save
     * request's validation rules and the render-time clamping below read
     * the same min / max / default.
     */
    public const READER_FONT_SIZE = ['min' => 16, 'max' => 38, 'default' => 20];

    public const SIMULATOR_FONT_SIZE = ['min' => 12, 'max' => 48, 'default' => 26];

    public const SIMULATOR_AI_PANEL_WIDTH = ['min' => 280, 'max' => 1200, 'default' => 560];

    public const SIMULATOR_WORKPLACE_HEIGHT = ['min' => 80, 'max' => 800, 'default' => 168];

    /**
     * One ui_settings section of the given user's settings row.
     *
     * @return array<string, mixed>
     */
    public static function section(?User $user, string $name): array
    {
        return $user?->settings?->ui_settings[$name] ?? [];
    }

    /**
     * A saved integer clamped into [min, max]; anything not integer-like
     * (including null, a never-saved key) seeds the default.
     */
    public static function int(array $saved, string $key, int $min, int $max, int $default): int
    {
        $value = $saved[$key] ?? null;

        if (is_bool($value) || ! is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    /**
     * A saved boolean preference.
     */
    public static function bool(array $saved, string $key, bool $default): bool
    {
        return (bool) ($saved[$key] ?? $default);
    }

    /**
     * The saved annotation display preferences (ADR 0067), keyed by the
     * camelCased setting key the page expects as its prop name; default off.
     *
     * @param  array<string, mixed>  $saved  one ui_settings section
     * @param  iterable<object{settingKey: string}>  $annotations  EnricherRegistry::annotations()
     * @return array<string, bool>
     */
    public static function annotations(array $saved, iterable $annotations): array
    {
        $props = [];

        foreach ($annotations as $annotation) {
            $props[Str::camel($annotation->settingKey)] = (bool) ($saved[$annotation->settingKey] ?? false);
        }

        return $props;
    }
}
