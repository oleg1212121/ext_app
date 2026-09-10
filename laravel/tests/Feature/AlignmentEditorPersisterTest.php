<?php

use App\Classes\AlignmentEditorPersister;
use App\Classes\AlignmentEditorPresenter;
use App\Classes\SparseOrderService;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;

/**
 * The EN entity is created first, so it is the match's 'a' side and RU is 'b'.
 */
function createAlignmentFixture(): array
{
    $sentenceType = SentenceType::create(['name' => 'sentence']);

    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English text']);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian text']);

    $en1 = EntitySentence::create([
        'entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'First EN.',
        'order' => 1,
    ]);

    $en2 = EntitySentence::create([
        'entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'Second EN.',
        'order' => 2,
    ]);

    $ru1 = EntitySentence::create([
        'entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'First RU.',
        'order' => 1,
    ]);

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'completed',
        'a_total_sentences' => 2,
        'b_total_sentences' => 1,
        'linked_count' => 1,
    ]);

    $meaningMatch = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 0.9,
        'alignment_chunk' => 0,
    ]);

    SentenceMeaningMatch::create([
        'entity_sentence_id' => $en1->id,
        'meaning_match_id' => $meaningMatch->id,
        'side' => 'a',
    ]);

    SentenceMeaningMatch::create([
        'entity_sentence_id' => $ru1->id,
        'meaning_match_id' => $meaningMatch->id,
        'side' => 'b',
    ]);

    return compact('entityMatch', 'en1', 'en2', 'ru1', 'meaningMatch', 'enEntity', 'ruEntity');
}

it('loads draft with matched and unmatched sentences', function () {
    ['entityMatch' => $entityMatch, 'en2' => $en2] = createAlignmentFixture();

    $draft = app(AlignmentEditorPresenter::class)->toDraft($entityMatch->fresh(['aEntity', 'bEntity']));

    expect($draft['meaning_rows'])->toHaveCount(1)
        ->and($draft['unmatched_a'])->toHaveCount(1)
        ->and($draft['unmatched_a'][0]['id'])->toBe($en2->id)
        ->and($draft['unmatched_b'])->toBeEmpty();
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
                'a_sentences' => [
                    $presenter->sentencePayload($en2->id, 'Second EN moved first.', 1),
                ],
                'b_sentences' => [
                    $presenter->sentencePayload($ru1->id, 'First RU updated.', 1),
                ],
            ],
        ],
        'unmatched_a' => [
            $presenter->sentencePayload($en1->id, 'First EN now unmatched.', 2),
        ],
        'unmatched_b' => [],
    ];

    app(AlignmentEditorPersister::class)->persist($entityMatch->fresh(), $draft);

    expect($en1->fresh()->content)->toBe('First EN now unmatched.')
        ->and($en1->fresh()->order)->toBe(2)
        ->and($en2->fresh()->content)->toBe('Second EN moved first.')
        ->and($en2->fresh()->order)->toBe(1)
        ->and($ru1->fresh()->content)->toBe('First RU updated.');

    $entityMatch->refresh();
    expect($entityMatch->a_total_sentences)->toBe(2)
        ->and($entityMatch->b_total_sentences)->toBe(1)
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
                'a_sentences' => [
                    $presenter->sentencePayload($en1->id, 'First EN.', 1),
                ],
                'b_sentences' => [
                    $presenter->sentencePayload($ru1->id, 'First RU.', 1),
                ],
            ],
            [
                'key' => 'mm-new-1',
                'id' => null,
                'order' => 1,
                'a_sentences' => [$newEn],
                'b_sentences' => [$newRu],
            ],
        ],
        'unmatched_a' => [],
        'unmatched_b' => [],
    ];

    app(AlignmentEditorPersister::class)->persist($entityMatch->fresh(), $draft);

    expect(EntitySentence::query()->where('content', 'Brand new EN.')->exists())->toBeTrue()
        ->and(EntitySentence::query()->where('content', 'Brand new RU.')->exists())->toBeTrue()
        ->and(MeaningMatch::query()->where('entity_match_id', $entityMatch->id)->count())->toBe(2);

    $entityMatch->refresh();
    expect($entityMatch->a_total_sentences)->toBe(2)
        ->and($entityMatch->b_total_sentences)->toBe(2)
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
                'a_sentences' => [
                    $presenter->sentencePayload($en1->id, 'First EN.', 1),
                ],
                'b_sentences' => [
                    $presenter->sentencePayload($ru1->id, 'First RU.', 1),
                ],
            ],
        ],
        'unmatched_a' => [],
        'unmatched_b' => [],
    ];

    app(AlignmentEditorPersister::class)->persist($entityMatch->fresh(), $draft);

    expect(EntitySentence::query()->where('content', 'Second EN.')->exists())->toBeFalse()
        ->and($entityMatch->fresh()->a_total_sentences)->toBe(1);
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
                'a_sentences' => [
                    $presenter->sentencePayload($en1->id, 'First EN.', 1),
                    $presenter->sentencePayload($en2->id, 'Second EN.', 2),
                ],
                'b_sentences' => [
                    $presenter->sentencePayload($ru1->id, 'First RU.', 1),
                ],
            ],
        ],
        'unmatched_a' => [],
        'unmatched_b' => [],
    ];

    app(AlignmentEditorPersister::class)->persist($entityMatch->fresh(), $draft);

    $meaningMatch->refresh();
    expect($meaningMatch->sideSentenceMeaningMatches('a')->count())->toBe(2)
        ->and($meaningMatch->sideSentenceMeaningMatches('b')->count())->toBe(1);
});

it('preserves sparse sentence orders so moving one sentence does not renumber neighbors', function () {
    $sentenceType = SentenceType::create(['name' => 'sentence']);
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'Sparse English text']);
    $ruEntity = createEntity('ru', $work, ['name' => 'Sparse Russian text']);

    $en1 = EntitySentence::create([
        'entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'First sparse EN.',
        'order' => 0,
    ]);
    $en2 = EntitySentence::create([
        'entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'Second sparse EN.',
        'order' => SparseOrderService::STRIDE,
    ]);
    $en3 = EntitySentence::create([
        'entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'Third sparse EN.',
        'order' => SparseOrderService::STRIDE * 2,
    ]);
    $ru1 = EntitySentence::create([
        'entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'First sparse RU.',
        'order' => 0,
    ]);

    $entityMatch = createEntityMatch($enEntity, $ruEntity, ['status' => 'completed']);
    $meaningMatch = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
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
                'a_sentences' => [
                    [
                        'key' => 's-'.$en3->id,
                        'id' => $en3->id,
                        'content' => 'Third sparse EN.',
                        'order' => intdiv(SparseOrderService::STRIDE, 2),
                    ],
                ],
                'b_sentences' => [
                    [
                        'key' => 's-'.$ru1->id,
                        'id' => $ru1->id,
                        'content' => 'First sparse RU.',
                        'order' => 0,
                    ],
                ],
            ],
        ],
        'unmatched_a' => [
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
        'unmatched_b' => [],
    ]);

    expect($en1->fresh()->order)->toBe(0)
        ->and($en2->fresh()->order)->toBe(SparseOrderService::STRIDE)
        ->and($en3->fresh()->order)->toBe(intdiv(SparseOrderService::STRIDE, 2));
});
