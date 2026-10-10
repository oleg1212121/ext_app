<?php

use App\Models\Language;
use App\Models\UiString;
use App\Models\UiStringKey;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UiStringSeeder;

function seededUiStringTexts(string $key): array
{
    $stringKey = UiStringKey::query()->where('key', $key)->firstOrFail();

    return UiString::query()
        ->where('ui_string_key_id', $stringKey->id)
        ->get()
        ->mapWithKeys(fn (UiString $string): array => [$string->language->code => $string->text])
        ->all();
}

it('seeds every interface-enabled language that has a value for the key', function () {
    app(LanguageSeeder::class)->run();
    app(UiStringSeeder::class)->run();

    // reader.no_texts is the one partial key carrying a be value: en/ru come
    // out byte-identical to before, and be appears now that the registry
    // marks it interface-enabled.
    expect(seededUiStringTexts('reader.no_texts'))->toBe([
        'en' => 'No texts in this language',
        'ru' => 'На этом языке текстов нет',
        'be' => 'На гэтай мове тэкстаў няма',
    ]);
});

it('creates no rows for keys a language has no value for', function () {
    app(LanguageSeeder::class)->run();
    app(UiStringSeeder::class)->run();

    // reader.russian carries no be value yet (ticket 06 ships the rest):
    // the English fallback chain covers it at render time (ADR 0023),
    // so seeding must not create an empty be row.
    $be = Language::query()->where('code', 'be')->firstOrFail();

    expect(seededUiStringTexts('reader.russian'))->toBe([
        'en' => 'Russian',
        'ru' => 'Русский',
    ])
        ->and(UiString::query()
            ->whereHas('key', fn ($query) => $query->where('key', 'reader.russian'))
            ->where('language_id', $be->id)
            ->exists())->toBeFalse();
});

it('skips languages that are not interface-enabled', function () {
    app(LanguageSeeder::class)->run();
    Language::query()->where('code', 'be')->update(['is_interface_enabled' => false]);
    app(UiStringSeeder::class)->run();

    // The loop derives from the interface-enabled registry, not a hardcoded
    // pair: with be flagged off the seeder behaves exactly as before ticket 05.
    $be = Language::query()->where('code', 'be')->firstOrFail();

    expect(UiString::query()->where('language_id', $be->id)->exists())->toBeFalse();
});

it('re-runs idempotently', function () {
    app(LanguageSeeder::class)->run();
    app(UiStringSeeder::class)->run();
    $afterFirstRun = UiString::query()->count();

    app(UiStringSeeder::class)->run();

    expect(UiString::query()->count())->toBe($afterFirstRun)
        ->and(seededUiStringTexts('reader.no_texts'))->toBe([
            'en' => 'No texts in this language',
            'ru' => 'На этом языке текстов нет',
            'be' => 'На гэтай мове тэкстаў няма',
        ]);
});
