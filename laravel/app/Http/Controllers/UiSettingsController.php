<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateUiSettingsRequest;
use Illuminate\Http\JsonResponse;

class UiSettingsController extends Controller
{
    /**
     * The `anki` section's legacy global base — the top-level deck/fields
     * keys the pre-per-language app wrote. Read-only since anki-popup-refresh
     * (the word-level fallback in AnkiSettings::for still reads them): no
     * validation rule accepts them, so validated() strips any incoming
     * values, and the previously saved pair is grafted back onto the stored
     * section below — the Anki tab autosaves the WHOLE section, and the
     * wholesale replace must never wipe or rewrite the base.
     */
    private const ANKI_LEGACY_BASE_KEYS = ['deck', 'fields'];

    public function update(UpdateUiSettingsRequest $request): JsonResponse
    {
        $user = $request->user();
        $ui = $user->settings?->ui_settings ?? [];

        // Captured before the validated payload replaces the stored section.
        $ankiLegacyBase = array_intersect_key(
            $ui['anki'] ?? [],
            array_flip(self::ANKI_LEGACY_BASE_KEYS),
        );

        foreach (['simulator', 'reader', 'popup', 'anki'] as $section) {
            if ($request->has($section)) {
                $ui[$section] = $request->validated($section);
            }
        }

        if ($request->has('anki') && $ankiLegacyBase !== []) {
            // Union with the saved base winning: the payload can carry
            // url/port/languages only, so the graft can never shadow a
            // freshly saved value.
            $ui['anki'] = $ankiLegacyBase + $ui['anki'];
        }

        $user->settings()->updateOrCreate([], ['ui_settings' => $ui]);

        return response()->json(['saved' => true]);
    }
}
