<?php

use App\Classes\SentenceAlignmentService;
use App\Models\EnEntitySentence;
use App\Models\RuEntitySentence;

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
    $service = new SentenceAlignmentService('http://ext_embedding:8000', 30);
    $enSentences = collect([makeEnAlignmentSentence(101, 1)]);
    $ruSentences = collect([makeRuAlignmentSentence(201, 1)]);

    $result = $service->alignChunk(
        $enSentences,
        $ruSentences,
        [0 => [0 => 0.92]],
        6,
        ['0:0:1:1' => 0.92],
    );

    expect(alignmentGroupShapes($result['links']))->toEqual([[1, 1]])
        ->and($result['dpPath'])->toEqual([
            ['type' => 'match', 'alignment_order' => 0],
        ]);
});

it('aligns one english sentence to two russian sentences when the smallest meaning spans both russian sentences', function () {
    $service = new SentenceAlignmentService('http://ext_embedding:8000', 30);
    $enSentences = collect([makeEnAlignmentSentence(101, 1)]);
    $ruSentences = collect([
        makeRuAlignmentSentence(201, 1),
        makeRuAlignmentSentence(202, 2),
    ]);

    $result = $service->alignChunk(
        $enSentences,
        $ruSentences,
        [0 => [0 => 0.40, 1 => 0.40]],
        6,
        ['0:0:1:2' => 0.94],
    );

    expect(alignmentGroupShapes($result['links']))->toEqual([[1, 2]]);
});

it('aligns two english sentences to one russian sentence when russian compresses the meaning', function () {
    $service = new SentenceAlignmentService('http://ext_embedding:8000', 30);
    $enSentences = collect([
        makeEnAlignmentSentence(101, 1),
        makeEnAlignmentSentence(102, 2),
    ]);
    $ruSentences = collect([makeRuAlignmentSentence(201, 1)]);

    $result = $service->alignChunk(
        $enSentences,
        $ruSentences,
        [
            0 => [0 => 0.40],
            1 => [0 => 0.40],
        ],
        6,
        ['0:0:2:1' => 0.94],
    );

    expect(alignmentGroupShapes($result['links']))->toEqual([[2, 1]]);
});

it('aligns two english sentences to four russian sentences as the smallest complete meaning group', function () {
    $service = new SentenceAlignmentService('http://ext_embedding:8000', 30);
    $enSentences = collect([
        makeEnAlignmentSentence(101, 1),
        makeEnAlignmentSentence(102, 2),
    ]);
    $ruSentences = collect([
        makeRuAlignmentSentence(201, 1),
        makeRuAlignmentSentence(202, 2),
        makeRuAlignmentSentence(203, 3),
        makeRuAlignmentSentence(204, 4),
    ]);

    $result = $service->alignChunk(
        $enSentences,
        $ruSentences,
        [
            0 => [0 => 0.25, 1 => 0.25, 2 => 0.25, 3 => 0.25],
            1 => [0 => 0.25, 1 => 0.25, 2 => 0.25, 3 => 0.25],
        ],
        6,
        ['0:0:2:4' => 0.94],
    );

    expect(alignmentGroupShapes($result['links']))->toEqual([[2, 4]]);
});

it('prefers one balanced six sentence meaning group over two lopsided groups', function () {
    $service = new SentenceAlignmentService('http://ext_embedding:8000', 30);
    $enSentences = collect(range(1, 6))->map(fn (int $order): EnEntitySentence => makeEnAlignmentSentence(100 + $order, $order));
    $ruSentences = collect(range(1, 6))->map(fn (int $order): RuEntitySentence => makeRuAlignmentSentence(200 + $order, $order));
    $similarityMatrix = [];

    foreach (range(0, 5) as $i) {
        foreach (range(0, 5) as $j) {
            $similarityMatrix[$i][$j] = 0.20;
        }
    }

    $result = $service->alignChunk(
        $enSentences,
        $ruSentences,
        $similarityMatrix,
        6,
        [
            '0:0:1:5' => 0.95,
            '1:5:5:1' => 0.95,
            '0:0:6:6' => 0.90,
        ],
    );

    expect(alignmentGroupShapes($result['links']))->toEqual([[6, 6]]);
});

