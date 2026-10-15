<?php

use App\Support\PopupVisibility;
use Tests\TestCase;

uses(TestCase::class);

/*
 * The PHP<->JS mirror lockstep for the popup sections: App\Support\PopupVisibility
 * owns the canonical section list server-side (shared to every page as the
 * popupVisibility prop via HandleInertiaRequests), while the profile's Popups tab
 * hand-mirrors it as the module-local POPUP_SECTIONS constant — no JS runtime
 * here, just a string assertion against the JSX source read from disk. A section
 * added, renamed or re-ordered on either side fails this test until both sides
 * agree again.
 */

it('mirrors every popup section key, in order, into the PopupsSettings tab', function () {
    $popupsTab = file_get_contents(base_path('resources/js/Pages/Profile/PopupsSettings.jsx'));

    preg_match('/const POPUP_SECTIONS = \[(?<body>[^\]]+)\]/', $popupsTab, $array);
    preg_match_all("/'(?<key>[a-z_]+)'/", $array['body'] ?? '', $matches);

    expect($matches['key'])->toBe(PopupVisibility::SECTIONS);
});
