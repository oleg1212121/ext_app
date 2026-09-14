<?php

use App\Classes\EntityWordIndexer;
use App\Jobs\RefreshEntityWords;
use App\Models\EntitySentence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function addRefreshSentence($entity, string $content): EntitySentence
{
    return EntitySentence::query()->create([
        'entity_id' => $entity->id,
        'content' => $content,
        'order' => EntitySentence::query()->where('entity_id', $entity->id)->count() * 1024 + 1024,
    ]);
}

function refreshJobEntityId(object $job): int
{
    return Closure::bind(fn (): int => $this->entityId, $job, $job::class)();
}

it('dispatches refresh jobs for entities with an unbuilt word list', function () {
    Queue::fake();

    $entity = createEntity('en');
    addRefreshSentence($entity, 'The cat sat on the mat.');

    $this->artisan('crossword:refresh')
        ->assertSuccessful()
        ->expectsOutput('Dispatched 1 word-list refresh jobs.');

    Queue::assertPushed(RefreshEntityWords::class, fn ($job) => refreshJobEntityId($job) === $entity->id);
});

it('dispatches for a fresh entity whose tokens are unlinked', function () {
    Queue::fake();

    $entity = createEntity('en');
    addRefreshSentence($entity, 'The cat sat.');
    app(EntityWordIndexer::class)->index($entity);
    expect($entity->refresh()->words_indexed_at)->not->toBeNull();

    $this->artisan('crossword:refresh')->assertSuccessful();

    Queue::assertPushed(RefreshEntityWords::class, fn ($job) => refreshJobEntityId($job) === $entity->id);
});

it('skips entities whose word list is fresh and fully linked', function () {
    Queue::fake();

    $entity = createEntity('en');
    addRefreshSentence($entity, 'The cat sat.');
    createWord('en', 'the');
    createWord('en', 'cat');
    createWord('en', 'sat');
    Artisan::call('crossword:index');
    Artisan::call('crossword:link');

    $this->artisan('crossword:refresh')
        ->assertSuccessful()
        ->expectsOutput('No entities need a word-list refresh.');

    Queue::assertNotPushed(RefreshEntityWords::class);
});

it('skips entities without sentences', function () {
    Queue::fake();

    createEntity('en'); // never indexed, no sentences

    $this->artisan('crossword:refresh')->assertSuccessful();

    Queue::assertNotPushed(RefreshEntityWords::class);
});

it('forces refresh for explicitly given entity ids', function () {
    Queue::fake();

    $entity = createEntity('en'); // no sentences, fully fresh — forced anyway

    $this->artisan("crossword:refresh --entity={$entity->id}")->assertSuccessful();

    Queue::assertPushed(RefreshEntityWords::class, fn ($job) => refreshJobEntityId($job) === $entity->id);
});

it('respects the limit option', function () {
    Queue::fake();

    $first = createEntity('en');
    $second = createEntity('en');
    $third = createEntity('en');
    foreach ([$first, $second, $third] as $entity) {
        addRefreshSentence($entity, 'The cat sat.');
    }

    $this->artisan('crossword:refresh --limit=2')->assertSuccessful();

    Queue::assertPushed(RefreshEntityWords::class, 2);
    Queue::assertPushed(RefreshEntityWords::class, fn ($job) => refreshJobEntityId($job) === $first->id);
    Queue::assertPushed(RefreshEntityWords::class, fn ($job) => refreshJobEntityId($job) === $second->id);
});

it('reports without dispatching on dry-run', function () {
    Queue::fake();

    $entity = createEntity('en');
    addRefreshSentence($entity, 'The cat sat.');

    $this->artisan('crossword:refresh --dry-run')
        ->assertSuccessful()
        ->expectsOutput("Would refresh entity #{$entity->id} ({$entity->name})")
        ->expectsOutput('Would dispatch 1 word-list refresh jobs.');

    Queue::assertNothingPushed();
});
