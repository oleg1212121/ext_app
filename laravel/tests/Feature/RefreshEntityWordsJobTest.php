<?php

use App\Classes\EntityWordIndexer;
use App\Classes\EntityWordLinker;
use App\Jobs\RefreshEntityWords;
use App\Models\EntitySentence;
use App\Models\EntityWord;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function runRefreshJob($entity): void
{
    (new RefreshEntityWords($entity->id))
        ->handle(app(EntityWordIndexer::class), app(EntityWordLinker::class));
}

function refreshSentence($entity, string $content): EntitySentence
{
    return EntitySentence::query()->create([
        'entity_id' => $entity->id,
        'content' => $content,
        'order' => 1024,
    ]);
}

it('builds and links the word list for a stale entity', function () {
    $entity = createEntity('en');
    refreshSentence($entity, 'The cat sleeps. The dog barks.');
    $catNoun = createWord('en', 'cat', 'noun');
    createWord('en', 'cat', 'unknown');
    createWord('en', 'dog', 'verb');

    runRefreshJob($entity);

    $rows = EntityWord::query()->where('entity_id', $entity->id)->get()->keyBy('l_word');
    expect($rows)->toHaveCount(5);
    // Noun wins over the same lowercase form's other word classes.
    expect($rows['cat']->word_id)->toBe($catNoun->id);
    expect($rows['dog']->word_id)->not->toBeNull();
    expect($rows['the']->word_id)->toBeNull();
    expect($entity->refresh()->words_indexed_at)->not->toBeNull();
});

it('does not re-index when the list is fresh but still links unlinked tokens', function () {
    $entity = createEntity('en');
    refreshSentence($entity, 'The cat sleeps.');
    $cat = createWord('en', 'cat', 'noun');

    app(EntityWordIndexer::class)->index($entity);

    $idsBefore = EntityWord::query()->where('entity_id', $entity->id)->orderBy('id')->pluck('id')->all();

    runRefreshJob($entity);

    // No wholesale rebuild: the row ids survive.
    expect(EntityWord::query()->where('entity_id', $entity->id)->orderBy('id')->pluck('id')->all())->toBe($idsBefore);
    // But the link pass ran.
    expect(EntityWord::query()->where('entity_id', $entity->id)->where('l_word', 'cat')->value('word_id'))->toBe($cat->id);
});

it('is idempotent on a second run', function () {
    $entity = createEntity('en');
    refreshSentence($entity, 'The cat sleeps.');
    createWord('en', 'cat', 'noun');

    runRefreshJob($entity);
    $before = EntityWord::query()->where('entity_id', $entity->id)->orderBy('id')->get(['id', 'word_id'])->toJson();
    runRefreshJob($entity);
    $after = EntityWord::query()->where('entity_id', $entity->id)->orderBy('id')->get(['id', 'word_id'])->toJson();

    expect($after)->toBe($before);
});

it('skips a missing entity without failing', function () {
    (new RefreshEntityWords(999999))->handle(app(EntityWordIndexer::class), app(EntityWordLinker::class));

    expect(EntityWord::query()->count())->toBe(0);
});
