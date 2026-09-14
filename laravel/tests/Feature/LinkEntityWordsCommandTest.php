<?php

use App\Models\EntitySentence;
use App\Models\EntityWord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('links entity words through the crossword:link command and reports stats', function () {
    $entity = createEntity('en');
    EntitySentence::query()->create([
        'entity_id' => $entity->id,
        'content' => 'The cat sleeps.',
        'order' => 1024,
    ]);
    $cat = createWord('en', 'cat', 'noun');

    Artisan::call('crossword:index');
    Artisan::call('crossword:link');

    expect(Artisan::output())->toContain("Entity #{$entity->id} ({$entity->name}): 1 linked, 2 without a dictionary match.");

    $rows = EntityWord::query()->where('entity_id', $entity->id)->get()->keyBy('l_word');
    expect($rows['cat']->word_id)->toBe($cat->id);
    expect($rows['the']->word_id)->toBeNull();
});

it('links explicitly given entity ids only', function () {
    $linked = createEntity('en');
    $untouched = createEntity('en');
    foreach ([$linked, $untouched] as $entity) {
        EntitySentence::query()->create([
            'entity_id' => $entity->id,
            'content' => 'The cat sleeps.',
            'order' => 1024,
        ]);
    }
    $cat = createWord('en', 'cat', 'noun');

    Artisan::call('crossword:index');
    Artisan::call("crossword:link --entity={$linked->id}");

    $linkedRow = EntityWord::query()->where('entity_id', $linked->id)->where('l_word', 'cat')->first();
    $untouchedRow = EntityWord::query()->where('entity_id', $untouched->id)->where('l_word', 'cat')->first();
    expect($linkedRow->word_id)->toBe($cat->id);
    expect($untouchedRow->word_id)->toBeNull();
});
