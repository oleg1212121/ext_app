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

function seedResequenceSideJunction(MeaningMatch $match, EntitySentence $sentence, string $side): void
{
    SentenceMeaningMatch::create([
        'entity_sentence_id' => $sentence->id,
        'meaning_match_id' => $match->id,
        'side' => $side,
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

it('slots a single-b row before the two-sided row whose b sentences come later', function () {
    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    $enSentences = [];
    $ruSentences = [];

    foreach (range(1, 2) as $order) {
        $enSentences[] = seedResequenceSentence($enEntity, $sentenceType, $order, "English {$order}.");
    }

    foreach (range(1, 4) as $order) {
        $ruSentences[] = seedResequenceSentence($ruEntity, $sentenceType, $order, "Russian {$order}.");
    }

    $entityMatch = createEntityMatch($enEntity, $ruEntity, ['status' => 'completed']);

    // The reported regression: a two-sided row anchored at EN 1 pairs with
    // RU 4 while RU 3 went unmatched. Stored order puts the two-sided row
    // first, so walking by order reads RU 0, 4, 3 — the "RU 67 then RU 65"
    // descent. Sorting b-only rows by the a scale anchors them after it.
    $headRow = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 0.9,
        'alignment_chunk' => 0,
    ]);
    seedResequenceJunction($headRow, $enSentences[0], $ruSentences[0]);

    $lateBRow = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 1024,
        'similarity' => 0.9,
        'alignment_chunk' => 0,
    ]);
    seedResequenceJunction($lateBRow, $enSentences[1], $ruSentences[3]);

    $bOnlyRow = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 2048,
        'similarity' => 0.0,
        'alignment_chunk' => 1,
    ]);
    seedResequenceSideJunction($bOnlyRow, $ruSentences[2], 'b');

    $changed = SentenceAlignmentService::create()->resequenceMatchesByDocumentPosition($entityMatch);

    expect($changed)->toBe(2)
        ->and($headRow->refresh()->order)->toBe(0)
        ->and($bOnlyRow->refresh()->order)->toBe(SparseOrderService::STRIDE, 'the unmatched RU sentence sorts before the row whose RU partner is later')
        ->and($lateBRow->refresh()->order)->toBe(SparseOrderService::STRIDE * 2)
        ->and(SentenceAlignmentService::create()->resequenceMatchesByDocumentPosition($entityMatch))->toBe(0);
});

it('interleaves a-only, b-only, two-sided and junction-less rows into one document sequence', function () {
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

    $twoSidedFirst = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id, 'order' => 3000, 'similarity' => 0.9, 'alignment_chunk' => 0,
    ]);
    seedResequenceJunction($twoSidedFirst, $enSentences[0], $ruSentences[0]);

    $aOnlyRow = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id, 'order' => 1000, 'similarity' => 0.0, 'alignment_chunk' => 1,
    ]);
    seedResequenceSideJunction($aOnlyRow, $enSentences[1], 'a');

    $bOnlyRow = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id, 'order' => 2000, 'similarity' => 0.0, 'alignment_chunk' => 1,
    ]);
    seedResequenceSideJunction($bOnlyRow, $ruSentences[1], 'b');

    $twoSidedLast = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id, 'order' => 4000, 'similarity' => 0.9, 'alignment_chunk' => 0,
    ]);
    seedResequenceJunction($twoSidedLast, $enSentences[2], $ruSentences[2]);

    $orphanRow = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id, 'order' => 0, 'similarity' => 0.0, 'alignment_chunk' => 1,
    ]);

    SentenceAlignmentService::create()->resequenceMatchesByDocumentPosition($entityMatch);

    $sequence = MeaningMatch::query()
        ->where('entity_match_id', $entityMatch->id)
        ->orderBy('order')
        ->pluck('id')
        ->all();

    // a-anchored rows read in a order (the b-only row slots between the
    // two-sided rows bracketing its b position); the junction-less row last.
    expect($sequence)->toBe([
        $twoSidedFirst->id,
        $aOnlyRow->id,
        $bOnlyRow->id,
        $twoSidedLast->id,
        $orphanRow->id,
    ]);
});

it('drops a machine row fully duplicated by a better row before renumbering', function () {
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

    // The same pair junctioned into two rows — the signature of a re-fed
    // window. The stronger row (higher similarity) keeps the junctions.
    $keeperRow = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id, 'order' => 1024, 'similarity' => 0.8, 'alignment_chunk' => 0,
    ]);
    seedResequenceJunction($keeperRow, $enSentences[0], $ruSentences[0]);

    $dupeRow = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id, 'order' => 0, 'similarity' => 0.4, 'alignment_chunk' => 1,
    ]);
    seedResequenceJunction($dupeRow, $enSentences[0], $ruSentences[0]);

    $lastRow = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id, 'order' => 2048, 'similarity' => 0.9, 'alignment_chunk' => 0,
    ]);
    seedResequenceJunction($lastRow, $enSentences[1], $ruSentences[1]);

    $changed = SentenceAlignmentService::create()->resequenceMatchesByDocumentPosition($entityMatch);

    expect($changed)->toBe(3)
        ->and(MeaningMatch::query()->whereKey($dupeRow->id)->exists())->toBeFalse()
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)->count())->toBe(2)
        ->and($keeperRow->refresh()->order)->toBe(0)
        ->and($lastRow->refresh()->order)->toBe(SparseOrderService::STRIDE)
        ->and($enSentences[0]->refresh()->meaningJunctions()->count())->toBe(1)
        ->and(SentenceAlignmentService::create()->resequenceMatchesByDocumentPosition($entityMatch))->toBe(0);
});

it('keeps machine rows that only partially overlap so each keeps its own junctions', function () {
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

    // Legitimate n:m overlap: both rows share EN 1 but each holds a b
    // junction of its own, so neither is fully subsumed.
    $firstRow = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id, 'order' => 5000, 'similarity' => 0.5, 'alignment_chunk' => 0,
    ]);
    seedResequenceJunction($firstRow, $enSentences[0], $ruSentences[0]);

    $overlappingRow = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id, 'order' => 3000, 'similarity' => 0.3, 'alignment_chunk' => 1,
    ]);
    seedResequenceSideJunction($overlappingRow, $enSentences[0], 'a');
    seedResequenceSideJunction($overlappingRow, $ruSentences[1], 'b');

    $changed = SentenceAlignmentService::create()->resequenceMatchesByDocumentPosition($entityMatch);

    expect($changed)->toBe(2)
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)->count())->toBe(2)
        ->and($firstRow->refresh()->order)->toBe(0)
        ->and($overlappingRow->refresh()->order)->toBe(SparseOrderService::STRIDE)
        ->and($enSentences[0]->refresh()->meaningJunctions()->count())->toBe(2);
});
