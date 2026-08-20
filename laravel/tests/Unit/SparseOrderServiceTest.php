<?php

use App\Classes\SparseOrderService;

it('creates sparse initial orders', function () {
    $service = new SparseOrderService;

    expect($service->initial(0))->toBe(0)
        ->and($service->initial(1))->toBe(SparseOrderService::STRIDE)
        ->and($service->initial(2))->toBe(SparseOrderService::STRIDE * 2);
});

it('uses midpoint orders when a gap exists', function () {
    $service = new SparseOrderService;

    expect($service->between(0, SparseOrderService::STRIDE))->toBe(intdiv(SparseOrderService::STRIDE, 2));
});

it('locally rebalances dense neighbors when no gap exists', function () {
    $service = new SparseOrderService;

    $placement = $service->orderForInsertAfter([
        ['key' => 'first', 'order' => 0],
        ['key' => 'second', 'order' => 1],
    ], null, 0);

    expect($placement['order'])->toBe(intdiv(SparseOrderService::STRIDE, 2))
        ->and($placement['items'])->toBe([
            ['key' => 'first', 'order' => 0],
            ['key' => 'second', 'order' => SparseOrderService::STRIDE],
        ]);
});

it('produces non-negative order when inserting before all items with small orders', function () {
    $service = new SparseOrderService;

    $items = array_map(
        fn (int $i): array => ['key' => "s-{$i}", 'order' => $i],
        range(0, 49),
    );

    $result = $service->orderForInsertAfter($items, null, 0);

    expect($result['order'])->toBeGreaterThanOrEqual(0);

    foreach ($result['items'] as $item) {
        expect($item['order'])->toBeGreaterThanOrEqual(0);
    }
});

it('produces non-negative order when inserting at beginning with BEGINNING_SENTINEL anchor', function () {
    $service = new SparseOrderService;

    $items = [
        ['key' => 's-1', 'order' => 1024],
        ['key' => 's-2', 'order' => 2048],
    ];

    $result = $service->orderForInsertAfter($items, null, SparseOrderService::BEGINNING_SENTINEL);

    expect($result['order'])->toBeGreaterThanOrEqual(0);

    foreach ($result['items'] as $item) {
        expect($item['order'])->toBeGreaterThanOrEqual(0);
    }
});
