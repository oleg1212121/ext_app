<?php

use App\Classes\SparseOrderService;
use App\Classes\AlignmentEditorPersister;
use App\Classes\AlignmentEditorPresenter;
use App\Models\EnEntity;
use App\Models\EnEntitySentence;
use App\Models\EnRuEntityMatch;
use App\Models\EnRuMeaningMatch;
use App\Models\EnSentenceMeaningMatch;
use App\Models\RuEntity;
use App\Models\RuEntitySentence;
use App\Models\RuSentenceMeaningMatch;
use App\Models\SentenceType;

function createAlignmentFixture(): array
{
    $sentenceType = SentenceType::create(['name' => 'sentence']);

    $enEntity = EnEntity::create(['name' => 'English text']);
    $ruEntity = RuEntity::create(['name' => 'Russian text']);

    $en1 = EnEntitySentence::create([
        'en_entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'First EN.',
        'order' => 1,
    ]);

    $en2 = EnEntitySentence::create([
        'en_entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'Second EN.',
        'order' => 2,
    ]);

    $ru1 = RuEntitySentence::create([
        'ru_entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'First RU.',
        'order' => 1,
    ]);

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'completed',
        'en_total_sentences' => 2,
        'ru_total_sentences' => 1,
        'linked_count' => 1,
    ]);

    $meaningMatch = EnRuMeaningMatch::create([
        'en_ru_entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 0.9,
        'alignment_chunk' => 0,
    ]);

    EnSentenceMeaningMatch::create([
        'en_entity_sentence_id' => $en1->id,
        'en_ru_meaning_match_id' => $meaningMatch->id,
        'order' => 0,
    ]);

    RuSentenceMeaningMatch::create([
        'ru_entity_sentence_id' => $ru1->id,
        'en_ru_meaning_match_id' => $meaningMatch->id,
        'order' => 0,
    ]);

    return compact('entityMatch', 'en1', 'en2', 'ru1', 'meaningMatch', 'enEntity', 'ruEntity');
}

it('loads draft with matched and unmatched sentences', function () {
    ['entityMatch' => $entityMatch, 'en2' => $en2] = createAlignmentFixture();

    $draft = app(AlignmentEditorPresenter::class)->toDraft($entityMatch->fresh(['enEntity', 'ruEntity']));

    expect($draft['meaning_rows'])->toHaveCount(1)
        ->and($draft['unmatched_en'])->toHaveCount(1)
        ->and($draft['unmatched_en'][0]['id'])->toBe($en2->id)
        ->and($draft['unmatched_ru'])->toBeEmpty();
});

it('persists updated sentence content and order', function () {
    ['entityMatch' => $entityMatch, 'en1' => $en1, 'en2' => $en2, 'ru1' => $ru1, 'meaningMatch' => $meaningMatch] = createAlignmentFixture();

    $presenter = app(AlignmentEditorPresenter::class);

    $draft = [
        'meaning_rows' => [
            [
                'key' => 'mm-'.$meaningMatch->id,
                'id' => $meaningMatch->id,
                'order' => 0,
                'en_sentences' => [
                    $presenter->sentencePayload($en2->id, 'Second EN moved first.', 1),
                ],
                'ru_sentences' => [
                    $presenter->sentencePayload($ru1->id, 'First RU updated.', 1),
                ],
            ],
        ],
        'unmatched_en' => [
            $presenter->sentencePayload($en1->id, 'First EN now unmatched.', 2),
        ],
        'unmatched_ru' => [],
    ];

    app(AlignmentEditorPersister::class)->persist($entityMatch->fresh(), $draft);

    expect($en1->fresh()->content)->toBe('First EN now unmatched.')
        ->and($en1->fresh()->order)->toBe(2)
        ->and($en2->fresh()->content)->toBe('Second EN moved first.')
        ->and($en2->fresh()->order)->toBe(1)
        ->and($ru1->fresh()->content)->toBe('First RU updated.');

    $entityMatch->refresh();
    expect($entityMatch->en_total_sentences)->toBe(2)
        ->and($entityMatch->ru_total_sentences)->toBe(1)
        ->and($entityMatch->status)->toBe('completed');
});

it('creates new sentences and meaning rows on persist', function () {
    ['entityMatch' => $entityMatch, 'en1' => $en1, 'ru1' => $ru1, 'meaningMatch' => $meaningMatch] = createAlignmentFixture();

    $presenter = app(AlignmentEditorPresenter::class);
    $newEn = $presenter->sentencePayload(null, 'Brand new EN.', 3, 'tmp-en-1');
    $newRu = $presenter->sentencePayload(null, 'Brand new RU.', 2, 'tmp-ru-1');

    $draft = [
        'meaning_rows' => [
            [
                'key' => 'mm-'.$meaningMatch->id,
                'id' => $meaningMatch->id,
                'order' => 0,
                'en_sentences' => [
                    $presenter->sentencePayload($en1->id, 'First EN.', 1),
                ],
                'ru_sentences' => [
                    $presenter->sentencePayload($ru1->id, 'First RU.', 1),
                ],
            ],
            [
                'key' => 'mm-new-1',
                'id' => null,
                'order' => 1,
                'en_sentences' => [$newEn],
                'ru_sentences' => [$newRu],
            ],
        ],
        'unmatched_en' => [],
        'unmatched_ru' => [],
    ];

    app(AlignmentEditorPersister::class)->persist($entityMatch->fresh(), $draft);

    expect(EnEntitySentence::query()->where('content', 'Brand new EN.')->exists())->toBeTrue()
        ->and(RuEntitySentence::query()->where('content', 'Brand new RU.')->exists())->toBeTrue()
        ->and(EnRuMeaningMatch::query()->where('en_ru_entity_match_id', $entityMatch->id)->count())->toBe(2);

    $entityMatch->refresh();
    expect($entityMatch->en_total_sentences)->toBe(2)
        ->and($entityMatch->ru_total_sentences)->toBe(2)
        ->and($entityMatch->linked_count)->toBe(2);
});

