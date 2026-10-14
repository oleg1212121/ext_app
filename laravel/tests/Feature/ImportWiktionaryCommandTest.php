<?php

use App\Models\Definition;
use App\Models\Etymology;
use App\Models\Form;
use App\Models\Language;
use App\Models\Transcription;
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

it('accepts multiple comma-separated target languages', function () {
    Language::query()->updateOrCreate(
        ['code' => 'de'],
        ['name' => 'German', 'is_enabled' => false, 'sort_order' => 5],
    );

    $tmpFile = tempnam(sys_get_temp_dir(), 'wiktionary_cmd_');
    file_put_contents($tmpFile, json_encode(['word' => 'cat', 'pos' => 'noun'])."\n");

    $this->artisan('wiktionary:import', ['file' => $tmpFile, '--lang' => 'en', '--target-lang' => 'ru,de'])
        ->assertSuccessful();

    expect(Word::where('word', 'cat')->count())->toBe(1);

    unlink($tmpFile);
});

it('rejects one unknown language among multiple targets', function () {
    $tmpFile = tempnam(sys_get_temp_dir(), 'wiktionary_cmd_');
    file_put_contents($tmpFile, json_encode(['word' => 'cat', 'pos' => 'noun'])."\n");

    $this->artisan('wiktionary:import', ['file' => $tmpFile, '--lang' => 'en', '--target-lang' => 'ru,xx'])
        ->assertFailed();

    expect(Word::count())->toBe(0);

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

it('is idempotent on re-runs — definitions are not duplicated', function () {
    // Pins the insertNewOnly dedupe at the command level: the arrow-function
    // capture bug (fixed 2026-10, commit 871d0eb) made every re-import of the
    // same dump silently duplicate all definitions. The intra-file repeat of
    // the second cat sense collapses in mergeRecord — the guard under test is
    // the re-run against rows already in the database.
    $enLanguageId = createLanguages()['en']->id;
    $tmpFile = tempnam(sys_get_temp_dir(), 'wiktionary_reimport_');
    $lines = [
        json_encode([
            'word' => 'cat', 'pos' => 'noun',
            'senses' => [
                ['glosses' => ['A small domesticated feline']],
                ['glosses' => ['A carnivorous mammal']],
            ],
            'translations' => [['code' => 'ru', 'word' => 'кошка']],
            'forms' => [['form' => 'cats']],
            'sounds' => [['ipa' => '/kæt/']],
            'etymology_text' => 'Middle English cat.',
        ]),
        json_encode([
            'word' => 'cat', 'pos' => 'noun',
            'senses' => [
                ['glosses' => ['A carnivorous mammal']],
                ['glosses' => ['An inland sailboat']],
            ],
        ]),
        json_encode([
            'word' => 'dog', 'pos' => 'noun',
            'senses' => [['glosses' => ['A domesticated canine']]],
            'etymology_text' => 'Middle English dogge.',
        ]),
    ];
    file_put_contents($tmpFile, implode("\n", $lines)."\n");

    $import = fn () => $this->artisan('wiktionary:import', ['file' => $tmpFile, '--lang' => 'en', '--target-lang' => 'ru'])->assertSuccessful();

    $import();
    $import();
    $import();

    expect(Word::query()->where('language_id', $enLanguageId)->count())->toBe(2)
        ->and(Definition::query()->count())->toBe(4) // cat: 3 merged senses; dog: 1
        ->and(Etymology::query()->count())->toBe(2)
        ->and(Form::query()->count())->toBe(1)
        ->and(Transcription::query()->count())->toBe(1);

    // The dedupe key is (word_id, lower(definition)) — no pair may repeat.
    // ->get() then assert on the collection: ->count() over a grouped query
    // returns the first group's count, not the number of groups.
    $duplicates = Definition::query()
        ->selectRaw('word_id, lower(definition) as text, count(*) as n')
        ->groupByRaw('word_id, lower(definition)')
        ->havingRaw('count(*) > 1')
        ->get();
    expect($duplicates)->toBeEmpty();

    $cat = Word::query()->where('word', 'cat')->where('language_id', $enLanguageId)->firstOrFail();
    expect($cat->translations)->toBeArray()
        ->and($cat->translations)->toContain('кошка')
        ->and($cat->translations)->toHaveCount(1);

    unlink($tmpFile);
});
