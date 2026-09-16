<?php

use App\Models\Language;
use App\Models\Word;
use App\Models\WordClass;
use App\Models\WordTranslation;

beforeEach(function () {
    createLanguages();
});

function makeImportFixture(string $gzPath, array $lines): void
{
    file_put_contents($gzPath, gzencode(implode("\n", $lines)."\n"));
}

function importFixtureBase(string $gzPath): string
{
    $base = substr($gzPath, 0, -3);

    return str_ends_with($base, '.jsonl') ? substr($base, 0, -6) : $base;
}

function cleanupImportFixture(string $gzPath, array $codes): void
{
    @unlink($gzPath);
    foreach ($codes as $code) {
        @unlink(importFixtureBase($gzPath).'.'.$code.'.jsonl');
    }
}

function catAndCatFixture(): array
{
    return [
        '{"word": "cat", "pos": "noun", "lang": "English", "lang_code": "en", "senses": [{"glosses": ["A small domesticated feline"], "translations": [{"code": "ru", "word": "кошка"}]}]}',
        '{"word": "кошка", "pos": "noun", "lang": "Russian", "lang_code": "ru", "senses": [{"glosses": ["Кошка — домашнее животное"], "translations": [{"code": "en", "word": "cat"}]}]}',
        '{"word": "chien", "pos": "noun", "lang": "French", "lang_code": "fr", "senses": [{"glosses": ["Chien"]}, {"glosses": ["Dog"]}]}',
    ];
}

it('imports en and ru end to end, stages translations symmetrically and links by default', function () {
    $gzPath = sys_get_temp_dir().'/raw-import-'.uniqid().'.jsonl.gz';
    makeImportFixture($gzPath, catAndCatFixture());

    $this->artisan('wiktionary:import-raw', ['file' => $gzPath])
        ->assertSuccessful();

    $en = Language::query()->where('code', 'en')->first();
    $ru = Language::query()->where('code', 'ru')->first();

    $cat = Word::query()->where('word', 'cat')->where('language_id', $en->id)->first();
    $koshka = Word::query()->where('word', 'кошка')->where('language_id', $ru->id)->first();

    expect($cat)->not->toBeNull();
    expect($koshka)->not->toBeNull();
    expect(Word::query()->where('language_id', $ru->id)->where('word', 'chien')->exists())->toBeFalse();

    expect($cat->translations)->toContain('кошка');
    expect($koshka->translations)->toContain('cat');

    expect(WordTranslation::count())->toBe(1);

    $link = WordTranslation::query()->first();
    $pair = [$link->word_a_id, $link->word_b_id];
    sort($pair);
    expect($pair)->toBe([min($cat->id, $koshka->id), max($cat->id, $koshka->id)]);

    expect(file_exists(importFixtureBase($gzPath).'.en.jsonl'))->toBeTrue();
    expect(file_exists(importFixtureBase($gzPath).'.ru.jsonl'))->toBeTrue();

    cleanupImportFixture($gzPath, ['en', 'ru']);
});

it('leaves translations unlinked with --no-link', function () {
    $gzPath = sys_get_temp_dir().'/raw-import-'.uniqid().'.jsonl.gz';
    makeImportFixture($gzPath, catAndCatFixture());

    $this->artisan('wiktionary:import-raw', ['file' => $gzPath, '--no-link' => true])
        ->assertSuccessful();

    expect(Word::count())->toBe(2);
    expect(WordTranslation::count())->toBe(0);

    cleanupImportFixture($gzPath, ['en', 'ru']);
});

it('touches no database rows with --extract-only', function () {
    $gzPath = sys_get_temp_dir().'/raw-import-'.uniqid().'.jsonl.gz';
    makeImportFixture($gzPath, catAndCatFixture());

    $this->artisan('wiktionary:import-raw', ['file' => $gzPath, '--extract-only' => true])
        ->assertSuccessful();

    expect(Word::count())->toBe(0);
    expect(file_exists(importFixtureBase($gzPath).'.en.jsonl'))->toBeTrue();
    expect(file_exists(importFixtureBase($gzPath).'.ru.jsonl'))->toBeTrue();

    cleanupImportFixture($gzPath, ['en', 'ru']);
});