it('skips nearby context instead of attaching it to a mediocre lopsided group', function () {
    $service = new SentenceAlignmentService('http://ext_embedding:8000', 30);
    $enSentences = collect([makeEnAlignmentSentence(101, 1)]);
    $ruSentences = collect([
        makeRuAlignmentSentence(201, 1),
        makeRuAlignmentSentence(202, 2),
        makeRuAlignmentSentence(203, 3),
        makeRuAlignmentSentence(204, 4),
    ]);

    $result = $service->alignChunk(
        $enSentences,
        $ruSentences,
        [0 => [0 => 0.20, 1 => 0.84, 2 => 0.20, 3 => 0.20]],
        6,
        [
            '0:0:1:4' => 0.84,
            '0:1:1:1' => 0.84,
        ],
    );

    expect(alignmentGroupShapes($result['links']))->toEqual([[1, 1]])
        ->and($result['links'][0]['ru_entity_sentence_id'])->toBe(202);
});

it('aligns one english sentence to five russian sentences', function () {
    $service = new SentenceAlignmentService('http://ext_embedding:8000', 30);
    $enSentences = collect([makeEnAlignmentSentence(101, 1)]);
    $ruSentences = collect([
        makeRuAlignmentSentence(201, 1),
        makeRuAlignmentSentence(202, 2),
        makeRuAlignmentSentence(203, 3),
        makeRuAlignmentSentence(204, 4),
        makeRuAlignmentSentence(205, 5),
    ]);

    $result = $service->alignChunk(
        $enSentences,
        $ruSentences,
        [0 => [0 => 0.25, 1 => 0.25, 2 => 0.25, 3 => 0.25, 4 => 0.25]],
        5,
        ['0:0:1:5' => 0.95],
    );

    expect($result['dpPath'])->toEqual([
        ['type' => 'match', 'alignment_order' => 0],
    ])
        ->and($result['links'])->toHaveCount(5)
        ->and(collect($result['links'])->pluck('en_entity_sentence_id')->unique()->values()->all())->toEqual([101])
        ->and(collect($result['links'])->pluck('ru_entity_sentence_id')->values()->all())->toEqual([201, 202, 203, 204, 205])
        ->and(collect($result['links'])->pluck('link_group')->unique()->values()->all())->toEqual([1]);
});

it('aligns several english sentences to several russian sentences as one meaning group', function () {
    $service = new SentenceAlignmentService('http://ext_embedding:8000', 30);
    $enSentences = collect([
        makeEnAlignmentSentence(101, 1),
        makeEnAlignmentSentence(102, 2),
    ]);
    $ruSentences = collect([
        makeRuAlignmentSentence(201, 1),
        makeRuAlignmentSentence(202, 2),
        makeRuAlignmentSentence(203, 3),
        makeRuAlignmentSentence(204, 4),
        makeRuAlignmentSentence(205, 5),
    ]);

    $result = $service->alignChunk(
        $enSentences,
        $ruSentences,
        [
            0 => [0 => 0.15, 1 => 0.15, 2 => 0.15, 3 => 0.15, 4 => 0.15],
            1 => [0 => 0.15, 1 => 0.15, 2 => 0.15, 3 => 0.15, 4 => 0.15],
        ],
        5,
        ['0:0:2:5' => 0.95],
    );

    expect($result['dpPath'])->toEqual([
        ['type' => 'match', 'alignment_order' => 0],
    ])
        ->and($result['links'])->toHaveCount(10)
        ->and(collect($result['links'])->pluck('en_entity_sentence_id')->unique()->values()->all())->toEqual([101, 102])
        ->and(collect($result['links'])->pluck('ru_entity_sentence_id')->unique()->values()->all())->toEqual([201, 202, 203, 204, 205])
        ->and(collect($result['links'])->pluck('link_group')->unique()->values()->all())->toEqual([1]);
});
