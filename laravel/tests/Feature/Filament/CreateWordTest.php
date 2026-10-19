<?php

use App\Filament\Resources\WordResource\Pages\CreateWord;
use App\Models\Language;
use App\Models\User;
use App\Models\Word;
use App\Models\WordClass;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    createWordClasses();
});

it('autofills a blank l_word with the shared lookup key on create', function () {
    $languageId = Language::query()->where('code', 'ru')->value('id');
    $classId = WordClass::query()->where('language_id', $languageId)->where('slug', 'noun')->value('id');

    // The key is the uniqueness identity now: a blank one must never store
    // an unkeyed row — it autofills through the shared normalization
    // (ё folds, marks and zero-width characters strip).
    Livewire::test(CreateWord::class)
        ->fillForm([
            'language_id' => $languageId,
            'word' => 'Ёлка',
            'word_class_id' => $classId,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Word::query()->where('word', 'Ёлка')->where('language_id', $languageId)->firstOrFail()->l_word)
        ->toBe('елка');
});

it('keeps an explicitly typed l_word', function () {
    $languageId = Language::query()->where('code', 'en')->value('id');
    $classId = WordClass::query()->where('language_id', $languageId)->where('slug', 'noun')->value('id');

    Livewire::test(CreateWord::class)
        ->fillForm([
            'language_id' => $languageId,
            'word' => 'March',
            'l_word' => 'march',
            'word_class_id' => $classId,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Word::query()->where('word', 'March')->where('language_id', $languageId)->firstOrFail()->l_word)
        ->toBe('march');
});
