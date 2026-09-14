<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateUiSettingsRequest;
use Illuminate\Http\JsonResponse;

class UiSettingsController extends Controller
{
    public function update(UpdateUiSettingsRequest $request): JsonResponse
    {
        $user = $request->user();
        $ui = $user->settings?->ui_settings ?? [];

        foreach (['simulator', 'reader'] as $section) {
            if ($request->has($section)) {
                $ui[$section] = $request->validated($section);
            }
        }

        $user->settings()->updateOrCreate([], ['ui_settings' => $ui]);

        return response()->json(['saved' => true]);
    }
}
