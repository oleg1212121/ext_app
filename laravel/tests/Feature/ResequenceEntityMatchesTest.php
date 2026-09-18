<?php

use App\Classes\SentenceAlignmentService;
use App\Classes\SparseOrderService;
use App\Models\Entity;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;

function seedResequenceSentence(Entity $entity, SentenceType $sentenceType, int $order, string $label): EntitySentence
{
    return EntitySentence::create([
        'entity_id' => $entity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => $label,
        'order' => $order,
    ]);
}

function seedResequenceJunction(MeaningMatch $match, EntitySentence $a, EntitySentence $b): void
{
    SentenceMeaningMatch::create([
        'entity_sentence_id' => $a->id,
        'meaning_match_id' => $match->id,
        'side' => 'a',
    ]);
    SentenceMeaningMatch::create([
        'entity_sentence_id' => $b->id,
        'meaning_match_id' => $match->id,
        'side' => 'b',
    ]);
}

it('renumbers scrambled meaning match orders into document position order', function () {
    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    $enSentences = [];
    $ruSentences = [];

    foreach (range(1, 3) as $order) {
        $enSentences[] = seedResequenceSentence($enEntity, $sentenceType, $order, "English {$order}.");
        $ruSentences[] = seedResequenceSentence($ruEntity, $sentenceType, $order, "Russian {$order}.");
    }

    $entityMatch = createEntityMatch($enEntity, $ruEntity, ['status' => 'completed']);

    // First row in document order carries the larger order; the document-last
    // row carries order 0 — exactly the scramble the display would show.
    $lateRow = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 0.9,
        'alignment_chunk' => 0,
    ]);
    seedResequenceJunction($lateRow, $enSentences[2], $ruSentences[2]);

    $earlyRow = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 5000,
        'similarity' => 0.9,
        'alignment_chunk' => 0,
    ]);
    seedResequenceJunction($earlyRow, $enSentences[0], $ruSentences[0]);

    $this->artisan('alignments:resequence', ['entityMatch' => $entityMatch->id])->assertSuccessful();

    $lateRow->refresh();
    $earlyRow->refresh();

    expect($earlyRow->order)->toBe(0, 'document-first row must get the first order')
        ->and($lateRow->order)->toBe(SparseOrderService::STRIDE, 'document-last row must get the second order');
});

it('resequences a junction-less row last and reports zero changes when order already matches document position', function () {
    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    $enSentences = [];
    $ruSentences = [];

    foreach (range(1, 2) as $order) {
        $enSentences[] = seedResequenceSentence($enEntity, $sentenceType, $order, "English {$order}.");
        $ruSentences[] = seedResequenceSentence($ruEntity, $sentenceType, $order, "Russian {$order}.");
    }

    $entityMatch = createEntityMatch($enEntity, $ruEntity, ['status' => 'completed']);

    // Order column puts the junction-less row first and the junctioned row
    // second — the opposite of document position, since junction-less rows
    // always sort last.
    $junctionedRow = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 1024,
        'similarity' => 0.9,
        'alignment_chunk' => 0,
    ]);
    seedResequenceJunction($junctionedRow, $enSentences[0], $ruSentences[0]);

    $junctionlessRow = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 0.9,
        'alignment_chunk' => 0,
    ]);

    $changed = SentenceAlignmentService::create()->resequenceMatchesByDocumentPosition($entityMatch);

    expect($changed)->toBe(2)
        ->and($junctionedRow->refresh()->order)->toBe(0, 'junctioned row must sort first')
        ->and($junctionlessRow->refresh()->order)->toBe(SparseOrderService::STRIDE, 'junction-less row must sort last');

    // A second pass is a no-op: everything is already in document position.
    expect(SentenceAlignmentService::create()->resequenceMatchesByDocumentPosition($entityMatch))->toBe(0);
});
