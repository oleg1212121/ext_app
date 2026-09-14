<?php

use App\Models\EntitySentence;
use App\Models\EntityWord;
use App\Models\Form;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

function indexAndLink(int $entityId): void
{
    Artisan::call('crossword:index');
    Artisan::call("crossword:link --entity={$entityId}");
}

it('links inflected tokens through the forms table', function () {
    $entity = createEntity('ru');
    EntitySentence::query()->create([
        'entity_id' => $entity->id,
        'content' => 'Кошку кормили.',
        'order' => 1024,
    ]);

    $cat = createWord('ru', 'кот', 'noun');
    Form::query()->create(['word_id' => $cat->id, 'form' => 'кошку', 'l_word' => 'кошку']);

    indexAndLink($entity->id);

    $row = EntityWord::query()->where('entity_id', $entity->id)->where('l_word', 'кошку')->first();
    expect($row?->word_id)->toBe($cat->id);
});

it('prefers an exact dictionary match over a form match', function () {
    $entity = createEntity('ru');
    EntitySentence::query()->create([
        'entity_id' => $entity->id,
        'content' => 'Кошка спит.',
        'order' => 1024,
    ]);

    $directEntry = createWord('ru', 'кошка', 'noun');
    $otherEntry = createWord('ru', 'кот', 'noun');
    Form::query()->create(['word_id' => $otherEntry->id, 'form' => 'кошка', 'l_word' => 'кошка']);

    indexAndLink($entity->id);

    $row = EntityWord::query()->where('entity_id', $entity->id)->where('l_word', 'кошка')->first();
    expect($row?->word_id)->toBe($directEntry->id)
        ->and($row->word_id)->not->toBe($otherEntry->id);
});

it('applies the word class priority to form candidates', function () {
    $entity = createEntity('ru');
    EntitySentence::query()->create([
        'entity_id' => $entity->id,
        'content' => 'Столом накрыли.',
        'order' => 1024,
    ]);

    $noun = createWord('ru', 'стол', 'noun');
    $verb = createWord('ru', 'столоваться', 'verb');
    Form::query()->create(['word_id' => $verb->id, 'form' => 'столом', 'l_word' => 'столом']);
    Form::query()->create(['word_id' => $noun->id, 'form' => 'столом', 'l_word' => 'столом']);

    indexAndLink($entity->id);

    $row = EntityWord::query()->where('entity_id', $entity->id)->where('l_word', 'столом')->first();
    expect($row?->word_id)->toBe($noun->id);
});

it('does not cross languages when linking forms', function () {
    $entity = createEntity('en');
    EntitySentence::query()->create([
        'entity_id' => $entity->id,
        'content' => 'Cats sleep.',
        'order' => 1024,
    ]);

    $ruCat = createWord('ru', 'кот', 'noun');
    Form::query()->create(['word_id' => $ruCat->id, 'form' => 'cats', 'l_word' => 'cats']);

    indexAndLink($entity->id);

    $row = EntityWord::query()->where('entity_id', $entity->id)->where('l_word', 'cats')->first();
    expect($row?->word_id)->toBeNull();
});

it('stays idempotent when run twice', function () {
    $entity = createEntity('ru');
    EntitySentence::query()->create([
        'entity_id' => $entity->id,
        'content' => 'Кошку кормили.',
        'order' => 1024,
    ]);

    $cat = createWord('ru', 'кот', 'noun');
    Form::query()->create(['word_id' => $cat->id, 'form' => 'кошку', 'l_word' => 'кошку']);

    indexAndLink($entity->id);
    indexAndLink($entity->id);

    expect(EntityWord::query()->where('entity_id', $entity->id)->whereNotNull('word_id')->count())->toBe(1);
});