it('reuses existing extract files with --skip-extract', function () {
    $gzPath = sys_get_temp_dir().'/raw-import-'.uniqid().'.jsonl.gz';
    makeImportFixture($gzPath, catAndCatFixture());

    $markerEn = '{"word": "marker", "pos": "noun", "lang": "English", "lang_code": "en", "senses": [{"glosses": ["Marked"]}]}';
    $markerRu = '{"word": "метка", "pos": "noun", "lang": "Russian", "lang_code": "ru", "senses": [{"glosses": ["Метка"]}]}';
    file_put_contents(importFixtureBase($gzPath).'.en.jsonl', $markerEn."\n");
    file_put_contents(importFixtureBase($gzPath).'.ru.jsonl', $markerRu."\n");

    $this->artisan('wiktionary:import-raw', ['file' => $gzPath, '--skip-extract' => true, '--no-link' => true])
        ->assertSuccessful();

    expect(file_get_contents(importFixtureBase($gzPath).'.en.jsonl'))->toBe($markerEn."\n");
    expect(file_get_contents(importFixtureBase($gzPath).'.ru.jsonl'))->toBe($markerRu."\n");

    $en = Language::query()->where('code', 'en')->first();
    $ru = Language::query()->where('code', 'ru')->first();
    expect(Word::query()->where('word', 'marker')->where('language_id', $en->id)->exists())->toBeTrue();
    expect(Word::query()->where('word', 'метка')->where('language_id', $ru->id)->exists())->toBeTrue();
    expect(Word::query()->where('word', 'cat')->exists())->toBeFalse();

    cleanupImportFixture($gzPath, ['en', 'ru']);
});

it('wipes only the selected languages with --fresh --force', function () {
    $ru = Language::query()->where('code', 'ru')->first();
    $nounClass = WordClass::query()->create([
        'language_id' => $ru->id, 'slug' => 'noun', 'title' => 'Существительное', 'description' => 'Test',
    ]);
    Word::query()->create(['language_id' => $ru->id, 'word' => 'старое', 'l_word' => 'старое', 'word_class_id' => $nounClass->id]);

    $gzPath = sys_get_temp_dir().'/raw-import-'.uniqid().'.jsonl.gz';
    makeImportFixture($gzPath, catAndCatFixture());

    $this->artisan('wiktionary:import-raw', ['file' => $gzPath, '--fresh' => true, '--force' => true, '--no-link' => true])
        ->assertSuccessful();

    expect(Word::query()->where('word', 'старое')->exists())->toBeFalse();

    $en = Language::query()->where('code', 'en')->first();
    expect(Word::query()->where('word', 'cat')->where('language_id', $en->id)->exists())->toBeTrue();
    expect(Word::query()->where('word', 'кошка')->where('language_id', $ru->id)->exists())->toBeTrue();
    expect(Word::count())->toBe(2);

    cleanupImportFixture($gzPath, ['en', 'ru']);
});

it('aborts --fresh without confirmation', function () {
    $gzPath = sys_get_temp_dir().'/raw-import-'.uniqid().'.jsonl.gz';
    makeImportFixture($gzPath, catAndCatFixture());

    $this->artisan('wiktionary:import-raw', ['file' => $gzPath, '--fresh' => true])
        ->assertFailed();

    expect(Word::count())->toBe(0);

    cleanupImportFixture($gzPath, ['en', 'ru']);
});

it('rejects unknown languages', function () {
    $gzPath = sys_get_temp_dir().'/raw-import-'.uniqid().'.jsonl.gz';
    makeImportFixture($gzPath, catAndCatFixture());

    $this->artisan('wiktionary:import-raw', ['file' => $gzPath, '--langs' => 'en,xx'])
        ->assertFailed();

    expect(Word::count())->toBe(0);

    cleanupImportFixture($gzPath, ['en', 'ru']);
});

it('imports a single language and stages the given target with --target-langs', function () {
    $gzPath = sys_get_temp_dir().'/raw-import-'.uniqid().'.jsonl.gz';
    makeImportFixture($gzPath, catAndCatFixture());

    $this->artisan('wiktionary:import-raw', [
        'file' => $gzPath,
        '--langs' => 'en',
        '--target-langs' => 'ru',
        '--no-link' => true,
    ])->assertSuccessful();

    $en = Language::query()->where('code', 'en')->first();
    $cat = Word::query()->where('word', 'cat')->where('language_id', $en->id)->first();

    expect($cat)->not->toBeNull();
    expect($cat->translations)->toContain('кошка');
    expect(Word::query()->where('word', 'кошка')->exists())->toBeFalse();

    cleanupImportFixture($gzPath, ['en', 'ru']);
});
