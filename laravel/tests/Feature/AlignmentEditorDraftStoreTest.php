<?php

use App\Classes\AlignmentEditorDraftStore;
use App\Classes\AlignmentEditorPresenter;

it('paginates meaning rows and unmatched sentences from draft data', function () {
    $store = app(AlignmentEditorDraftStore::class);
    $presenter = app(AlignmentEditorPresenter::class);

    $draft = [
        'meaning_rows' => [],
        'unmatched_en' => [],
        'unmatched_ru' => [],
    ];

    for ($i = 1; $i <= 30; $i++) {
        $draft['meaning_rows'][] = [
            'key' => "mm-{$i}",
            'id' => $i,
            'en_sentences' => [$presenter->sentencePayload($i, "EN {$i}", $i)],
            'ru_sentences' => [$presenter->sentencePayload($i + 100, "RU {$i}", $i)],
        ];
    }

    for ($i = 1; $i <= 20; $i++) {
        $draft['unmatched_en'][] = $presenter->sentencePayload(200 + $i, "Unmatched EN {$i}", $i);
    }

    $meaningPage1 = $store->paginateMeaningRows($draft, 1, 25);
    $meaningPage2 = $store->paginateMeaningRows($draft, 2, 25);
    $unmatchedPage = $store->paginateUnmatched($draft, 'en', 2, 10);

    expect($meaningPage1['total'])->toBe(30)
        ->and($meaningPage1['rows'])->toHaveCount(25)
        ->and($meaningPage2['rows'])->toHaveCount(5)
        ->and($meaningPage2['last_page'])->toBe(2)
        ->and($unmatchedPage['total'])->toBe(20)
        ->and($unmatchedPage['rows'])->toHaveCount(10);
});

it('stores and retrieves draft in session', function () {
    $store = app(AlignmentEditorDraftStore::class);

    $store->put(1, 5, ['meaning_rows' => [], 'unmatched_en' => [], 'unmatched_ru' => []]);

    expect($store->get(1, 5))->toBeArray()
        ->and($store->get(2, 5))->toBeNull();

    $store->forget(1, 5);

    expect($store->get(1, 5))->toBeNull();
});
