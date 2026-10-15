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

    // reader.no_texts already carried a be value since ticket 05: en/ru come
    // out byte-identical, and be is picked up through the interface-enabled
    // registry loop.
    expect(seededUiStringTexts('reader.no_texts'))->toBe([
        'en' => 'No texts in this language',
        'ru' => 'На этом языке текстов нет',
        'be' => 'На гэтай мове тэкстаў няма',
    ]);
});

it('seeds the Anki tab language-selector string in all three interface languages', function () {
    app(LanguageSeeder::class)->run();
    app(UiStringSeeder::class)->run();

    expect(seededUiStringTexts('profile.anki_language'))->toBe([
        'en' => 'Language',
        'ru' => 'Язык',
        'be' => 'Мова',
    ]);
});

it('seeds the word popup update-affordance strings in all three interface languages', function () {
    app(LanguageSeeder::class)->run();
    app(UiStringSeeder::class)->run();

    // anki-popup-refresh ticket 05: the toolbar icon's update form and its
    // "Updated in Anki." status — the duplicate dead-end's replacements.
    expect(seededUiStringTexts('word.anki_update'))->toBe([
        'en' => 'Update in Anki',
        'ru' => 'Обновить в Anki',
        'be' => 'Абнавіць у Anki',
    ]);
    expect(seededUiStringTexts('word.anki_updated'))->toBe([
        'en' => 'Updated in Anki.',
        'ru' => 'Обновлено в Anki.',
        'be' => 'Абноўлена ў Anki.',
    ]);
});

it('seeds be texts for sampled keys across every group', function () {
    app(LanguageSeeder::class)->run();
    app(UiStringSeeder::class)->run();

    // Ticket 06: every seeded key ships a Belarusian text — spot-check one
    // key per group file.
    $samples = [
        'ai.models_icon' => 'ШІ-мадэлі',
        'alignments.title' => 'Выраўноўванні',
        'auth.log_in' => 'Увайсці',
        'bilinguals.stress_marks' => 'Націскі',
        'crossword.build' => 'Сабраць',
        'dashboard.dashboard' => 'Панэль кіравання',
        'entities.save' => 'Захаваць',
        'library.work' => 'Твор',
        'nav.library' => 'Бібліятэка',
        'pending.log_out' => 'Выйсці',
        'profile.interface_language' => 'Мова інтэрфейсу',
        'reader.stress_marks' => 'Націскі',
        'recommendations.apply' => 'Ужыць',
        'sounds.group_vowels' => 'Галосныя',
        'welcome.about' => 'Пра праект',
        'word.tab_dictionary' => 'Слоўнік',
        'wordtest.submit' => 'Адправіць',
    ];

    foreach ($samples as $key => $text) {
        expect(seededUiStringTexts($key)['be'] ?? null)->toBe($text);
    }
});

it('creates no rows for keys a language has no value for', function () {
    app(LanguageSeeder::class)->run();

    // A registry row is all it takes to add an interface language: until its
    // texts are seeded, the English fallback chain covers it at render time
    // (ADR 0023), so seeding must not create empty rows for it.
    Language::create([
        'code' => 'uk',
        'name' => 'Ukrainian',
        'native_name' => 'Українська',
        'is_enabled' => true,
        'sort_order' => 3,
        'is_interface_enabled' => true,
    ]);

    app(UiStringSeeder::class)->run();

    $uk = Language::query()->where('code', 'uk')->firstOrFail();

    expect(UiString::query()->where('language_id', $uk->id)->exists())->toBeFalse();
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
