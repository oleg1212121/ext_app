<?php

use App\Jobs\AlignEntitySentences;
use Tests\TestCase;

uses(TestCase::class);

it('ensures the database queue retry_after exceeds the longest job timeout', function () {
    // The longest-running job is AlignEntitySentences ($timeout = 600s). The
    // database queue's retry_after must exceed it, otherwise a second worker
    // can pick up the still-running job and execute the alignment twice.
    $longestJobTimeout = (new ReflectionClass(AlignEntitySentences::class))
        ->getDefaultProperties()['timeout'];

    expect((int) config('queue.connections.database.retry_after'))
        ->toBeGreaterThan($longestJobTimeout, "retry_after must exceed the {$longestJobTimeout}s AlignEntitySentences job timeout");
});
