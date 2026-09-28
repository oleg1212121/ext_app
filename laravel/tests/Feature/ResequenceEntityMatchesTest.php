<?php

use App\Classes\SentenceAlignmentService;
use App\Classes\SparseOrderService;
use App\Models\Entity;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function seedResequenceSentence(Entity $entity, SentenceType $sentenceType, int $order, string $label): EntitySentence
{
    return EntitySentence::create([
        'entity_id' => $entity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => $label,
        'order' => $order,
    ]);
}

/**
 * Duplicates cannot exist once the strict unique constraint is in place —
 * suspend it to seed the legacy duplicate state the resolution pass repairs
 * (RefreshDatabase rebuilds the schema for the next test).
 */
function suspendResequenceJunctionUniqueness(): void
{
    DB::statement('ALTER TABLE sentence_meaning_matches DROP CONSTRAINT smm_match_sentence_unique');
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
    suspendResequenceJunctionUniqueness();

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

it('resolves a partial overlap by trimming the shared junction from the weaker row', function () {
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

    // Overlapping machine rows sharing EN 1 — strict junction uniqueness
    // (ADR 0048): the stronger row (higher similarity) keeps the shared
    // sentence, the weaker row keeps only the junctions of its own and
    // survives as a b-only row.
    suspendResequenceJunctionUniqueness();

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

    expect($changed)->toBe(3, 'one trimmed junction + two order changes')
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)->count())->toBe(2)
        ->and($firstRow->refresh()->order)->toBe(0)
        ->and($overlappingRow->refresh()->order)->toBe(SparseOrderService::STRIDE)
        ->and($enSentences[0]->refresh()->meaningJunctions()->count())->toBe(1, 'EN 1 keeps exactly one junction — the stronger row\'s')
        ->and($ruSentences[1]->refresh()->meaningJunctions()->count())->toBe(1)
        ->and(SentenceAlignmentService::create()->resequenceMatchesByDocumentPosition($entityMatch))->toBe(0);
});

it('keeps a landmark junction against any machine row and against a later human row', function () {
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

    // A human row pins EN 1; a high-similarity machine row (auto-landmark)
    // pins EN 2; overlapping machine rows and a later human row must all
    // lose the shared sentences to the pins.
    suspendResequenceJunctionUniqueness();

    $humanRow = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id, 'order' => 0, 'similarity' => 1.0, 'alignment_chunk' => -1,
    ]);
    seedResequenceSideJunction($humanRow, $enSentences[0], 'a');

    $machineOverHuman = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id, 'order' => 1024, 'similarity' => 0.89, 'alignment_chunk' => 2,
    ]);
    seedResequenceJunction($machineOverHuman, $enSentences[0], $ruSentences[0]);

    $autoLandmark = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id, 'order' => 2048, 'similarity' => 0.95, 'alignment_chunk' => 2,
    ]);
    seedResequenceSideJunction($autoLandmark, $enSentences[1], 'a');

    $machineOverAuto = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id, 'order' => 3072, 'similarity' => 0.7, 'alignment_chunk' => 3,
    ]);
    seedResequenceSideJunction($machineOverAuto, $enSentences[1], 'a');

    $firstHuman = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id, 'order' => 4096, 'similarity' => 1.0, 'alignment_chunk' => -1,
    ]);
    seedResequenceSideJunction($firstHuman, $enSentences[2], 'a');

    $secondHuman = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id, 'order' => 5120, 'similarity' => 1.0, 'alignment_chunk' => -1,
    ]);
    seedResequenceSideJunction($secondHuman, $enSentences[2], 'a');

    SentenceAlignmentService::create()->resequenceMatchesByDocumentPosition($entityMatch);

    expect($enSentences[0]->refresh()->meaningJunctions()->count())->toBe(1)
        ->and($enSentences[0]->meaningJunctions()->first()->meaning_match_id)->toBe($humanRow->id, 'human row beats the machine row')
        ->and($enSentences[1]->refresh()->meaningJunctions()->count())->toBe(1)
        ->and($enSentences[1]->meaningJunctions()->first()->meaning_match_id)->toBe($autoLandmark->id, 'auto-landmark beats the machine row')
        ->and($enSentences[2]->refresh()->meaningJunctions()->count())->toBe(1)
        ->and($enSentences[2]->meaningJunctions()->first()->meaning_match_id)->toBe($firstHuman->id, 'earlier-ordered human row wins the human-vs-human conflict')
        ->and($machineOverHuman->refresh()->sentenceMeaningMatches->pluck('side')->all())->toBe(['b'], 'machine row survives with only its own junction');
});

it('rejects a second junction for the same sentence in the same match at the DB level', function () {
    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    $enSentence = seedResequenceSentence($enEntity, $sentenceType, 1, 'English 1.');
    $ruSentence = seedResequenceSentence($ruEntity, $sentenceType, 1, 'Russian 1.');

    $entityMatch = createEntityMatch($enEntity, $ruEntity, ['status' => 'completed']);

    $row = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id, 'order' => 0, 'similarity' => 0.9, 'alignment_chunk' => 0,
    ]);
    seedResequenceSideJunction($row, $enSentence, 'a');

    $otherRow = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id, 'order' => 1024, 'similarity' => 0.5, 'alignment_chunk' => 1,
    ]);

    expect(fn () => seedResequenceSideJunction($otherRow, $enSentence, 'a'))->toThrow(QueryException::class)
        ->and(fn () => SentenceMeaningMatch::create([
            // Even without the explicit column, the model hook fills it and
            // the constraint still applies.
            'entity_sentence_id' => $ruSentence->id,
            'meaning_match_id' => $otherRow->id,
            'side' => 'b',
        ]))->not->toThrow(QueryException::class, 'a different sentence in the same match junctions fine');
});
