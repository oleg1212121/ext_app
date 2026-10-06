<?php

use App\Classes\SparseOrderService;
use App\Models\EntitySentence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function twoPhaseSentence($entity, int $order, string $content = 'Sentence'): EntitySentence
{
    return EntitySentence::create([
        'entity_id' => $entity->id,
        'content' => $content,
        'order' => $order,
    ]);
}

/**
 * The orders of the entity's sentences keyed by content — finals must land
 * on the right rows regardless of the parks written mid-flight.
 *
 * @return array<string, int>
 */
function twoPhaseOrders($entity): array
{
    return EntitySentence::query()
        ->where('entity_id', $entity->id)
        ->orderBy('content')
        ->pluck('order', 'content')
        ->map(fn (mixed $order): int => (int) $order)
        ->all();
}

it('swaps rows past each other without tripping the unique order index', function () {
    $entity = createEntity('en');
    $first = twoPhaseSentence($entity, 0, 'first');
    twoPhaseSentence($entity, 1024, 'middle');
    $last = twoPhaseSentence($entity, 2048, 'last');

    // One-phase this swap would collide: first's final (2048) is last's
    // current order and vice versa.
    app(SparseOrderService::class)->persistOrdersTwoPhase(EntitySentence::class, [
        ['id' => $first->id, 'order' => 2048],
        ['id' => $last->id, 'order' => 0],
    ]);

    expect(twoPhaseOrders($entity))->toBe([
        'first' => 2048,
        'last' => 0,
        'middle' => 1024,
    ]);
});

it('nests inside a caller transaction and rolls back with it', function () {
    $entity = createEntity('en');
    $first = twoPhaseSentence($entity, 0, 'first');
    $last = twoPhaseSentence($entity, 2048, 'last');

    DB::transaction(function () use ($entity, $first, $last): void {
        app(SparseOrderService::class)->persistOrdersTwoPhase(EntitySentence::class, [
            ['id' => $first->id, 'order' => 2048],
            ['id' => $last->id, 'order' => 0],
        ]);

        expect(twoPhaseOrders($entity))->toBe([
            'first' => 2048,
            'last' => 0,
        ], 'finals visible inside the surrounding transaction');

        DB::rollback();
    });

    expect(twoPhaseOrders($entity))->toBe([
        'first' => 0,
        'last' => 2048,
    ], 'a caller rollback takes the primitive\'s writes with it');
});

it('treats empty updates as a no-op', function () {
    $entity = createEntity('en');
    twoPhaseSentence($entity, 0, 'first');
    twoPhaseSentence($entity, 1024, 'last');

    app(SparseOrderService::class)->persistOrdersTwoPhase(EntitySentence::class, []);

    expect(twoPhaseOrders($entity))->toBe([
        'first' => 0,
        'last' => 1024,
    ]);
});
