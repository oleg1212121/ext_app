<?php

use App\Classes\WordTestService;
use Tests\TestCase;

uses(TestCase::class);

/**
 * A sample payload with CEFR bands of the given sizes, in anchor order
 * starting at a1; ids run 1..N across the bands in order, so band k's ids
 * are known without the sampler. $base is the floor the untested lower
 * bands contribute.
 */
function scoringPayload(array $bandSizes, int $base = 0): array
{
    $slugs = ['a1', 'a2', 'b1', 'b2', 'c1', 'c2'];
    $nextId = 1;
    $bands = [];

    foreach ($bandSizes as $index => $size) {
        $bands[$slugs[$index]] = $size === 0 ? [] : range($nextId, $nextId + $size - 1);
        $nextId += $size;
    }

    return ['base' => $base, 'bands' => $bands];
}

it('awards every band width for a fully-known fresh test', function () {
    $service = new WordTestService;
    $payload = scoringPayload([10, 10, 10, 10, 10, 10]);

    // Band widths 1000/2000/3000/4000/5000/5000: a fully-known fresh test
    // tops out exactly on the 20 000 scale.
    $everyId = range(1, 60);

    expect($service->score($payload, $everyId))->toBe(20000);
});

it('sums the band widths of the known shares', function () {
    $service = new WordTestService;
    $payload = scoringPayload([10, 10, 10, 10, 10, 10]);

    // Full a1 + full a2 + two of b1's ten drawn: 1000 + 2000 + 600.
    $known = [...range(1, 10), ...range(11, 20), ...range(21, 22)];

    expect($service->score($payload, $known))->toBe(3600);
});

it('scores the base when nothing is known', function () {
    $service = new WordTestService;
    $fresh = scoringPayload([10, 10, 10, 10, 10, 10]);
    $retake = scoringPayload([20, 20, 20], 6000);

    expect($service->score($fresh, []))->toBe(0)
        ->and($service->score($retake, []))->toBe(6000);
});

it('rounds the per-band share to whole credit points', function () {
    $service = new WordTestService;
    $payload = scoringPayload([10, 10]);

    expect($service->score($payload, [1, 2, 3]))->toBe(300)   // round(1000 x 3/10)
        ->and($service->score($payload, [11, 12, 13]))->toBe(600) // round(2000 x 3/10)
        ->and($service->score($payload, [1, 2, 11, 12, 13, 14]))->toBe(1000); // round(1000x2/10) + round(2000x4/10)
});

it('skips empty bands and ignores unknown ids', function () {
    $service = new WordTestService;
    $payload = scoringPayload([0, 10]);

    // a1 drew nothing and contributes nothing; only a2's first word counts —
    // 999 and 12345 were never sampled.
    expect($service->score($payload, [1, 999, 12345]))->toBe(200);
});

it('clamps the score to the 20000 scale top', function () {
    $service = new WordTestService;

    // An over-full payload cannot leave the scale.
    $payload = ['base' => 20000, 'bands' => ['c2' => range(1, 4)]];

    expect($service->score($payload, range(1, 4)))->toBe(20000);
});

it('treats numeric-string ids as integers', function () {
    $service = new WordTestService;
    $payload = scoringPayload([10]);

    expect($service->score($payload, ['3']))->toBe(100); // round(1000 x 1/10)
});
