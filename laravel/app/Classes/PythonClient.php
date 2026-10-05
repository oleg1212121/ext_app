<?php

namespace App\Classes;

use App\Exceptions\PythonClientException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The one seam between Laravel and the python service (ADR 0061): base URL,
 * per-endpoint timeouts, the connection-error-only retry policy and the
 * error envelope live here and nowhere else. Each typed method owns its
 * endpoint's response unwrapping; request-payload assembly stays with the
 * domain services — it is alignment/enrichment semantics, not transport.
 */
class PythonClient
{
    private const RETRY_DELAYS_MS = [500, 1_500, 3_000];

    public function __construct(
        private readonly string $apiUrl,
        private readonly int $timeout,
        private readonly int $alignTimeout,
    ) {}

    public static function create(): self
    {
        return new self(
            apiUrl: config('services.python.url', 'http://ext_python:8000'),
            timeout: (int) config('services.python.timeout', 30),
            alignTimeout: (int) config('services.python.align_timeout', 600),
        );
    }

    /**
     * @return array{sentences: list<array{content: string, type: string}>, remainder: string}
     */
    public function split(string $text, string $language, bool $finalize): array
    {
        $response = $this->post('/split', 'split', [
            'text' => $text,
            'language' => $language,
            'finalize' => $finalize,
        ]);

        return [
            'sentences' => $response->json('sentences', []),
            'remainder' => (string) $response->json('remainder', ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{a_start: int, a_end: int, b_start: int, b_end: int, score: float}>
     */
    public function align(array $payload): array
    {
        $response = $this->post('/align', 'alignment', $payload, $this->alignTimeout);

        $matches = [];

        foreach ($response->json('matches', []) as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $matches[] = [
                'a_start' => (int) ($raw['a_start'] ?? 0),
                'a_end' => (int) ($raw['a_end'] ?? 0),
                'b_start' => (int) ($raw['b_start'] ?? 0),
                'b_end' => (int) ($raw['b_end'] ?? 0),
                'score' => (float) ($raw['score'] ?? 0.0),
            ];
        }

        return $matches;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{results: list<array{id: int, output: array<string, mixed>}>, versions: array<string, int>}
     *                                                                                                          results plus each dispatched enricher's python-reported
     *                                                                                                          algorithm version (ADR 0067; missing entries are simply
     *                                                                                                          absent — the caller decides what a silent service means)
     */
    public function enrich(array $payload): array
    {
        $response = $this->post('/enrich', 'enrichment', $payload);

        $results = [];

        foreach ($response->json('results', []) as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $results[] = [
                'id' => (int) ($raw['id'] ?? 0),
                'output' => is_array($raw['output'] ?? null) ? $raw['output'] : [],
            ];
        }

        $versions = [];

        foreach ($response->json('versions', []) as $key => $version) {
            if (is_string($key) && is_numeric($version)) {
                $versions[$key] = (int) $version;
            }
        }

        return ['results' => $results, 'versions' => $versions];
    }

    /**
     * @return list<float>|null null when the response carries no vector; a
     *                          non-2xx response throws PythonClientException.
     */
    public function embed(string $text, string $language): ?array
    {
        $response = $this->post('/embed', 'embed', [
            'text' => $text,
            'language' => $language,
        ]);

        return $response->json('vector');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(string $path, string $endpointLabel, array $payload, ?int $timeout = null): Response
    {
        $response = Http::timeout($timeout ?? $this->timeout)
            ->retry(
                self::RETRY_DELAYS_MS,
                0,
                fn (Throwable $exception, PendingRequest $request): bool => $exception instanceof ConnectionException,
                false,
            )
            ->post("{$this->apiUrl}{$path}", $payload);

        if (! $response->successful()) {
            throw new PythonClientException($endpointLabel, $response->status(), $response->body());
        }

        return $response;
    }
}
