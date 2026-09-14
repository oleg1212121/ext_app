<?php

use App\Filament\Resources\WordResource\Pages\EditWord;
use App\Filament\Resources\WordResource\RelationManagers\TranslationsRelationManager;
use App\Models\User;
use App\Models\Word;
use App\Models\WordTranslation;
use Filament\Actions\DeleteAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    createWordClasses();
});

function translationsManager(Word $owner)
{
    return Livewire::test(TranslationsRelationManager::class, [
        'ownerRecord' => $owner,
        'pageClass' => EditWord::class,
    ]);
}

it('lists links from both sides with the other word', function () {
    $owner = createWord('en', 'cat');
    $ruWord = createWord('ru', 'кошка');
    $otherEn = createWord('en', 'dog');

    // Owner is the b-side of the first link, a-side of the second.
    WordTranslation::link($ruWord->id, $owner->id);
    WordTranslation::link($owner->id, $otherEn->id);

    $links = WordTranslation::query()->get();

    translationsManager($owner)
        ->assertCanSeeTableRecords($links);
});

it('attach creates one canonical row visible from both words', function () {
    $owner = createWord('en', 'cat');
    $target = createWord('ru', 'кошка');

    translationsManager($owner)
        ->callAction('attachTranslation', data: ['translation_word_id' => $target->id])
        ->assertHasNoActionErrors();

    expect(WordTranslation::count())->toBe(1)
        ->and($owner->translationWords()->pluck('id'))->toContain($target->id)
        ->and($target->translationWords()->pluck('id'))->toContain($owner->id);

    // The link shows up on the other word's page too.
    translationsManager($target)
        ->assertCanSeeTableRecords(WordTranslation::all());
});

it('rejects attaching a same-language word', function () {
    $owner = createWord('en', 'cat');
    $sameLanguage = createWord('en', 'feline');

    translationsManager($owner)
        ->callAction('attachTranslation', data: ['translation_word_id' => $sameLanguage->id])
        ->assertHasActionErrors(['translation_word_id']);

    expect(WordTranslation::count())->toBe(0);
});

it('rejects attaching an already linked word', function () {
    $owner = createWord('en', 'cat');
    $target = createWord('ru', 'кошка');
    WordTranslation::link($owner->id, $target->id);

    translationsManager($owner)
        ->callAction('attachTranslation', data: ['translation_word_id' => $target->id])
        ->assertHasActionErrors(['translation_word_id']);

    expect(WordTranslation::count())->toBe(1);
});

it('create word & link creates the missing target word and links it', function () {
    $owner = createWord('en', 'cat');
    $languageId = \App\Models\Language::query()->where('code', 'ru')->value('id');
    $classId = \App\Models\WordClass::query()->where('language_id', $languageId)->where('slug', 'noun')->value('id');

    translationsManager($owner)
        ->callAction('createWordAndLink', data: [
            'word' => 'кошка',
            'language_id' => $languageId,
            'word_class_id' => $classId,
        ])
        ->assertHasNoActionErrors();

    $created = Word::query()->where('word', 'кошка')->where('language_id', $languageId)->first();

    expect($created)->not->toBeNull()
        ->and($created->l_word)->toBe('кошка')
        ->and(WordTranslation::count())->toBe(1)
        ->and($owner->translationWords()->pluck('id'))->toContain($created->id);
});

it('create word & link reuses an existing word instead of duplicating it', function () {
    $owner = createWord('en', 'cat');
    $existing = createWord('ru', 'кошка');
    $languageId = \App\Models\Language::query()->where('code', 'ru')->value('id');
    $classId = $existing->word_class_id;

    translationsManager($owner)
        ->callAction('createWordAndLink', data: [
            'word' => 'кошка',
            'language_id' => $languageId,
            'word_class_id' => $classId,
        ])
        ->assertHasNoActionErrors();

    expect(Word::query()->where('word', 'кошка')->where('language_id', $languageId)->count())->toBe(1)
        ->and(WordTranslation::count())->toBe(1);
});

it('delete removes the single pivot row from either side', function () {
    $owner = createWord('en', 'cat');
    $target = createWord('ru', 'кошка');
    $link = WordTranslation::link($target->id, $owner->id);

    translationsManager($target)
        ->callTableAction(DeleteAction::class, $link);

    expect(WordTranslation::count())->toBe(0);
});
