<?php

namespace App\Classes\WordTranslations;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * A dictionary-translation API behind the word popup's auto-fetch.
 * Providers are interchangeable: the resolver walks them in a fixed order
 * and stops at the first one that returns candidates.
 */
abstract class WordTranslationProvider
{
    private const RETRY_DELAYS_MS = [500, 1_500, 3_000];

    /** Candidates kept per word — the popup caps its translation list anyway. */
    protected const MAX_CANDIDATES = 10;

    abstract public function name(): string;

    /**
     * True when the provider's credentials are present. Unconfigured
     * providers are dropped from the resolver's chain.
     */
    abstract public function isConfigured(): bool;

    /**
     * Dictionary translations of a word. An empty list means the API knows
     * no translation (the exclusion case); an exception means the call
     * itself failed and may be retried.
     *
     * @return list<array{text: string, pos: string|null}>
     */
    abstract public function fetch(string $word, string $fromCode, string $toCode): array;

    /**
     * POST with the house HTTP pattern: connection-error retries only, no
     * retry on HTTP failures, and a status+body exception on a bad response.
     */
    protected function post(string $url, array $data, array $headers, int $timeout): Response
    {
        $response = Http::timeout($timeout)
            ->retry(
                self::RETRY_DELAYS_MS,
                0,
                fn (Throwable $exception, PendingRequest $request): bool => $exception instanceof ConnectionException,
                false,
            )
            ->withHeaders($headers)
            ->post($url, $data);

        if (! $response->successful()) {
            throw new RuntimeException("{$this->name()} error: {$response->status()} - {$response->body()}");
        }

        return $response;
    }

    /**
     * Normalize a raw candidate: trim, and for Cyrillic targets strip the
     * combining stress marks dictionary APIs return (the same convention as
     * LinkTranslationsCommand::normalizeTargetWord). Null drops the
     * candidate.
     */
    protected function normalizeCandidate(string $text, string $toCode): ?string
    {
        if ($toCode === 'ru') {
            $text = (string) preg_replace('/\p{M}/u', '', $text);
        }

        $text = trim($text);

        return $text === '' ? null : $text;
    }
}
