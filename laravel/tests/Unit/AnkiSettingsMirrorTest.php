<?php

use App\Support\AnkiSettings;
use Tests\TestCase;

uses(TestCase::class);

/*
 * The PHP<->JS mirror lockstep: App\Support\AnkiSettings owns the canonical
 * thirteen-field list and the connection defaults server-side, while the
 * browser modules (resources/js/lib/ankiTemplate.js, ankiConnect.js)
 * hand-mirror them — no JS runtime here, just string assertions against the
 * sources read from disk. A field added, renamed or re-ordered on either
 * side fails these tests until both sides agree again.
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
