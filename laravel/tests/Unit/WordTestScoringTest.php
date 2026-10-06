<?php

use App\Classes\WordTestService;
use Tests\TestCase;

uses(TestCase::class);

/**
 * A sample payload with buckets of the given sizes; ids run 1..N across
 * buckets in order, so bucket k's ids are known without the sampler.
 */
function scoringPayload(array $bucketSizes): array
{
    $nextId = 1;
    $buckets = [];

    foreach ($bucketSizes as $size) {
        $buckets[] = range($nextId, $nextId + $size - 1);
        $nextId += $size;
    }

    return ['buckets' => $buckets];
}

it('awards full credit for every fully-known bucket', function () {
    $service = new WordTestService;
    $payload = scoringPayload([3, 2, 3, 2]);

    $allIds = array_merge(...$payload['buckets']);

    // A full sample is 20 buckets: every bucket known tops out at 20 000.
    $fullSample = scoringPayload(array_fill(0, 20, 3));
    $everyId = range(1, 60);

    expect($service->score($payload, $allIds))->toBe(4000)
        ->and($service->score($payload, [1, 2, 3]))->toBe(1000)
        ->and($service->score($payload, [4, 5]))->toBe(1000)
        ->and($service->score($fullSample, $everyId))->toBe(20000);
});

it('scores zero when nothing is known', function () {
    $service = new WordTestService;

    expect($service->score(scoringPayload([3, 2]), []))->toBe(0);
});

it('rounds the per-bucket share to whole credit points', function () {
    $service = new WordTestService;
    $payload = scoringPayload([3, 2]);

    expect($service->score($payload, [1]))->toBe(333)      // round(1000 / 3)
        ->and($service->score($payload, [4]))->toBe(500)   // round(1000 / 2)
        ->and($service->score($payload, [1, 2]))->toBe(667); // round(2000 / 3)
});

it('ignores ids that were not sampled', function () {
    $service = new WordTestService;
    $payload = scoringPayload([3, 2]);

    expect($service->score($payload, [1, 999, 12345]))->toBe(333);
});

it('skips empty buckets', function () {
    $service = new WordTestService;
    $payload = scoringPayload([0, 2]);

    expect($service->score($payload, [2]))->toBe(500);
});

it('treats numeric-string ids as integers', function () {
    $service = new WordTestService;
    $payload = scoringPayload([3, 2]);

    expect($service->score($payload, ['1']))->toBe(333);
});