it('deletes removed sentences on persist', function () {
    ['entityMatch' => $entityMatch, 'en1' => $en1, 'ru1' => $ru1, 'meaningMatch' => $meaningMatch] = createAlignmentFixture();

    $presenter = app(AlignmentEditorPresenter::class);

    $draft = [
        'meaning_rows' => [
            [
                'key' => 'mm-'.$meaningMatch->id,
                'id' => $meaningMatch->id,
                'order' => 0,
                'en_sentences' => [
                    $presenter->sentencePayload($en1->id, 'First EN.', 1),
                ],
                'ru_sentences' => [
                    $presenter->sentencePayload($ru1->id, 'First RU.', 1),
                ],
            ],
        ],
        'unmatched_en' => [],
        'unmatched_ru' => [],
    ];

    app(AlignmentEditorPersister::class)->persist($entityMatch->fresh(), $draft);

    expect(EnEntitySentence::query()->where('content', 'Second EN.')->exists())->toBeFalse()
        ->and($entityMatch->fresh()->en_total_sentences)->toBe(1);
});

it('supports n to m meaning groups on persist', function () {
    ['entityMatch' => $entityMatch, 'en1' => $en1, 'en2' => $en2, 'ru1' => $ru1, 'meaningMatch' => $meaningMatch] = createAlignmentFixture();

    $presenter = app(AlignmentEditorPresenter::class);

    $draft = [
        'meaning_rows' => [
            [
                'key' => 'mm-'.$meaningMatch->id,
                'id' => $meaningMatch->id,
                'order' => 0,
                'en_sentences' => [
                    $presenter->sentencePayload($en1->id, 'First EN.', 1),
                    $presenter->sentencePayload($en2->id, 'Second EN.', 2),
                ],
                'ru_sentences' => [
                    $presenter->sentencePayload($ru1->id, 'First RU.', 1),
                ],
            ],
        ],
        'unmatched_en' => [],
        'unmatched_ru' => [],
    ];

    app(AlignmentEditorPersister::class)->persist($entityMatch->fresh(), $draft);

    $meaningMatch->refresh();
    expect($meaningMatch->enSentenceMatches()->count())->toBe(2)
        ->and($meaningMatch->ruSentenceMatches()->count())->toBe(1);
});

it('preserves sparse sentence orders so moving one sentence does not renumber neighbors', function () {
    $sentenceType = SentenceType::create(['name' => 'sentence']);
    $enEntity = EnEntity::create(['name' => 'Sparse English text']);
    $ruEntity = RuEntity::create(['name' => 'Sparse Russian text']);

    $en1 = EnEntitySentence::create([
        'en_entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'First sparse EN.',
        'order' => 0,
    ]);
    $en2 = EnEntitySentence::create([
        'en_entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'Second sparse EN.',
        'order' => SparseOrderService::STRIDE,
    ]);
    $en3 = EnEntitySentence::create([
        'en_entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'Third sparse EN.',
        'order' => SparseOrderService::STRIDE * 2,
    ]);
    $ru1 = RuEntitySentence::create([
        'ru_entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'First sparse RU.',
        'order' => 0,
    ]);

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'completed',
    ]);
    $meaningMatch = EnRuMeaningMatch::create([
        'en_ru_entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 1.0,
        'alignment_chunk' => 0,
    ]);

    app(AlignmentEditorPersister::class)->persist($entityMatch, [
        'meaning_rows' => [
            [
                'key' => 'mm-'.$meaningMatch->id,
                'id' => $meaningMatch->id,
                'order' => 0,
                'en_sentences' => [
                    [
                        'key' => 's-'.$en3->id,
                        'id' => $en3->id,
                        'content' => 'Third sparse EN.',
                        'order' => intdiv(SparseOrderService::STRIDE, 2),
                    ],
                ],
                'ru_sentences' => [
                    [
                        'key' => 's-'.$ru1->id,
                        'id' => $ru1->id,
                        'content' => 'First sparse RU.',
                        'order' => 0,
                    ],
                ],
            ],
        ],
        'unmatched_en' => [
            [
                'key' => 's-'.$en1->id,
                'id' => $en1->id,
                'content' => 'First sparse EN.',
                'order' => 0,
            ],
            [
                'key' => 's-'.$en2->id,
                'id' => $en2->id,
                'content' => 'Second sparse EN.',
                'order' => SparseOrderService::STRIDE,
            ],
        ],
        'unmatched_ru' => [],
    ]);

    expect($en1->fresh()->order)->toBe(0)
        ->and($en2->fresh()->order)->toBe(SparseOrderService::STRIDE)
        ->and($en3->fresh()->order)->toBe(intdiv(SparseOrderService::STRIDE, 2));
});
