<?php

use App\Support\AnkiSettings;
use Tests\TestCase;

uses(TestCase::class);

/*
 * The PHP<->JS mirror lockstep: App\Support\AnkiSettings owns the canonical
 * thirteen-field list and the connection defaults server-side, while the
 * browser modules (resources/js/lib/ankiTemplate.js, ankiConnect.js) and
 * the Anki tab (resources/js/Pages/Profile/AnkiSettings.jsx) hand-mirror
 * them — no JS runtime here, just string assertions against the sources
 * read from disk. A field added, renamed or re-ordered on either side
 * fails these tests until both sides agree again.
 */

/**
 * The Anki note-type field names keyed by settings key, in the note type's
 * fixed field order. The names only exist browser-side; this map is the
 * executable record of the mapping the generated template and the duplicate
 * search both rely on. Schema v2: `body` is the 13th field (Body, last) —
 * the value-carrier of the popup-interleaved dictionary body.
 */
const MIRRORED_ANKI_FIELDS = [
    'word' => 'Word',
    'transcription' => 'Transcription',
    'part_of_speech' => 'PartOfSpeech',
    'frequency' => 'Frequency',
    'definitions' => 'Definitions',
    'translations' => 'Translations',
    'examples' => 'Examples',
    'etymology' => 'Etymology',
    'word_family' => 'WordFamily',
    'tags' => 'Tags',
    'familiarity' => 'Familiarity',
    'language' => 'Language',
    'body' => 'Body',
];

it('mirrors every AnkiSettings field key and its Anki field name into the JS template module', function () {
    $templateModule = file_get_contents(base_path('resources/js/lib/ankiTemplate.js'));

    preg_match_all("/\{key: '(?<key>[a-z_]+)', name: '(?<name>[A-Za-z]+)'\}/", $templateModule, $matches);

    expect(count(AnkiSettings::FIELDS))->toBe(13)
        ->and($matches['key'])->toBe(AnkiSettings::FIELDS)
        ->and($matches['name'])->toBe(array_values(MIRRORED_ANKI_FIELDS));
});

it('keeps the field mapping and the AnkiSettings::FIELDS list in the same order', function () {
    expect(array_keys(MIRRORED_ANKI_FIELDS))->toBe(AnkiSettings::FIELDS);
});

it('surfaces every field key as exactly one checkbox across the Anki tab\'s toggle groups', function () {
    $ankiTab = file_get_contents(base_path('resources/js/Pages/Profile/AnkiSettings.jsx'));

    preg_match('/const FIELD_GROUPS = \[(?<groups>[\s\S]*?)\n\]/', $ankiTab, $array);
    preg_match_all('/keys: \[(?<keys>[^\]]*)\]/', $array['groups'] ?? '', $groups);
    preg_match_all("/'(?<key>[a-z_]+)'/", implode(', ', $groups['keys']), $matches);

    // Grouping is display-only, so coverage is the invariant: no key lost
    // to an unsurfaced toggle (the regression that shipped `body`
    // checkbox-less), none duplicated across groups, nothing foreign.
    expect($matches['key'])->toHaveCount(count(AnkiSettings::FIELDS))
        ->and($matches['key'])->toEqualCanonicalizing(AnkiSettings::FIELDS);
});

it('drives the Anki tab\'s per-language editing through the shared state module', function () {
    $ankiTab = file_get_contents(base_path('resources/js/Pages/Profile/AnkiSettings.jsx'));

    // The tab's whole-section state, per-language entry accessors, sync
    // config (note type wiring) and sample payload all come from
    // resources/js/lib/ankiTabState.js — the executable behavior lives there
    // (ankiTabState.test.js), the tab only renders it.
    expect($ankiTab)
        ->toContain("from '../../lib/ankiTabState'")
        ->toContain('ankiTabState(')
        ->toContain('ankiTabDefaultCode(')
        ->toContain('ankiTabEntry(')
        ->toContain('ankiTabSetDeck(')
        ->toContain('ankiTabToggleField(')
        ->toContain('ankiTabSyncConfig(')
        ->toContain('ankiTabSampleData(')
        // The resolved `default` entry is never selectable and never
        // persisted: the selector lists the enabled languages only.
        ->and($ankiTab)->not->toContain("'default'");
});

it('mirrors the connection defaults into the JS AnkiConnect module', function () {
    $connectModule = file_get_contents(base_path('resources/js/lib/ankiConnect.js'));

    expect($connectModule)
        ->toContain("ANKI_DEFAULT_URL = '".AnkiSettings::DEFAULT_URL."'")
        ->toContain('ANKI_DEFAULT_PORT = '.AnkiSettings::DEFAULT_PORT)
        ->toContain("ANKI_DEFAULT_DECK = '".AnkiSettings::DEFAULT_DECK."'");
});

it('pins the app-owned note type and card name in the JS template module', function () {
    $templateModule = file_get_contents(base_path('resources/js/lib/ankiTemplate.js'));

    expect($templateModule)
        ->toContain("ANKI_NOTE_TYPE = 'ExtApp Vocabulary'")
        ->toContain("ANKI_CARD_NAME = 'Recognition'");
});

it('mirrors the per-language note type naming into the JS template module', function () {
    $templateModule = file_get_contents(base_path('resources/js/lib/ankiTemplate.js'));

    // The PHP behavior: base + " ⋅ " + code, base alone for an empty or
    // missing code (words with unknown/missing language_code resolve to the
    // settings' `default` entry).
    expect(AnkiSettings::noteTypeForLanguage('en'))->toBe('ExtApp Vocabulary ⋅ en')
        ->and(AnkiSettings::noteTypeForLanguage(''))->toBe(AnkiSettings::NOTE_TYPE_BASE)
        ->and(AnkiSettings::noteTypeForLanguage(null))->toBe(AnkiSettings::NOTE_TYPE_BASE)
        // The JS twin, pinned source-side (no JS runtime here): the same
        // base-name constant, the same separator, and the helper the send
        // paths build config.noteType with — its behavior is pinned
        // executable in ankiTemplate.test.js.
        ->and($templateModule)
        ->toContain("ANKI_NOTE_TYPE = '".AnkiSettings::NOTE_TYPE_BASE."'")
        ->toContain('export function noteTypeForLanguage(code)')
        ->toContain(AnkiSettings::NOTE_TYPE_SEPARATOR);
});
