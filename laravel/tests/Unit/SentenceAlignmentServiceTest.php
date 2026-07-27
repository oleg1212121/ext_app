<?php

use App\Classes\SentenceAlignmentService;
use App\Models\EnEntitySentence;
use App\Models\RuEntitySentence;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

function makeEnAlignmentSentence(int $id, int $order): EnEntitySentence
{
    $sentence = new EnEntitySentence([
        'content' => "English sentence {$order}.",
        'order' => $order,
    ]);
    $sentence->id = $id;

    return $sentence;
}

function makeRuAlignmentSentence(int $id, int $order): RuEntitySentence
{
    $sentence = new RuEntitySentence([
        'content' => "Russian sentence {$order}.",
        'order' => $order,
    ]);
    $sentence->id = $id;

    return $sentence;
}

function alignmentGroupShapes(array $links): array
{
    return collect($links)
        ->groupBy('link_group')
        ->map(fn ($group): array => [
            $group->pluck('en_entity_sentence_id')->unique()->count(),
            $group->pluck('ru_entity_sentence_id')->unique()->count(),
        ])
        ->values()
        ->all();
}

it('aligns a direct one sentence translation as one group', function () {
    Http::fake([
        '*' => Http::response([
            'matches' => [
                ['en_start' => 0, 'en_end' => 1, 'ru_start' => 0, 'ru_end' => 1, 'score' => 0.92],
            ],
            'unmatched_en' => [],
            'unmatched_ru' => [],
        ]),
    ]);

    $service = new SentenceAlignmentService('http://ext_python:8000', 30, 300);
    $enSentences = collect([makeEnAlignmentSentence(101, 1)]);
    $ruSentences = collect([makeRuAlignmentSentence(201, 1)]);

    $result = $service->alignChunkRemote($enSentences, $ruSentences, 6);

    expect(alignmentGroupShapes($result['links']))->toEqual([[1, 1]])
        ->and($result['links'][0]['similarity'])->toBe(0.92)
        ->and($result['dpPath'])->toEqual([
            ['type' => 'match', 'alignment_order' => 0],
        ]);
});

it('aligns one english sentence to two russian sentences as one group', function () {
    Http::fake([
        '*' => Http::response([
            'matches' => [
                ['en_start' => 0, 'en_end' => 1, 'ru_start' => 0, 'ru_end' => 2, 'score' => 0.94],
            ],
            'unmatched_en' => [],
            'unmatched_ru' => [],
        ]),
    ]);

    $service = new SentenceAlignmentService('http://ext_python:8000', 30, 300);
    $enSentences = collect([makeEnAlignmentSentence(101, 1)]);
    $ruSentences = collect([
        makeRuAlignmentSentence(201, 1),
        makeRuAlignmentSentence(202, 2),
    ]);

    $result = $service->alignChunkRemote($enSentences, $ruSentences, 6);

    expect(alignmentGroupShapes($result['links']))->toEqual([[1, 2]])
        ->and($result['links'])->toHaveCount(2);
});

it('aligns two english sentences to one russian sentence as one group', function () {
    Http::fake([
        '*' => Http::response([
            'matches' => [
                ['en_start' => 0, 'en_end' => 2, 'ru_start' => 0, 'ru_end' => 1, 'score' => 0.94],
            ],
            'unmatched_en' => [],
            'unmatched_ru' => [],
        ]),
    ]);

    $service = new SentenceAlignmentService('http://ext_python:8000', 30, 300);
    $enSentences = collect([
        makeEnAlignmentSentence(101, 1),
        makeEnAlignmentSentence(102, 2),
    ]);
    $ruSentences = collect([makeRuAlignmentSentence(201, 1)]);

    $result = $service->alignChunkRemote($enSentences, $ruSentences, 6);

    expect(alignmentGroupShapes($result['links']))->toEqual([[2, 1]])
        ->and($result['links'])->toHaveCount(2);
});

