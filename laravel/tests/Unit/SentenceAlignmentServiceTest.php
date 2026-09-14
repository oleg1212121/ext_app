<?php

use App\Classes\SentenceAlignmentService;
use App\Models\EntitySentence;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

function makeAAlignmentSentence(int $id, int $order): EntitySentence
{
    $sentence = new EntitySentence([
        'content' => "English sentence {$order}.",
        'order' => $order,
    ]);
    $sentence->id = $id;

    return $sentence;
}

function makeBAlignmentSentence(int $id, int $order): EntitySentence
{
    $sentence = new EntitySentence([
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
            $group->pluck('a_sentence_id')->unique()->count(),
            $group->pluck('b_sentence_id')->unique()->count(),
        ])
        ->values()
        ->all();
}

it('aligns a direct one sentence translation as one group', function () {
    Http::fake([
        '*' => Http::response([
            'matches' => [
                ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.92],
            ],
        ]),
    ]);

    $service = new SentenceAlignmentService('http://ext_python:8000', 30, 300);
    $aSentences = collect([makeAAlignmentSentence(101, 1)]);
    $bSentences = collect([makeBAlignmentSentence(201, 1)]);

    $result = $service->alignChunkRemote($aSentences, $bSentences, 6);

    expect(alignmentGroupShapes($result['links']))->toEqual([[1, 1]])
        ->and($result['links'][0]['similarity'])->toBe(0.92)
        ->and($result['dpPath'])->toEqual([
            ['type' => 'match', 'alignment_order' => 0],
        ]);
});

it('aligns one a side sentence to two b side sentences as one group', function () {
    Http::fake([
        '*' => Http::response([
            'matches' => [
                ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 2, 'score' => 0.94],
            ],
        ]),
    ]);

    $service = new SentenceAlignmentService('http://ext_python:8000', 30, 300);
    $aSentences = collect([makeAAlignmentSentence(101, 1)]);
    $bSentences = collect([
        makeBAlignmentSentence(201, 1),
        makeBAlignmentSentence(202, 2),
    ]);

    $result = $service->alignChunkRemote($aSentences, $bSentences, 6);

    expect(alignmentGroupShapes($result['links']))->toEqual([[1, 2]])
        ->and($result['links'])->toHaveCount(2);
});

it('aligns two a side sentences to one b side sentence as one group', function () {
    Http::fake([
        '*' => Http::response([
            'matches' => [
                ['a_start' => 0, 'a_end' => 2, 'b_start' => 0, 'b_end' => 1, 'score' => 0.94],
            ],
        ]),
    ]);

    $service = new SentenceAlignmentService('http://ext_python:8000', 30, 300);
    $aSentences = collect([
        makeAAlignmentSentence(101, 1),
        makeAAlignmentSentence(102, 2),
    ]);
    $bSentences = collect([makeBAlignmentSentence(201, 1)]);

    $result = $service->alignChunkRemote($aSentences, $bSentences, 6);

    expect(alignmentGroupShapes($result['links']))->toEqual([[2, 1]])
        ->and($result['links'])->toHaveCount(2);
});

it('produces skip steps for sentences before the matched span', function () {
    Http::fake([
        '*' => Http::response([
            'matches' => [
                ['a_start' => 1, 'a_end' => 2, 'b_start' => 1, 'b_end' => 2, 'score' => 0.8],
            ],
        ]),
    ]);

    $service = new SentenceAlignmentService('http://ext_python:8000', 30, 300);
    $aSentences = collect([
        makeAAlignmentSentence(101, 1),
        makeAAlignmentSentence(102, 2),
        makeAAlignmentSentence(103, 3),
    ]);
    $bSentences = collect([
        makeBAlignmentSentence(201, 1),
        makeBAlignmentSentence(202, 2),
        makeBAlignmentSentence(203, 3),
    ]);

    $result = $service->alignChunkRemote($aSentences, $bSentences, 6);

    expect($result['dpPath'])->toEqual([
        ['type' => 'skip_a', 'a_sentence_id' => 101, 'alignment_order' => 0],
        ['type' => 'skip_b', 'b_sentence_id' => 201, 'alignment_order' => 1],
        ['type' => 'match', 'alignment_order' => 2],
        ['type' => 'skip_a', 'a_sentence_id' => 103, 'alignment_order' => 3],
        ['type' => 'skip_b', 'b_sentence_id' => 203, 'alignment_order' => 4],
    ])
        ->and($result['links'])->toHaveCount(1)
        ->and($result['links'][0]['a_sentence_id'])->toBe(102)
        ->and($result['links'][0]['b_sentence_id'])->toBe(202)
        ->and($result['links'][0]['alignment_order'])->toBe(2);
});

it('returns a skip-only path without calling the service when a side is empty', function () {
    Http::fake();

    $service = new SentenceAlignmentService('http://ext_python:8000', 30, 300);

    $noA = $service->alignChunkRemote(collect(), collect([
        makeBAlignmentSentence(201, 1),
        makeBAlignmentSentence(202, 2),
    ]), 6);

    expect($noA['links'])->toEqual([])
        ->and($noA['dpPath'])->toEqual([
            ['type' => 'skip_b', 'b_sentence_id' => 201, 'alignment_order' => 0],
            ['type' => 'skip_b', 'b_sentence_id' => 202, 'alignment_order' => 1],
        ]);

    $noB = $service->alignChunkRemote(collect([makeAAlignmentSentence(101, 1)]), collect(), 6);

    expect($noB['links'])->toEqual([])
        ->and($noB['dpPath'])->toEqual([
            ['type' => 'skip_a', 'a_sentence_id' => 101, 'alignment_order' => 0],
        ]);

    Http::assertNothingSent();
});

it('sends sentence contents and max window to the alignment endpoint', function () {
    Http::fake([
        '*' => Http::response(['matches' => []]),
    ]);

    $service = new SentenceAlignmentService('http://ext_python:8000', 30, 300);
    $aSentences = collect([
        makeAAlignmentSentence(101, 1),
        makeAAlignmentSentence(102, 2),
    ]);
    $bSentences = collect([makeBAlignmentSentence(201, 1)]);

    $service->alignChunkRemote($aSentences, $bSentences, 5);

    Http::assertSent(function (Request $request): bool {
        return str_ends_with($request->url(), '/align')
            && $request->data()['a_sentences'] === ['English sentence 1.', 'English sentence 2.']
            && $request->data()['b_sentences'] === ['Russian sentence 1.']
            && $request->data()['max_window'] === 5;
    });
});

it('throws when the alignment service responds with an error', function () {
    Http::fake(fn () => Http::response('service unavailable', 503));

    $service = new SentenceAlignmentService('http://ext_python:8000', 30, 300);

    $service->alignChunkRemote(
        collect([makeAAlignmentSentence(101, 1)]),
        collect([makeBAlignmentSentence(201, 1)]),
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
                ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.9],
            ],
        ]);
    });

    $service = new SentenceAlignmentService('http://ext_python:8000', 30, 300);

    $result = $service->alignChunkRemote(
        collect([makeAAlignmentSentence(101, 1)]),
        collect([makeBAlignmentSentence(201, 1)]),
        6,
    );

    expect($result['links'])->toHaveCount(1)
        ->and($attempts)->toBe(3);
});
