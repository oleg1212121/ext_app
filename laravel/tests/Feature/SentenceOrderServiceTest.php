<?php

use App\Classes\SentenceOrderService;
use App\Enums\SentenceAnchor;
use App\Models\Entity;
use App\Models\EntitySentence;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function orderEntity(string $name = 'Order entity'): Entity
{
    return createEntity('en', null, ['name' => $name]);
}

function orderSentence(Entity $entity, int $order, string $content = 'Sentence'): EntitySentence
{
    return EntitySentence::create([
        'entity_id' => $entity->id,
        'content' => $content,
        'order' => $order,
    ]);
}

/**
 * The document orders of the entity's existing sentences, sorted.
 *
 * @return list<int>
 */
function placedOrders(Entity $entity): array
{
    return EntitySentence::query()
        ->where('entity_id', $entity->id)
        ->orderBy('order')
        ->orderBy('id')
        ->get(['order'])
        ->map(fn (EntitySentence $sentence): int => (int) $sentence->order)
        ->values()
        ->all();
}

it('places a sentence at the beginning of an empty document', function () {
    $entity = orderEntity();

    $order = app(SentenceOrderService::class)->place($entity->id, SentenceAnchor::beginning());

    expect($order)->toBe(0);
});

it('appends at the end one stride past the last sentence', function () {
    $entity = orderEntity();
    orderSentence($entity, 0);
    orderSentence($entity, 1024);

    $order = app(SentenceOrderService::class)->place($entity->id, SentenceAnchor::end());

    expect($order)->toBe(2048);
});

it('places a new sentence directly after its anchor sentence', function () {
    $entity = orderEntity();
    $first = orderSentence($entity, 0);
    orderSentence($entity, 1024);

    $order = app(SentenceOrderService::class)->place($entity->id, SentenceAnchor::after($first->id));

    expect($order)->toBe(512);
});

it('moves a sentence to the beginning and shifts every order non-negative', function () {
    $entity = orderEntity();
    $a = orderSentence($entity, 0);
    $b = orderSentence($entity, 1);
    $c = orderSentence($entity, 2);

    $order = app(SentenceOrderService::class)->place($entity->id, SentenceAnchor::beginning(), $c->id);

    expect($order)->toBe(0)
        ->and($c->refresh()->order)->toBe(0)
        ->and($a->refresh()->order)->toBe(1024)
        ->and($b->refresh()->order)->toBe(1025);
});

it('rebalances an exhausted gap without violating the unique index', function () {
    $entity = orderEntity();
    $a = orderSentence($entity, 0);
    orderSentence($entity, 1);
    orderSentence($entity, 2);

    // between(0, 1) has no room — the neighbourhood rebalance renumbers the
    // entity's sentences through the two-phase write under the
    // (entity_id, order) unique index.
    $order = app(SentenceOrderService::class)->place($entity->id, SentenceAnchor::after($a->id));

    expect($order)->toBe(512)
        ->and(placedOrders($entity))->toBe([0, 1024, 2048]);
});

it('bumps sentences_updated_at when an order changes and leaves it alone on a no-op', function () {
    $entity = orderEntity();
    orderSentence($entity, 0);
    orderSentence($entity, 1);

    $entity->forceFill(['sentences_updated_at' => now()->subHour()])->save();
    $before = $entity->refresh()->sentences_updated_at;

    // A move always rewrites the document order.
    app(SentenceOrderService::class)->place($entity->id, SentenceAnchor::beginning(), EntitySentence::query()->where('entity_id', $entity->id)->orderByDesc('order')->first()->id);
    expect($entity->refresh()->sentences_updated_at->greaterThan($before))->toBeTrue();

    // Appending at the end of a sparse document changes no existing order.
    $entity->forceFill(['sentences_updated_at' => now()->subHour()])->save();
    $before = $entity->refresh()->sentences_updated_at;

    app(SentenceOrderService::class)->place($entity->id, SentenceAnchor::end());
    expect($entity->refresh()->sentences_updated_at->equalTo($before))->toBeTrue();
});

it('rejects an anchor sentence belonging to another entity', function () {
    $entity = orderEntity();
    $foreign = orderSentence(orderEntity('Other entity'), 0);

    app(SentenceOrderService::class)->place($entity->id, SentenceAnchor::after($foreign->id));
})->throws(ModelNotFoundException::class);