it('produces skip steps for sentences outside matched spans', function () {
    Http::fake([
        '*' => Http::response([
            'matches' => [
                ['en_start' => 1, 'en_end' => 2, 'ru_start' => 1, 'ru_end' => 2, 'score' => 0.8],
            ],
            'unmatched_en' => [0, 2],
            'unmatched_ru' => [0, 2],
        ]),
    ]);

    $service = new SentenceAlignmentService('http://ext_python:8000', 30, 300);
    $enSentences = collect([
        makeEnAlignmentSentence(101, 1),
        makeEnAlignmentSentence(102, 2),
        makeEnAlignmentSentence(103, 3),
    ]);
    $ruSentences = collect([
        makeRuAlignmentSentence(201, 1),
        makeRuAlignmentSentence(202, 2),
        makeRuAlignmentSentence(203, 3),
    ]);

    $result = $service->alignChunkRemote($enSentences, $ruSentences, 6);

    expect($result['dpPath'])->toEqual([
        ['type' => 'skip_en', 'en_sentence_id' => 101, 'alignment_order' => 0],
        ['type' => 'skip_ru', 'ru_sentence_id' => 201, 'alignment_order' => 1],
        ['type' => 'match', 'alignment_order' => 2],
        ['type' => 'skip_en', 'en_sentence_id' => 103, 'alignment_order' => 3],
        ['type' => 'skip_ru', 'ru_sentence_id' => 203, 'alignment_order' => 4],
    ])
        ->and($result['links'])->toHaveCount(1)
        ->and($result['links'][0]['en_entity_sentence_id'])->toBe(102)
        ->and($result['links'][0]['ru_entity_sentence_id'])->toBe(202)
        ->and($result['links'][0]['alignment_order'])->toBe(2);
});

it('returns a skip-only path without calling the service when a side is empty', function () {
    Http::fake();

    $service = new SentenceAlignmentService('http://ext_python:8000', 30, 300);

    $noEn = $service->alignChunkRemote(collect(), collect([
        makeRuAlignmentSentence(201, 1),
        makeRuAlignmentSentence(202, 2),
    ]), 6);

    expect($noEn['links'])->toEqual([])
        ->and($noEn['dpPath'])->toEqual([
            ['type' => 'skip_ru', 'ru_sentence_id' => 201, 'alignment_order' => 0],
            ['type' => 'skip_ru', 'ru_sentence_id' => 202, 'alignment_order' => 1],
        ]);

    $noRu = $service->alignChunkRemote(collect([makeEnAlignmentSentence(101, 1)]), collect(), 6);

    expect($noRu['links'])->toEqual([])
        ->and($noRu['dpPath'])->toEqual([
            ['type' => 'skip_en', 'en_sentence_id' => 101, 'alignment_order' => 0],
        ]);

    Http::assertNothingSent();
});

it('sends sentence contents and max window to the alignment endpoint', function () {
    Http::fake([
        '*' => Http::response(['matches' => [], 'unmatched_en' => [], 'unmatched_ru' => []]),
    ]);

    $service = new SentenceAlignmentService('http://ext_python:8000', 30, 300);
    $enSentences = collect([
        makeEnAlignmentSentence(101, 1),
        makeEnAlignmentSentence(102, 2),
    ]);
    $ruSentences = collect([makeRuAlignmentSentence(201, 1)]);

    $service->alignChunkRemote($enSentences, $ruSentences, 5);

    Http::assertSent(function (Request $request): bool {
        return str_ends_with($request->url(), '/align')
            && $request->data()['en_sentences'] === ['English sentence 1.', 'English sentence 2.']
            && $request->data()['ru_sentences'] === ['Russian sentence 1.']
            && $request->data()['max_window'] === 5;
    });
});

it('throws when the alignment service responds with an error', function () {
    Http::fake(fn () => Http::response('service unavailable', 503));

    $service = new SentenceAlignmentService('http://ext_python:8000', 30, 300);

    $service->alignChunkRemote(
        collect([makeEnAlignmentSentence(101, 1)]),
        collect([makeRuAlignmentSentence(201, 1)]),
        6,
    );
})->throws(RuntimeException::class, 'Python alignment service error');

it('retries transient alignment connection failures before succeeding', function () {
    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        if ($attempts < 3) {
            throw new ConnectionException('Python service timed out');
        }

        return Http::response([
            'matches' => [
                ['en_start' => 0, 'en_end' => 1, 'ru_start' => 0, 'ru_end' => 1, 'score' => 0.9],
            ],
            'unmatched_en' => [],
            'unmatched_ru' => [],
        ]);
    });

    $service = new SentenceAlignmentService('http://ext_python:8000', 30, 300);

    $result = $service->alignChunkRemote(
        collect([makeEnAlignmentSentence(101, 1)]),
        collect([makeRuAlignmentSentence(201, 1)]),
        6,
    );

    expect($result['links'])->toHaveCount(1)
        ->and($attempts)->toBe(3);
});
