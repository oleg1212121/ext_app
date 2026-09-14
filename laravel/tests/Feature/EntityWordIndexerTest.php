<?php

use App\Classes\EntityWordIndexer;
use App\Models\EntitySentence;
use App\Models\EntityWord;
use App\Models\Word;

function addSentence($entity, string $content): EntitySentence
{
    return EntitySentence::query()->create([
        'entity_id' => $entity->id,
        'content' => $content,
        'order' => EntitySentence::query()->where('entity_id', $entity->id)->count() * 1024 + 1024,
    ]);
}

it('indexes unique words with counts across sentences', function () {
    $entity = createEntity('en');

    addSentence($entity, 'The quick brown fox jumps over the lazy dog.');
    addSentence($entity, 'The dog barks, and the fox runs.');

    $count = app(EntityWordIndexer::class)->index($entity);

    expect($count)->toBe(EntityWord::query()->where('entity_id', $entity->id)->count());

    $the = EntityWord::query()->where('entity_id', $entity->id)->where('l_word', 'the')->first();
    $dog = EntityWord::query()->where('entity_id', $entity->id)->where('l_word', 'dog')->first();

    expect($the->count)->toBe(4);
    expect($the->token)->toBe('The');
    expect($dog->count)->toBe(2);
    expect($entity->refresh()->words_indexed_at)->not->toBeNull();
});

it('is idempotent on re-run', function () {
    $entity = createEntity('en');
    addSentence($entity, 'One two three, one two.');

    $indexer = app(EntityWordIndexer::class);
    $indexer->index($entity);
    $first = $indexer->index($entity);

    expect($first)->toBe(3);
    expect(EntityWord::query()->where('entity_id', $entity->id)->where('l_word', 'one')->value('count'))->toBe(2);
});

it('detects staleness after a sentence change', function () {
    $entity = createEntity('en');
    $sentence = addSentence($entity, 'Fresh text.');

    $indexer = app(EntityWordIndexer::class);
    expect($indexer->isStale($entity))->toBeTrue();

    $indexer->index($entity);
    expect($indexer->isStale($entity->refresh()))->toBeFalse();

    sleep(1);
    $sentence->update(['content' => 'Changed text.']);

    expect($indexer->isStale($entity->refresh()))->toBeTrue();
});

it('links entity words to dictionary words preferring noun class', function () {
    $entity = createEntity('en');
    addSentence($entity, 'A book and a run.');

    $noun = createWord('en', 'book', 'noun');
    createWord('en', 'run', 'verb');

    app(EntityWordIndexer::class)->index($entity);

    $this->artisan('crossword:link', ['--entity' => [$entity->id]]);

    $book = EntityWord::query()->where('entity_id', $entity->id)->where('l_word', 'book')->first();
    $run = EntityWord::query()->where('entity_id', $entity->id)->where('l_word', 'run')->first();
    $and = EntityWord::query()->where('entity_id', $entity->id)->where('l_word', 'and')->first();

    expect($book->word_id)->toBe($noun->id);
    expect($run->word_id)->not->toBeNull();
    expect($and->word_id)->toBeNull();
});

it('prefers noun over verb for ambiguous spellings and stays idempotent', function () {
    $entity = createEntity('en');
    addSentence($entity, 'The work.');

    $noun = createWord('en', 'work', 'noun');
    createWord('en', 'work', 'verb');

    app(EntityWordIndexer::class)->index($entity);

    $this->artisan('crossword:link', ['--entity' => [$entity->id]]);
    $this->artisan('crossword:link', ['--entity' => [$entity->id]]);

    expect(EntityWord::query()->where('entity_id', $entity->id)->where('l_word', 'work')->value('word_id'))
        ->toBe($noun->id);
});

it('only links words of the entity language', function () {
    $entity = createEntity('en');
    addSentence($entity, 'Foreign text with book.');

    createWord('ru', 'book', 'noun');

    app(EntityWordIndexer::class)->index($entity);

    $this->artisan('crossword:link', ['--entity' => [$entity->id]]);

    expect(EntityWord::query()->where('entity_id', $entity->id)->where('l_word', 'book')->value('word_id'))
        ->toBeNull();
});

it('links words without a word_id even after the dictionary word is deleted', function () {
    $entity = createEntity('en');
    addSentence($entity, 'The vanish.');

    $word = createWord('en', 'vanish', 'verb');
    app(EntityWordIndexer::class)->index($entity);
    $this->artisan('crossword:link', ['--entity' => [$entity->id]]);

    $entityWordId = EntityWord::query()->where('entity_id', $entity->id)->where('l_word', 'vanish')->value('id');
    expect($entityWordId)->not->toBeNull();

    $word->delete();

    expect(EntityWord::query()->find($entityWordId)->refresh()->word_id)->toBeNull();
});
