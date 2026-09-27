<?php

namespace App\Support;

use App\Models\User;

/**
 * The word popup's section visibility for the current user (the profile's
 * Popups tab). Keys absent from ui_settings.popup mean visible — the
 * defaults are all-visible, so a user who never touched the tab sees the
 * popup exactly as before.
 */
class PopupVisibility
{
    /**
     * Every toggleable popup block, in the order the Popups tab lists it.
     */
    public const SECTIONS = [
        'familiarity',
        'progress_actions',
        'form_of',
        'word_family',
        'frequency',
        'transcriptions',
        'definitions',
        'translations',
        'examples',
        'etymologies',
        'explanation',
    ];

    /**
     * The user's saved opt-outs resolved over the all-visible defaults.
     *
     * @return array<string, bool>
     */
    public static function for(?User $user): array
    {
        $saved = $user?->settings?->ui_settings['popup'] ?? [];
        $visibility = [];

        foreach (self::SECTIONS as $section) {
            $visibility[$section] = $saved[$section] ?? true;
        }

        return $visibility;
    }
}
