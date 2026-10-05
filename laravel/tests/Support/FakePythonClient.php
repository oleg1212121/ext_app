<?php

namespace Tests\Support;

use App\Classes\Enrichment\EnricherRegistry;
use App\Classes\PythonClient;
use App\Exceptions\PythonClientException;

/**
 * The in-memory adapter at the python transport seam (ADR 0061): satisfies
 * PythonClient's interface without HTTP, so tests bind it into the container
 * (the fakePython() helper) and container-resolved domain services cannot
 * tell the difference. Wire-level HTTP fakes live only in PythonClientTest,
 * which tests the real adapter.
 *
 * enrich() defaults to the keyed echo the enrichment tests rely on — each
 * requested enricher's output rides results[].output under its key and the
 * declared python versions ride the versions map (ADR 0057, ADR 0067).
 * Every other endpoint throws a PythonClientException until given a canned
 * response, so an unexpected call fails loudly under the same catch
 * semantics production code applies to a failing python service.
 */
class FakePythonClient extends PythonClient
{
    /** @var list<array<string, mixed>> */
    public array $enrichPayloads = [];

    /** @var list<array<string, mixed>> */
    public array $alignPayloads = [];

    /** @var list<array{text: string, language: string}> */
    public array $embedPayloads = [];

    /** @var callable|null (array): array{results: list, versions: array<string, int>} */
    public $enrichHandler;

    /** @var callable|null (array): list<array{a_start: int, a_end: int, b_start: int, b_end: int, score: float}> */
    public $alignHandler;

    /** @var callable|null (array): list<float>|null */
    public $embedHandler;

    public function __construct()
    {
        parent::__construct('http://python.fake', 1, 1);
    }

    /**
     * Serve one canned align response in the client's unwrapped matches shape.
     *
     * @param  list<array{a_start: int, a_end: int, b_start: int, b_end: int, score: float}>  $matches
     */
    public function aligning(array $matches): self
    {
        $this->alignHandler = fn () => $matches;

        return $this;
    }

    /**
     * Echo a diagonal 1:1 match per sentence pair — the "everything aligns"
     * canned response for tests that only need the pipeline to commit rows.
     */
    public function aligningDiagonal(float $score = 0.9): self
    {
        $this->alignHandler = function (array $payload) use ($score): array {
            $count = min(count($payload['a_sentences'] ?? []), count($payload['b_sentences'] ?? []));
            $matches = [];

            for ($i = 0; $i < $count; $i++) {
                $matches[] = ['a_start' => $i, 'a_end' => $i + 1, 'b_start' => $i, 'b_end' => $i + 1, 'score' => $score];
            }

            return $matches;
        };

        return $this;
    }

    /** Serve one canned embed vector; null mimics a vector-less response. */
    public function embedding(?array $vector): self
    {
        $this->embedHandler = fn () => $vector;

        return $this;
    }

    /** Serve an empty enrich response: no results, no versions. */
    public function enrichingNothing(): self
    {
        $this->enrichHandler = fn () => ['results' => [], 'versions' => []];

        return $this;
    }

    public function split(string $text, string $language, bool $finalize): array
    {
        throw $this->unexpected('split');
    }

    public function align(array $payload): array
    {
        $this->alignPayloads[] = $payload;

        if ($this->alignHandler === null) {
            throw $this->unexpected('alignment');
        }

        return ($this->alignHandler)($payload);
    }

    public function enrich(array $payload): array
    {
        $this->enrichPayloads[] = $payload;

        if ($this->enrichHandler !== null) {
            return ($this->enrichHandler)($payload);
        }

        $registry = new EnricherRegistry;
        $enrichers = $payload['enrichers'] ?? [];
        $results = collect($payload['sentences'] ?? [])
            ->map(fn (array $sentence): array => [
                'id' => $sentence['id'],
                'output' => array_filter([
                    'ru_stress' => in_array('ru_stress', $enrichers, true) ? $sentence['text'].'́' : null,
                    'en_stress' => in_array('en_stress', $enrichers, true) ? $sentence['text'] : null,
                    'en_phrasal' => in_array('en_phrasal', $enrichers, true) ? [] : null,
                ], fn ($output): bool => $output !== null),
            ])
            ->all();

        $versions = [];

        foreach ($enrichers as $key) {
            $versions[$key] = $registry->forKey($key)?->pythonVersion() ?? 1;
        }

        return ['results' => $results, 'versions' => $versions];
    }

    public function embed(string $text, string $language): ?array
    {
        $this->embedPayloads[] = ['text' => $text, 'language' => $language];

        if ($this->embedHandler === null) {
            throw $this->unexpected('embed');
        }

        return ($this->embedHandler)(end($this->embedPayloads));
    }

    private function unexpected(string $label): PythonClientException
    {
        return new PythonClientException($label, 500, 'no canned response configured on the fake python client');
    }
}
