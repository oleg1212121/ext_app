<?php

use App\Jobs\ProcessEntityFile;
use App\Jobs\QueueLane;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Queue as QueueAttribute;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class);

it('declares a queue lane from QueueLane on every job class', function () {
    $jobFiles = glob(app_path('Jobs/*.php'));

    expect($jobFiles)->not->toBeEmpty();

    foreach ($jobFiles as $file) {
        $class = 'App\\Jobs\\'.basename($file, '.php');

        // Support classes (e.g. QueueLane) are not jobs and carry no lane.
        if (! is_subclass_of($class, ShouldQueue::class)) {
            continue;
        }

        $attributes = (new ReflectionClass($class))->getAttributes(QueueAttribute::class);

        expect(count($attributes))
            ->toBe(1, "{$class} must declare its lane via #[Queue(QueueLane::DEFAULT)] or #[Queue(QueueLane::LOW)]");

        $lane = $attributes[0]->newInstance()->queue;

        expect(in_array($lane, [QueueLane::DEFAULT, QueueLane::LOW], true))
            ->toBeTrue("{$class} lane must be QueueLane::DEFAULT or QueueLane::LOW, got {$lane}");
    }
});

it('pushes a job onto the lane declared on its class', function () {
    Queue::fake();

    ProcessEntityFile::dispatch(999999, '/tmp/unused.txt');

    Queue::assertPushedOn(QueueLane::DEFAULT, ProcessEntityFile::class);
});

it('lets a dispatch site override the class lane with onQueue', function () {
    Queue::fake();

    dispatch((new ProcessEntityFile(999999, '/tmp/unused.txt'))->onQueue(QueueLane::LOW));

    Queue::assertPushedOn(QueueLane::LOW, ProcessEntityFile::class);
});
