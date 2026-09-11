<?php

use App\Models\Language;
use App\Models\Word;
use App\Models\WordClass;

beforeEach(function () {
    createLanguages();
});

it('rejects an unknown source language', function () {
    $tmpFile = tempnam(sys_get_temp_dir(), 'wiktionary_cmd_');
    file_put_contents($tmpFile, json_encode(['word' => 'cat', 'pos' => 'noun'])."\n");

    $this->artisan('wiktionary:import', ['file' => $tmpFile, '--lang' => 'xx', '--target-lang' => 'ru'])
        ->assertFailed();

    expect(Word::count())->toBe(0);

    unlink($tmpFile);
});

it('rejects an unknown target language', function () {
    $tmpFile = tempnam(sys_get_temp_dir(), 'wiktionary_cmd_');
    file_put_contents($tmpFile, json_encode(['word' => 'cat', 'pos' => 'noun'])."\n");

    $this->artisan('wiktionary:import', ['file' => $tmpFile, '--lang' => 'en', '--target-lang' => 'xx'])
        ->assertFailed();

    expect(Word::count())->toBe(0);

    unlink($tmpFile);
});

it('rejects source and target being the same language', function () {
    $tmpFile = tempnam(sys_get_temp_dir(), 'wiktionary_cmd_');
    file_put_contents($tmpFile, '');

    $this->artisan('wiktionary:import', ['file' => $tmpFile, '--lang' => 'en', '--target-lang' => 'en'])
        ->expectsOutputToContain('Source language and target language must be different.')
        ->assertFailed();

    unlink($tmpFile);
});

it('imports any language present in the registry without seeded lookups', function () {
    $de = Language::query()->updateOrCreate(
        ['code' => 'de'],
        ['name' => 'German', 'is_enabled' => false, 'sort_order' => 5],
    );

    $tmpFile = tempnam(sys_get_temp_dir(), 'wiktionary_cmd_');
    file_put_contents($tmpFile, implode("\n", [
        json_encode(['word' => 'Hund', 'pos' => 'noun', 'senses' => [['glosses' => ['A dog']]]]),
    ])."\n");

    $this->artisan('wiktionary:import', ['file' => $tmpFile, '--lang' => 'de', '--target-lang' => 'en'])
        ->expectsOutputToContain('Import completed!')
        ->assertSuccessful();

    expect(Word::where('word', 'Hund')->where('language_id', $de->id)->count())->toBe(1);
    expect(WordClass::where('language_id', $de->id)->where('slug', 'noun')->exists())->toBeTrue();

    unlink($tmpFile);
});
