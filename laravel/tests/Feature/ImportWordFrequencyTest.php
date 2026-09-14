<?php

use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    createLanguages();
    createWordClasses();
});

it('updates frequency for all word classes of a spelling', function () {
    $theNoun = createWord('en', 'the', 'noun');
    $theUnknown = createWord('en', 'the', 'unknown');
    $and = createWord('en', 'and', 'verb');
    $csv = database_path('frequency/en-sample.csv');

    $this->artisan('words:import-frequency', ['file' => $csv, '--lang' => 'en']);

    expect((int) $theNoun->refresh()->frequency)->toBe(1);
    expect((int) $theUnknown->refresh()->frequency)->toBe(1);
    expect((int) $and->refresh()->frequency)->toBe(5);
});

it('skips words missing from the dictionary and never creates words', function () {
    createWord('en', 'the', 'noun');
    $before = Word::query()->count();
    $csv = database_path('frequency/en-sample.csv');

    $this->artisan('words:import-frequency', ['file' => $csv, '--lang' => 'en']);

    expect(Word::query()->count())->toBe($before);
});

it('is idempotent on re-run', function () {
    $word = createWord('en', 'be', 'verb');
    $csv = database_path('frequency/en-sample.csv');

    $this->artisan('words:import-frequency', ['file' => $csv, '--lang' => 'en']);
    $this->artisan('words:import-frequency', ['file' => $csv, '--lang' => 'en']);

    expect((int) $word->refresh()->frequency)->toBe(2);
});

it('fails on unknown language', function () {
    $csv = database_path('frequency/en-sample.csv');

    $this->artisan('words:import-frequency', ['file' => $csv, '--lang' => 'xx'])
        ->assertFailed();
});
