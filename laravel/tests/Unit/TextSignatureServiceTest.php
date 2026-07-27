<?php

use App\Classes\TextSignatureService;
use App\Jobs\GenerateEntitySignature;
use App\Jobs\ProcessEntityFile;
use App\Jobs\SplitEntityFileSentences;
use App\Models\EnEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('retries transient embedding connection failures before succeeding', function () {
    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        if ($attempts < 3) {
            throw new ConnectionException('Embedding service timed out');
        }

        return Http::response([
            'vector' => [0.1, 0.2, 0.3],
        ]);
    });

    $service = new TextSignatureService('http://ext_python:8000', 30);

    expect($service->generateSignature('A short sample text'))
        ->toEqual([0.1, 0.2, 0.3]);

    expect($attempts)->toBe(3);
});

it('configures entity embedding jobs to retry with backoff', function () {
    $processJob = new ProcessEntityFile(1, 'entities/example.txt', 'en');
    $generateJob = new GenerateEntitySignature(1, 'entities/example.txt', 'en');
    $splitJob = new SplitEntityFileSentences(1, 'entities/example.txt', 'en');

    expect($processJob->timeout)->toBe(120)
        ->and($processJob->tries)->toBe(5)
        ->and($processJob->backoff())->toEqual([30, 60, 120, 300])
        ->and($generateJob->timeout)->toBe(180)
        ->and($generateJob->tries)->toBe(5)
        ->and($generateJob->backoff())->toEqual([30, 60, 120, 300])
        ->and($splitJob->timeout)->toBe(180)
        ->and($splitJob->tries)->toBe(5)
        ->and($splitJob->backoff())->toEqual([30, 60, 120, 300]);
});

it('sends only a head and tail sample for long texts to the python service', function () {
    Http::fake([
        '*' => Http::response(['vector' => array_fill(0, 384, 0.01)], 200),
    ]);

    $service = new TextSignatureService('http://ext_python:8000', 30);

    $long = str_repeat('x', 21_000);
    expect(strlen($long))->toBeGreaterThan(20_000);

    expect($service->generateSignature($long))->toBeArray();

    Http::assertSentCount(1);

    /** @var Request $request */
    $request = Http::recorded()[0][0];
    $sent = $request->data()['text'] ?? '';

    expect(strlen($sent))->toBeLessThanOrEqual(20_000 + 16)
        ->and(str_starts_with($sent, str_repeat('x', 10_000)))
        ->and(str_ends_with($sent, str_repeat('x', 10_000)));
});

it('sends short text unchanged to the python service', function () {
    Http::fake([
        '*' => Http::response(['vector' => [0.1, 0.2, 0.3]], 200),
    ]);

    $service = new TextSignatureService('http://ext_python:8000', 30);

    expect($service->generateSignature('hello'))->toEqual([0.1, 0.2, 0.3]);

    Http::assertSent(function (Request $request): bool {
        return ($request->data()['text'] ?? '') === 'hello';
    });
});

it('detects similar entity via cosine batch endpoint', function () {
    $v = [1.0, 0.0, 0.0];
    $sig = json_encode($v);

    EnEntity::query()->create(['name' => 'first', 'file_path' => 'a', 'signature' => $sig]);
    $other = EnEntity::query()->create(['name' => 'second', 'file_path' => 'b', 'signature' => $sig]);

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/cosine/batch')) {
            return Http::response(['similarities' => [1.0]], 200);
        }

        return Http::response(['error' => 'not expected in this test'], 500);
    });

    $service = new TextSignatureService('http://ext_python:8000', 30);
    $other->refresh();

    expect($service->hasSimilar($other, 'en'))->toBeTrue();
});

it('falls back to PHP cosine when the batch endpoint fails', function () {
    $v = [0.6, 0.8, 0.0];
    $sig = json_encode($v);

    EnEntity::query()->create(['name' => 'first', 'file_path' => 'a', 'signature' => $sig]);
    $other = EnEntity::query()->create(['name' => 'second', 'file_path' => 'b', 'signature' => $sig]);

    Http::fake(fn () => Http::response('bad gateway', 502));

    $service = new TextSignatureService('http://ext_python:8000', 30);
    $other->refresh();

    expect($service->hasSimilar($other, 'en'))->toBeTrue();
});

it('dispatches sentence splitting when the file is not a duplicate', function () {
    config(['services.python.url' => 'http://ext_python:8000']);

    Bus::fake();
    $dir = 'entities/'.uniqid('e_', true).'.txt';
    Storage::disk('local')->put($dir, 'First sentence. Second here.');

    Http::fake(function () {
        return Http::response(['vector' => [0.1, 0.2, 0.3]], 200);
    });

    $entity = EnEntity::query()->create(['name' => 'Entity', 'file_path' => $dir]);

    (new ProcessEntityFile($entity->id, $dir, 'en'))->handle();

    Bus::assertDispatched(SplitEntityFileSentences::class);
});
