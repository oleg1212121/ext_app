<?php

use App\Models\Language;
use App\Models\UiString;
use App\Models\UiStringKey;
use App\Support\UiStrings;
use Illuminate\Support\Facades\App;

beforeEach(function () {
    createLanguages();
    UiStrings::flush();
    App::setLocale('en');
});

function seedUiStringFixtures(): void
{
    $en = Language::where('code', 'en')->first();
    $ru = Language::where('code', 'ru')->first();

    $key = UiStringKey::create(['key' => 'test.greeting']);
    UiString::create(['ui_string_key_id' => $key->id, 'language_id' => $en->id, 'text' => 'Hello']);
    UiString::create(['ui_string_key_id' => $key->id, 'language_id' => $ru->id, 'text' => 'Привет']);
}

it('serves db ui strings through the translator', function () {
    seedUiStringFixtures();

    App::setLocale('en');
    expect(__('test.greeting'))->toBe('Hello');

    App::setLocale('ru');
    expect(__('test.greeting'))->toBe('Привет');
});

it('falls back to english when the locale value is missing', function () {
    $en = Language::where('code', 'en')->first();
    $key = UiStringKey::create(['key' => 'test.only_english']);
    UiString::create(['ui_string_key_id' => $key->id, 'language_id' => $en->id, 'text' => 'Only English']);

    App::setLocale('ru');
    expect(__('test.only_english'))->toBe('Only English');
});

it('renders unknown keys as the key itself', function () {
    App::setLocale('ru');

    expect(__('test.does_not_exist'))->toBe('test.does_not_exist');
});

it('reflects admin edits immediately without a manual cache clear', function () {
    seedUiStringFixtures();

    expect(UiStrings::mapFor('ru')['test.greeting'])->toBe('Привет');

    // Saved via a model (as the Filament CRUD does) — observers flush the cache.
    $string = UiString::whereHas('key', fn ($query) => $query->where('key', 'test.greeting'))
        ->whereHas('language', fn ($query) => $query->where('code', 'ru'))
        ->first();
    $string->update(['text' => 'Здравствуй']);

    expect(UiStrings::mapFor('ru')['test.greeting'])->toBe('Здравствуй');
});

it('builds a merged map with english underneath the locale', function () {
    seedUiStringFixtures();

    $map = UiStrings::mapFor('ru');

    expect($map['test.greeting'])->toBe('Привет');
});

it('translates strings per group through blade-style __()', function () {
    seedUiStringFixtures();
    $en = Language::where('code', 'en')->first();
    $key = UiStringKey::create(['key' => 'nav.grouped']);
    UiString::create(['ui_string_key_id' => $key->id, 'language_id' => $en->id, 'text' => 'Grouped']);

    App::setLocale('ru');
    expect(__('nav.grouped'))->toBe('Grouped');
    expect(__('nav'))->toBeArray();
});
