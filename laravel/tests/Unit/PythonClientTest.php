<?php

use App\Classes\PythonClient;
use App\Exceptions\PythonClientException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

function makePythonClient(): PythonClient
{
    return new PythonClient('http://ext_python:8000', 30, 600);
}

it('unwraps the split response and casts the remainder to a string', function () {
    Http::fake([
        '*/split' => Http::response([
            'sentences' => [
                ['content' => 'First.', 'type' => 'sentence'],
            ],
            'remainder' => 'trailing',
        ]),
    ]);

    expect(makePythonClient()->split('First. trailing', 'en', false))->toEqual([
        'sentences' => [
            ['content' => 'First.', 'type' => 'sentence'],
        ],
        'remainder' => 'trailing',
    ]);
});

it('defaults missing split keys', function () {
    Http::fake([
        '*/split' => Http::response([]),
    ]);

    expect(makePythonClient()->split('text', 'en', true))->toEqual([
        'sentences' => [],
        'remainder' => '',
    ]);
});

it('unwraps align matches with numeric casts and skips malformed rows', function () {
    Http::fake([
        '*/align' => Http::response([
            'matches' => [
                ['a_start' => '0', 'a_end' => '1', 'b_start' => 0, 'b_end' => 1, 'score' => '0.92'],
                'not-a-row',
                ['a_end' => 2],
            ],
        ]),
    ]);

    expect(makePythonClient()->align(['a_sentences' => ['a'], 'b_sentences' => ['b']]))->toEqual([
        ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.92],
        ['a_start' => 0, 'a_end' => 2, 'b_start' => 0, 'b_end' => 0, 'score' => 0.0],
    ]);
});

it('unwraps enrich results and normalizes the output map', function () {
    Http::fake([
        '*/enrich' => Http::response([
            'results' => [
                ['id' => '5', 'output' => ['ru_stress' => 'ok']],
                ['id' => 6],
                'not-a-row',
            ],
        ]),
    ]);

    expect(makePythonClient()->enrich(['sentences' => []]))->toEqual([
        ['id' => 5, 'output' => ['ru_stress' => 'ok']],
        ['id' => 6, 'output' => []],
    ]);
});

it('returns the embed vector', function () {
    Http::fake([
        '*/embed' => Http::response(['vector' => [0.1, 0.2, 0.3]]),
    ]);

    expect(makePythonClient()->embed('hello', 'en'))->toEqual([0.1, 0.2, 0.3]);
});

it('returns null when the embed response carries no vector', function () {
    Http::fake([
        '*/embed' => Http::response(['unexpected' => true]),
    ]);

    expect(makePythonClient()->embed('hello', 'en'))->toBeNull();
});

it('throws an endpoint-labelled exception on a non-2xx response', function (string $method, array $args, string $label) {
    Http::fake(fn () => Http::response('service unavailable', 503));

    expect(fn () => makePythonClient()->$method(...$args))
        ->toThrow(PythonClientException::class, "Python {$label} service error: 503 - service unavailable");
})
    ->with([
        'split' => ['split', ['text', 'en', true], 'split'],
        'align' => ['align', [['a_sentences' => ['a'], 'b_sentences' => ['b']]], 'alignment'],
        'enrich' => ['enrich', [['sentences' => []]], 'enrichment'],
        'embed' => ['embed', ['hello', 'en'], 'embed'],
    ]);

it('retries transient connection failures before succeeding', function () {
    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        if ($attempts < 3) {
            throw new ConnectionException('Python service timed out');
        }

        return Http::response(['vector' => [0.1]]);
    });

    expect(makePythonClient()->embed('hello', 'en'))->toEqual([0.1])
        ->and($attempts)->toBe(3);
});

it('resolves its url and timeouts from config when created through the factory', function () {
    config([
        'services.python.url' => 'http://python.example:9999',
        'services.python.timeout' => 30,
        'services.python.align_timeout' => 600,
    ]);

    Http::fake([
        '*/align' => Http::response(['matches' => []]),
    ]);

    PythonClient::create()->align(['a_sentences' => ['a'], 'b_sentences' => ['b']]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://python.example:9999/align');
});
