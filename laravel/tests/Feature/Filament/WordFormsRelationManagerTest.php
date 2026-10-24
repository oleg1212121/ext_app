<?php

use App\Filament\Resources\WordResource\Pages\EditWord;
use App\Filament\Resources\WordResource\RelationManagers\FormsRelationManager;
use App\Models\Form;
use App\Models\User;
use App\Models\Word;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    createWordClasses();
});

function formsManager(Word $owner)
{
    return Livewire::test(FormsRelationManager::class, [
        'ownerRecord' => $owner,
        'pageClass' => EditWord::class,
    ]);
}

it('creates a form with the normalized lookup key when l_word is left blank', function () {
    $owner = createWord('ru', 'дом');

    formsManager($owner)
        ->callTableAction(CreateAction::class, data: ['form' => 'Дома'])
        ->assertHasNoTableActionErrors();

    $form = Form::query()->where('word_id', $owner->id)->first();

    expect($form)->not->toBeNull()
        ->and($form->form)->toBe('Дома')
        ->and($form->l_word)->toBe('дома');
});

it('strips the stress marks off a manually created ru form', function () {
    $owner = createWord('ru', 'дом');

    formsManager($owner)
        ->callTableAction(CreateAction::class, data: ['form' => 'до́ма'])
        ->assertHasNoTableActionErrors();

    $form = Form::query()->where('word_id', $owner->id)->first();

    // Rule 1: no combining marks in stored forms — the stressed variant
    // collapses into the plain spelling.
    expect($form->form)->toBe('дома')
        ->and($form->l_word)->toBe('дома');
});

it('normalizes the lookup key on the en create and edit paths', function () {
    $owner = createWord('en', 'turn');

    formsManager($owner)
        ->callTableAction(CreateAction::class, data: ['form' => 'Turning'])
        ->assertHasNoTableActionErrors();

    $form = Form::query()->where('word_id', $owner->id)->first();

    expect($form->l_word)->toBe('turning');

    formsManager($owner)
        ->callTableAction(EditAction::class, $form, data: ['form' => 'Turned', 'l_word' => null])
        ->assertHasNoTableActionErrors();

    expect($form->refresh()->form)->toBe('Turned')
        ->and($form->l_word)->toBe('turned');
});
