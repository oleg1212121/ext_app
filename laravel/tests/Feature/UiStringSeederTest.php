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

it('seeds the model-aware popup mismatch string in all three interface languages', function () {
    app(LanguageSeeder::class)->run();
    app(UiStringSeeder::class)->run();

    // anki-popup-refresh review fixes: per-language note types can mismatch
    // too, so the popup copy interpolates the actual :model (mirroring the
    // profile tab's strings) instead of hardcoding the legacy base name.
    expect(seededUiStringTexts('word.anki_mismatch'))->toBe([
        'en' => 'The ":model" note type in Anki is out of date. In Anki, open Tools → Manage Note Types and delete ":model" (its notes are deleted with it), then sync again from Profile → Anki and send your words again.',
        'ru' => 'Тип заметок «:model» в Anki устарел. В Anki откройте «Инструменты → Управление типами заметок» и удалите «:model» (его заметки удалятся вместе с ним), затем снова синхронизируйте профиль → Anki и отправьте слова заново.',
        'be' => 'Тып нататак «:model» у Anki састарэў. У Anki адкрыйце «Інструменты → Кіраванне тыпамі нататак» і выдаліце «:model» (яго нататкі выдаляцца разам з ім), затым зноў сінхранізуйце профіль → Anki і адпраўце словы яшчэ раз.',
    ]);
});

it('seeds the sample-card updated string in all three interface languages', function () {
    app(LanguageSeeder::class)->run();
    app(UiStringSeeder::class)->run();

    // anki-popup-refresh review fixes: the sync layer reports {added,
    // updated}, so a re-sent sample that refreshed the app's own note in
    // place reports "updated" instead of "nothing was duplicated".
    expect(seededUiStringTexts('profile.anki_sample_updated'))->toBe([
        'en' => 'Sample card updated in ":deck".',
        'ru' => 'Пример карточки обновлён в «:deck».',
        'be' => 'Прыклад карткі абноўлены ў «:deck».',
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
        'word.ask_ai' => 'Спытаць ШІ',
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
