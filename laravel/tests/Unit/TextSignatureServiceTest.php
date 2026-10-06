<?php

use App\Classes\TextSignatureService;
use App\Jobs\GenerateEntitySignature;
use App\Jobs\ProcessEntityFile;
use App\Jobs\SplitEntityFileSentences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// The container binds the fake python client; anything that still tries the
// wire fails here instead of reaching for the real service.
beforeEach(fn () => Http::preventStrayRequests());

it('configures entity embedding jobs to retry with backoff', function () {
    $processJob = new ProcessEntityFile(1, 'entities/example.txt');
    $generateJob = new GenerateEntitySignature(1, 'entities/example.txt');
    $splitJob = new SplitEntityFileSentences(1, 'entities/example.txt');

    expect($processJob->timeout)->toBe(120)
        ->and($processJob->tries)->toBe(5)
        ->and($processJob->backoff())->toEqual([30, 60, 120, 300])
        ->and($generateJob->timeout)->toBe(180)
        ->and($generateJob->tries)->toBe(5)
        ->and($generateJob->backoff())->toEqual([30, 60, 120, 300])
        ->and($splitJob->timeout)->toBe(600) // run-budget sized: 8 chunks × 30 s python timeout (ADR 0043)
        ->and($splitJob->tries)->toBe(5)
        ->and($splitJob->backoff())->toEqual([30, 60, 120, 300]);
});

it('sends only a head and tail sample for long texts to the python service', function () {
    $fake = fakePython()->embedding(array_fill(0, 384, 0.01));

    $service = new TextSignatureService($fake);

    $long = str_repeat('x', 21_000);
    expect(strlen($long))->toBeGreaterThan(20_000);

    expect($service->generateSignature($long))->toBeArray();

    expect($fake->embedPayloads)->toHaveCount(1);

    $sent = $fake->embedPayloads[0]['text'];

    expect(strlen($sent))->toBeLessThanOrEqual(20_000 + 16)
        ->and(str_starts_with($sent, str_repeat('x', 10_000)))
        ->and(str_ends_with($sent, str_repeat('x', 10_000)));
});

it('sends short text unchanged to the python service', function () {
    $fake = fakePython()->embedding([0.1, 0.2, 0.3]);

    $service = new TextSignatureService($fake);

    expect($service->generateSignature('hello'))->toEqual([0.1, 0.2, 0.3]);

    expect($fake->embedPayloads[0]['text'] ?? '')->toBe('hello')
        ->and($fake->embedPayloads[0]['language'] ?? '')->toBe('en');
});

it('dispatches sentence splitting without calling the python service', function () {
    config(['services.python.url' => 'http://ext_python:8000']);

    Bus::fake();
    $dir = 'entities/'.uniqid('e_', true).'.txt';
    Storage::disk('local')->put($dir, 'First sentence. Second here.');

    $entity = createEntity('en', null, ['name' => 'Entity', 'file_path' => $dir]);

    // The embedding service being down must not block the pipeline start:
    // the unconfigured fake throws the same PythonClientException a 502 maps
    // to in production.
    (new ProcessEntityFile($entity->id, $dir))->handle();

    Bus::assertDispatched(SplitEntityFileSentences::class);
});

it('computes cosine similarity for the signature gate', function () {
    $service = new TextSignatureService(fakePython());

    expect($service->cosineSimilarity([1.0, 0.0], [1.0, 0.0]))->toBe(1.0)
        ->and($service->cosineSimilarity([1.0, 0.0], [0.0, 1.0]))->toBe(0.0)
        ->and($service->cosineSimilarity([0.0, 0.0], [1.0, 1.0]))->toBe(0.0)
        ->and($service->cosineSimilarity([1.0, 2.0], [2.0, 4.0]))->toEqualWithDelta(1.0, 0.000001)
        ->and($service->cosineSimilarity([], [1.0]))->toBe(0.0);
});

it('truncates defensively when signature vectors differ in length', function () {
    $service = new TextSignatureService(fakePython());

    // The shared prefix decides the score; the extra dimension is ignored
    // rather than warning on a missing index.
    expect($service->cosineSimilarity([1.0, 0.0], [1.0, 0.0, 7.0]))->toBe(1.0)
        ->and($service->cosineSimilarity([1.0, 0.0, 7.0], [1.0, 0.0]))->toBe(1.0);
});
