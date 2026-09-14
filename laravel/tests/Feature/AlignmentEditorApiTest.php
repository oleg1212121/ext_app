<?php

use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/**
 * The EN entity is created first, so it is the match's 'a' side and RU is 'b'.
 */
function editorWorld(array $enOrders = [], array $ruOrders = []): array
{
    $type = SentenceType::create(['name' => 'sentence']);
    $work = createWork();
    $en = createEntity('en', $work, ['name' => 'En text']);
    $ru = createEntity('ru', $work, ['name' => 'Ru text']);

    $match = createEntityMatch($en, $ru, ['status' => 'pending']);

    $enSentences = [];
    foreach ($enOrders as $order) {
        $enSentences[] = EntitySentence::create([
            'entity_id' => $en->id,
            'sentence_type_id' => $type->id,
            'content' => "EN {$order}",
            'order' => $order,
        ]);
    }

    $ruSentences = [];
    foreach ($ruOrders as $order) {
        $ruSentences[] = EntitySentence::create([
            'entity_id' => $ru->id,
            'sentence_type_id' => $type->id,
            'content' => "RU {$order}",
            'order' => $order,
        ]);
    }

    return compact('match', 'type', 'en', 'ru', 'enSentences', 'ruSentences');
}

function makeRow(int $matchId, int $order, float $similarity = 0.9): MeaningMatch
{
    return MeaningMatch::create([
        'entity_match_id' => $matchId,
        'order' => $order,
        'similarity' => $similarity,
        'alignment_chunk' => 0,
    ]);
}

/**
 * @param  'a'|'b'  $side
 */
function linkSentence(string $side, int $sentenceId, int $rowId): void
{
    SentenceMeaningMatch::create([
        'entity_sentence_id' => $sentenceId,
        'meaning_match_id' => $rowId,
        'side' => $side,
    ]);
}

test('guests cannot call editor endpoints', function () {
    $world = editorWorld();

    $this->postJson("/alignments/{$world['match']->id}/rows", [])
        ->assertUnauthorized();

    $this->getJson("/alignments/{$world['match']->id}/rows")
        ->assertUnauthorized();

    $this->getJson("/alignments/{$world['match']->id}/unmatched?side=a")
        ->assertUnauthorized();

    $this->getJson("/alignments/{$world['match']->id}/needs-review")
        ->assertUnauthorized();

    $row = makeRow($world['match']->id, 100);

    $this->postJson("/alignments/{$world['match']->id}/rows/{$row->id}/approve")
        ->assertUnauthorized();
});

test('approves a row by setting its similarity to 1 and marking it as a hard landmark', function () {
    $world = editorWorld();
    $row = makeRow($world['match']->id, 100, 0.42);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/rows/{$row->id}/approve");

    $response->assertOk();
    $this->assertSame(1.0, (float) $response->json('rows.0.similarity'));
    $this->assertDatabaseHas('meaning_matches', [
        'id' => $row->id,
        'similarity' => 1.0,
        'alignment_chunk' => -1,
    ]);
});

test('cannot approve a row belonging to another entity match', function () {
    $world = editorWorld();
    $otherWork = createWork();
    $otherMatch = createEntityMatch(
        createEntity('en', $otherWork, ['name' => 'Other En']),
        createEntity('ru', $otherWork, ['name' => 'Other Ru']),
        ['status' => 'pending'],
    );
    $row = makeRow($world['match']->id, 100);

    actingAs(User::factory()->create())
        ->postJson("/alignments/{$otherMatch->id}/rows/{$row->id}/approve")
        ->assertNotFound();
});

test('creates an empty pair between the current and next row', function () {
    $world = editorWorld();
    $first = makeRow($world['match']->id, 100);
    $second = makeRow($world['match']->id, 200);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/rows", ['after_row_id' => $first->id]);

    $response->assertOk();

    $row = $response->json('rows.0');
    $this->assertNotNull($row);
    $this->assertSame([], $row['a_sentences']);
    $this->assertSame([], $row['b_sentences']);
    $this->assertGreaterThan(100, $row['order']);
    $this->assertLessThan(200, $row['order']);

    $this->assertSame(3, $response->json('match.linked_count'));

    $this->assertDatabaseHas('meaning_matches', ['id' => $row['id']]);
});

test('creates an empty pair at the end when no after_row_id is given', function () {
    $world = editorWorld();
    $first = makeRow($world['match']->id, 100);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/rows", []);

    $response->assertOk();
    $this->assertGreaterThan(100, $response->json('rows.0.order'));
});

test('deleting an empty pair removes the row', function () {
    $world = editorWorld();
    $row = makeRow($world['match']->id, 100);

    $response = actingAs(User::factory()->create())
        ->deleteJson("/alignments/{$world['match']->id}/rows/{$row->id}");

    $response->assertOk();
    $this->assertSame([$row->id], $response->json('deleted_rows'));
    $this->assertSame([], $response->json('unmatched_changed'));
    $this->assertDatabaseMissing('meaning_matches', ['id' => $row->id]);
});

test('deleting a non-empty pair unlinks its sentences to unmatched', function () {
    $world = editorWorld([100], [100]);
    $row = makeRow($world['match']->id, 100);
    linkSentence('a', $world['enSentences'][0]->id, $row->id);
    linkSentence('b', $world['ruSentences'][0]->id, $row->id);

    $response = actingAs(User::factory()->create())
        ->deleteJson("/alignments/{$world['match']->id}/rows/{$row->id}");

    $response->assertOk();
    $this->assertSame([$row->id], $response->json('deleted_rows'));
    expect($response->json('unmatched_changed'))->toContain('a')->toContain('b');
    $this->assertDatabaseMissing('meaning_matches', ['id' => $row->id]);
    $this->assertDatabaseMissing('sentence_meaning_matches', ['meaning_match_id' => $row->id]);
    $this->assertDatabaseHas('entity_sentences', ['id' => $world['enSentences'][0]->id]);
    $this->assertDatabaseHas('entity_sentences', ['id' => $world['ruSentences'][0]->id]);

    $unmatched = actingAs(User::factory()->create())
        ->getJson("/alignments/{$world['match']->id}/unmatched?side=a")
        ->assertOk()
        ->json();

    expect(collect($unmatched['items'])->pluck('id'))->toContain($world['enSentences'][0]->id);
});

test('adds a sentence to a row after the last sentence of that row', function () {
    $world = editorWorld([100, 150]);
    $row = makeRow($world['match']->id, 100);
    linkSentence('a', $world['enSentences'][0]->id, $row->id);
    linkSentence('a', $world['enSentences'][1]->id, $row->id);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences", [
            'side' => 'a',
            'meaning_match_id' => $row->id,
            'content' => 'A brand new sentence.',
        ]);

    $response->assertOk();

    $sentences = $response->json('rows.0.a_sentences');
    $this->assertCount(3, $sentences);
    $this->assertSame('A brand new sentence.', $sentences[2]['content']);
    $this->assertGreaterThan(150, $sentences[2]['order']);

    $this->assertDatabaseHas('sentence_meaning_matches', [
        'entity_sentence_id' => $sentences[2]['id'],
        'meaning_match_id' => $row->id,
    ]);

    $this->assertSame(3, $response->json('match.a_total_sentences'));
});

test('a new sentence is placed at the document boundary', function () {
    $world = editorWorld([100, 150, 200]);
    $row = makeRow($world['match']->id, 100);
    [$a, $b, $c] = $world['enSentences'];
    linkSentence('a', $a->id, $row->id);
    linkSentence('a', $b->id, $row->id);
    linkSentence('a', $c->id, $row->id);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences", [
            'side' => 'a',
            'meaning_match_id' => $row->id,
            'content' => 'A brand new sentence.',
        ]);

    $response->assertOk();

    $sentences = $response->json('rows.0.a_sentences');
    $this->assertCount(4, $sentences);
    $newId = $sentences[3]['id'];

    $newSentence = EntitySentence::query()->whereKey($newId)->firstOrFail();
    $this->assertGreaterThan(200, $newSentence->order);

    $this->assertDatabaseHas('sentence_meaning_matches', [
        'entity_sentence_id' => $newId,
        'meaning_match_id' => $row->id,
    ]);

    $allSentenceIds = SentenceMeaningMatch::query()
        ->where('meaning_match_id', $row->id)
        ->get()
        ->map(fn ($match) => $match->entitySentence?->order ?? 0)
        ->sort()
        ->values()
        ->all();

    $this->assertSame($allSentenceIds, collect($allSentenceIds)->sort()->values()->all());
});

test('adds a sentence to an empty row after the previous row last sentence', function () {
    $world = editorWorld([100]);
    $first = makeRow($world['match']->id, 100);
    $second = makeRow($world['match']->id, 200);
    linkSentence('a', $world['enSentences'][0]->id, $first->id);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences", [
            'side' => 'a',
            'meaning_match_id' => $second->id,
            'content' => 'Fills the empty row.',
        ]);

    $response->assertOk();
    $sentences = $response->json('rows.0.a_sentences');
    $this->assertCount(1, $sentences);
    $this->assertGreaterThan(100, $sentences[0]['order']);
});

test('adds a sentence to a row with no prior sentences and gets a non-negative order', function () {
    $world = editorWorld([100]);
    $row = makeRow($world['match']->id, 100);
    linkSentence('a', $world['enSentences'][0]->id, $row->id);

    $newRow = makeRow($world['match']->id, 200);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences", [
            'side' => 'a',
            'meaning_match_id' => $newRow->id,
            'content' => 'First EN sentence in an empty row with no prior RU rows.',
        ]);

    $response->assertOk();
    $sentences = $response->json('rows.0.a_sentences');
    $this->assertCount(1, $sentences);
    $this->assertGreaterThanOrEqual(0, $sentences[0]['order']);
});

test('adds the very first sentence of a language and gets a non-negative order', function () {
    $world = editorWorld([], []);
    $row = makeRow($world['match']->id, 100);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences", [
            'side' => 'a',
            'meaning_match_id' => $row->id,
            'content' => 'The very first EN sentence.',
        ]);

    $response->assertOk();
    $sentences = $response->json('rows.0.a_sentences');
    $this->assertCount(1, $sentences);
    $this->assertGreaterThanOrEqual(0, $sentences[0]['order']);
});

test('rejects empty sentence content', function () {
    $world = editorWorld([100]);
    $row = makeRow($world['match']->id, 100);
    $sentence = $world['enSentences'][0];
    linkSentence('a', $sentence->id, $row->id);

    actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences", [
            'side' => 'a',
            'meaning_match_id' => $row->id,
            'content' => '   ',
        ])
        ->assertUnprocessable();

    actingAs(User::factory()->create())
        ->patchJson("/alignments/{$world['match']->id}/sentences/{$sentence->id}", [
            'side' => 'a',
            'content' => '',
        ])
        ->assertUnprocessable();
});

test('edits sentence content', function () {
    $world = editorWorld([100]);
    $row = makeRow($world['match']->id, 100);
    $sentence = $world['enSentences'][0];
    linkSentence('a', $sentence->id, $row->id);

    $response = actingAs(User::factory()->create())
        ->patchJson("/alignments/{$world['match']->id}/sentences/{$sentence->id}", [
            'side' => 'a',
            'content' => 'Edited content.',
        ]);

    $response->assertOk();
    $this->assertSame('Edited content.', $response->json('rows.0.a_sentences.0.content'));
    $this->assertDatabaseHas('entity_sentences', ['id' => $sentence->id, 'content' => 'Edited content.']);
});

test('unlinks a sentence to unmatched', function () {
    $world = editorWorld([100]);
    $row = makeRow($world['match']->id, 100);
    $sentence = $world['enSentences'][0];
    linkSentence('a', $sentence->id, $row->id);

    $response = actingAs(User::factory()->create())
        ->deleteJson("/alignments/{$world['match']->id}/sentences/{$sentence->id}", ['side' => 'a']);

    $response->assertOk();
    $this->assertSame([], $response->json('rows.0.a_sentences'));
    expect($response->json('unmatched_changed'))->toContain('a');
    $this->assertDatabaseMissing('sentence_meaning_matches', ['entity_sentence_id' => $sentence->id]);
});

test('moves a sentence within a row to reorder it', function () {
    $world = editorWorld([213, 240, 250]);
    $row = makeRow($world['match']->id, 100);
    [$a, $b, $c] = $world['enSentences'];
    linkSentence('a', $a->id, $row->id);
    linkSentence('a', $b->id, $row->id);
    linkSentence('a', $c->id, $row->id);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences/move", [
            'side' => 'a',
            'sentence_id' => $c->id,
            'to_row_id' => $row->id,
            'index' => 1,
        ]);

    $response->assertOk();

    $sentenceIdsByDocumentOrder = SentenceMeaningMatch::query()
        ->where('meaning_match_id', $row->id)
        ->get()
        ->map(fn ($match) => [
            'id' => $match->entity_sentence_id,
            'order' => $match->entitySentence?->order ?? 0,
        ])
        ->sortBy('order')
        ->pluck('id')
        ->all();

    expect($sentenceIdsByDocumentOrder)->toBe([$a->id, $c->id, $b->id]);

    $sentenceOrders = EntitySentence::query()
        ->whereIn('id', [$a->id, $b->id, $c->id])
        ->pluck('order', 'id')
        ->all();

    expect($sentenceOrders[$a->id])->toBe(213);
    expect($sentenceOrders[$c->id])->toBeGreaterThan(213)->toBeLessThan(240);
    expect($sentenceOrders[$b->id])->toBe(240);
});

test('reorder within row with consecutive orders uses global bounds', function () {
    $world = editorWorld([5, 18, 19, 20, 50]);
    $row = makeRow($world['match']->id, 100);
    [$before, $a, $b, $c, $after] = $world['enSentences'];
    linkSentence('a', $a->id, $row->id);
    linkSentence('a', $b->id, $row->id);
    linkSentence('a', $c->id, $row->id);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences/move", [
            'side' => 'a',
            'sentence_id' => $c->id,
            'to_row_id' => $row->id,
            'index' => 1,
        ]);

    $response->assertOk();

    $sentenceOrders = EntitySentence::query()
        ->whereIn('id', [$a->id, $b->id, $c->id])
        ->pluck('order', 'id')
        ->all();

    $allOrders = EntitySentence::query()
        ->where('entity_id', $world['en']->id)
        ->pluck('order', 'id');

    expect($allOrders->values()->unique()->count())->toBe($allOrders->count());
    expect($sentenceOrders[$c->id])->toBeGreaterThan($sentenceOrders[$a->id]);
    expect($sentenceOrders[$c->id])->toBeLessThan($sentenceOrders[$b->id]);
    expect($sentenceOrders[$a->id])->toBeGreaterThan($allOrders[$before->id]);
    expect($sentenceOrders[$b->id])->toBeLessThan($allOrders[$after->id]);
});

test('moves a sentence from one row to another', function () {
    $world = editorWorld([10, 100, 110, 500]);
    $rowA = makeRow($world['match']->id, 100);
    $rowB = makeRow($world['match']->id, 200);
    [$low, $a, $b, $high] = $world['enSentences'];
    linkSentence('a', $a->id, $rowA->id);
    linkSentence('a', $b->id, $rowB->id);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences/move", [
            'side' => 'a',
            'sentence_id' => $a->id,
            'to_row_id' => $rowB->id,
            'index' => 1,
        ]);

    $response->assertOk();

    $rowAIds = $response->json('rows.0.a_sentences');
    $rowBIds = $response->json('rows.1.a_sentences');

    $this->assertSame([], $rowAIds);
    expect(collect($rowBIds)->pluck('id')->all())->toBe([$b->id, $a->id]);
    $this->assertDatabaseMissing('sentence_meaning_matches', ['entity_sentence_id' => $a->id, 'meaning_match_id' => $rowA->id]);
    $this->assertDatabaseHas('sentence_meaning_matches', ['entity_sentence_id' => $a->id, 'meaning_match_id' => $rowB->id]);

    $sentenceOrders = EntitySentence::query()
        ->whereIn('id', [$a->id, $b->id])
        ->pluck('order', 'id');

    expect($sentenceOrders[$b->id])->toBe(110);
    expect($sentenceOrders[$a->id])->toBeGreaterThan(110)->toBeLessThan(500);
});

test('moves a sentence from unmatched into a row', function () {
    $world = editorWorld([100, 300, 900]);
    $row = makeRow($world['match']->id, 100);
    [$a, $unmatched, $high] = $world['enSentences'];
    linkSentence('a', $a->id, $row->id);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences/move", [
            'side' => 'a',
            'sentence_id' => $unmatched->id,
            'to_row_id' => $row->id,
            'index' => 1,
        ]);

    $response->assertOk();

    $sentences = $response->json('rows.0.a_sentences');
    expect(collect($sentences)->pluck('id')->all())->toBe([$a->id, $unmatched->id]);
    $this->assertDatabaseHas('sentence_meaning_matches', ['entity_sentence_id' => $unmatched->id, 'meaning_match_id' => $row->id]);
    $this->assertDatabaseHas('entity_sentences', ['id' => $unmatched->id, 'order' => 500]);
});

test('moves a sentence from a row out to unmatched', function () {
    $world = editorWorld([100]);
    $row = makeRow($world['match']->id, 100);
    $sentence = $world['enSentences'][0];
    linkSentence('a', $sentence->id, $row->id);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences/move", [
            'side' => 'a',
            'sentence_id' => $sentence->id,
            'to_row_id' => null,
            'index' => 0,
        ]);

    $response->assertOk();
    $this->assertSame([], $response->json('rows.0.a_sentences'));
    $this->assertDatabaseMissing('sentence_meaning_matches', ['entity_sentence_id' => $sentence->id]);
    $this->assertDatabaseHas('entity_sentences', ['id' => $sentence->id, 'order' => 100]);
});

test('cross-row drop at row start stays within the destination row bounds', function () {
    $world = editorWorld([500, 1000, 2000, 3000]);
    $rowU = makeRow($world['match']->id, 100);
    $rowD = makeRow($world['match']->id, 200);
    [$below, $u1, $d1, $d2] = $world['enSentences'];
    linkSentence('a', $u1->id, $rowU->id);
    linkSentence('a', $d1->id, $rowD->id);
    linkSentence('a', $d2->id, $rowD->id);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences/move", [
            'side' => 'a',
            'sentence_id' => $d1->id,
            'to_row_id' => $rowU->id,
            'index' => 0,
        ]);

    $response->assertOk();

    $sentenceOrders = EntitySentence::query()
        ->whereIn('id', [$below->id, $u1->id, $d1->id, $d2->id])
        ->pluck('order', 'id');

    expect($sentenceOrders[$d1->id])->toBe(750);
    expect($sentenceOrders[$d1->id])->toBeGreaterThan($sentenceOrders[$below->id]);
    expect($sentenceOrders[$d1->id])->toBeLessThan($sentenceOrders[$u1->id]);
    expect($sentenceOrders[$u1->id])->toBe(1000);
    expect($sentenceOrders[$d2->id])->toBe(3000);

    $rowUPayload = collect($response->json('rows'))->first(fn (array $row) => $row['id'] === $rowU->id);
    expect(collect($rowUPayload['a_sentences'])->pluck('id')->all())->toBe([$d1->id, $u1->id]);
});

test('moving a sentence back to its previous row restores document order', function () {
    $world = editorWorld([500, 1000, 2000, 5000]);
    $row1 = makeRow($world['match']->id, 100);
    $row2 = makeRow($world['match']->id, 300);
    [$below, $s1, $s2, $above] = $world['enSentences'];
    linkSentence('a', $s1->id, $row1->id);
    linkSentence('a', $s2->id, $row2->id);

    actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences/move", [
            'side' => 'a',
            'sentence_id' => $s2->id,
            'to_row_id' => $row1->id,
            'index' => 0,
        ])->assertOk();

    $midOrders = EntitySentence::query()
        ->whereIn('id', [$s1->id, $s2->id])
        ->pluck('order', 'id');

    expect($midOrders[$s2->id])->toBe(750);
    expect($midOrders[$s2->id])->toBeLessThan($midOrders[$s1->id]);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences/move", [
            'side' => 'a',
            'sentence_id' => $s2->id,
            'to_row_id' => $row2->id,
            'index' => 0,
        ]);

    $response->assertOk();

    $finalOrders = EntitySentence::query()
        ->where('entity_id', $world['en']->id)
        ->pluck('order', 'id');

    expect($finalOrders->values()->unique()->count())->toBe($finalOrders->count());
    expect($finalOrders[$s1->id])->toBe(1000);
    expect($finalOrders[$s2->id])->toBeGreaterThan($finalOrders[$s1->id])
        ->toBeLessThan($finalOrders[$above->id]);

    $row1Payload = collect($response->json('rows'))->first(fn (array $row) => $row['id'] === $row1->id);
    $row2Payload = collect($response->json('rows'))->first(fn (array $row) => $row['id'] === $row2->id);
    expect(collect($row1Payload['a_sentences'])->pluck('id')->all())->toBe([$s1->id]);
    expect(collect($row2Payload['a_sentences'])->pluck('id')->all())->toBe([$s2->id]);
});

test('cross-row spread is bounded by the destination row neighborhood', function () {
    $world = editorWorld([10, 100, 101, 102, 4000, 5000]);
    $rowA = makeRow($world['match']->id, 100);
    $rowB = makeRow($world['match']->id, 200);
    [$low, $a, $b, $c, $anchor, $far] = $world['enSentences'];
    linkSentence('a', $a->id, $rowA->id);
    linkSentence('a', $b->id, $rowA->id);
    linkSentence('a', $c->id, $rowA->id);
    linkSentence('a', $far->id, $rowB->id);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences/move", [
            'side' => 'a',
            'sentence_id' => $far->id,
            'to_row_id' => $rowA->id,
            'index' => 1,
        ]);

    $response->assertOk();

    $sentenceOrders = EntitySentence::query()
        ->whereIn('id', [$a->id, $b->id, $c->id, $far->id])
        ->pluck('order', 'id');

    expect($sentenceOrders[$a->id])->toBeLessThan($sentenceOrders[$far->id]);
    expect($sentenceOrders[$far->id])->toBeLessThan($sentenceOrders[$b->id]);
    expect($sentenceOrders[$b->id])->toBeLessThan($sentenceOrders[$c->id]);

    foreach ([$a->id, $b->id, $c->id, $far->id] as $id) {
        expect($sentenceOrders[$id])->toBeGreaterThan(10)->toBeLessThan(4000);
    }

    $rowAPayload = collect($response->json('rows'))->first(fn (array $row) => $row['id'] === $rowA->id);
    expect(collect($rowAPayload['a_sentences'])->pluck('id')->all())->toBe([$a->id, $far->id, $b->id, $c->id]);
});

test('drop into an empty row with an exhausted gap does not duplicate an order', function () {
    $world = editorWorld([0, 1024, 1025, 9000]);
    [$s1, $s2, $s3, $moved] = $world['enSentences'];
    $r1 = makeRow($world['match']->id, 0);
    $r2 = makeRow($world['match']->id, 1024);
    $r4 = makeRow($world['match']->id, 2048); // empty on the EN side
    $r3 = makeRow($world['match']->id, 3072);
    linkSentence('a', $s1->id, $r1->id);
    linkSentence('a', $s2->id, $r2->id);
    linkSentence('a', $s3->id, $r3->id);

    actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences/move", [
            'side' => 'a',
            'sentence_id' => $moved->id,
            'to_row_id' => $r4->id,
            'index' => 0,
        ])->assertOk();

    $orders = EntitySentence::query()
        ->where('entity_id', $world['en']->id)
        ->pluck('order', 'id');

    expect($orders->values()->unique()->count())->toBe($orders->count());
    expect($orders[$moved->id])->toBeGreaterThan($orders[$s2->id])
        ->toBeLessThan($orders[$s3->id]);
});

test('drop between row sentences does not collide with an interleaved sentence order', function () {
    $world = editorWorld([0, 1000, 2000, 3000, 9000]);
    [$low, $a, $interleaved, $b, $moved] = $world['enSentences'];
    $row = makeRow($world['match']->id, 100);
    linkSentence('a', $a->id, $row->id);
    linkSentence('a', $b->id, $row->id);

    actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences/move", [
            'side' => 'a',
            'sentence_id' => $moved->id,
            'to_row_id' => $row->id,
            'index' => 1,
        ])->assertOk();

    $orders = EntitySentence::query()
        ->where('entity_id', $world['en']->id)
        ->pluck('order', 'id');

    expect($orders->values()->unique()->count())->toBe($orders->count());
    expect($orders[$interleaved->id])->toBe(2000);
    expect($orders[$moved->id])->toBeGreaterThan($orders[$a->id])
        ->toBeLessThan($orders[$b->id]);
});

test('drop at the head of a row sorts before the row without crossing earlier sentences', function () {
    $world = editorWorld([500, 1000, 3000]);
    [$before, $a, $b] = $world['enSentences'];
    $row = makeRow($world['match']->id, 100);
    linkSentence('a', $a->id, $row->id);
    linkSentence('a', $b->id, $row->id);

    actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences/move", [
            'side' => 'a',
            'sentence_id' => $b->id,
            'to_row_id' => $row->id,
            'index' => 0,
        ])->assertOk();

    $orders = EntitySentence::query()
        ->where('entity_id', $world['en']->id)
        ->pluck('order', 'id');

    expect($orders->values()->unique()->count())->toBe($orders->count());
    expect($orders[$b->id])->toBeGreaterThan($orders[$before->id])
        ->toBeLessThan($orders[$a->id]);
});

test('repeated moves keep sentence orders unique', function () {
    $world = editorWorld([100, 200, 300, 400, 500]);
    [$a, $b, $c, $d, $e] = $world['enSentences'];
    $rowA = makeRow($world['match']->id, 100);
    $rowB = makeRow($world['match']->id, 200);
    linkSentence('a', $a->id, $rowA->id);
    linkSentence('a', $b->id, $rowA->id);
    linkSentence('a', $c->id, $rowB->id);

    $moves = [
        ['sentence_id' => $d->id, 'to_row_id' => $rowB->id, 'index' => 0],
        ['sentence_id' => $e->id, 'to_row_id' => $rowA->id, 'index' => 1],
        ['sentence_id' => $d->id, 'to_row_id' => $rowA->id, 'index' => 2],
        ['sentence_id' => $b->id, 'to_row_id' => $rowB->id, 'index' => 1],
        ['sentence_id' => $a->id, 'to_row_id' => $rowB->id, 'index' => 0],
        ['sentence_id' => $e->id, 'to_row_id' => $rowB->id, 'index' => 2],
    ];

    foreach ($moves as $move) {
        actingAs(User::factory()->create())
            ->postJson("/alignments/{$world['match']->id}/sentences/move", [
                'side' => 'a',
                ...$move,
            ])->assertOk();

        $orders = EntitySentence::query()
            ->where('entity_id', $world['en']->id)
            ->pluck('order', 'id');

        expect($orders->values()->unique()->count())->toBe($orders->count());
    }
});

test('adding a sentence into an exhausted order gap rebalances without violating the unique index', function () {
    $world = editorWorld([0, 1, 2, 9000]);
    [$a, $b, $unmatched, $far] = $world['enSentences'];
    $row = makeRow($world['match']->id, 100);
    linkSentence('a', $a->id, $row->id);
    linkSentence('a', $b->id, $row->id);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences", [
            'side' => 'a',
            'content' => 'New sentence.',
            'meaning_match_id' => $row->id,
        ]);

    $response->assertOk();

    $orders = EntitySentence::query()
        ->where('entity_id', $world['en']->id)
        ->pluck('order', 'id');

    expect($orders->values()->unique()->count())->toBe($orders->count());
    expect($orders[$b->id])->toBeGreaterThan($orders[$a->id]);
    expect($unmatched->refresh()->order)->toBeGreaterThan($orders[$b->id]);
});

test('hard deletes an unmatched sentence', function () {
    $world = editorWorld([100]);
    $sentence = $world['enSentences'][0];

    $response = actingAs(User::factory()->create())
        ->deleteJson("/alignments/{$world['match']->id}/unmatched/{$sentence->id}", ['side' => 'a']);

    $response->assertOk();
    $this->assertDatabaseMissing('entity_sentences', ['id' => $sentence->id]);
    expect($response->json('unmatched_changed'))->toContain('a');
    $this->assertSame(0, $response->json('match.a_total_sentences'));
});

test('rejects hard delete of a linked sentence', function () {
    $world = editorWorld([100]);
    $row = makeRow($world['match']->id, 100);
    $sentence = $world['enSentences'][0];
    linkSentence('a', $sentence->id, $row->id);

    actingAs(User::factory()->create())
        ->deleteJson("/alignments/{$world['match']->id}/unmatched/{$sentence->id}", ['side' => 'a'])
        ->assertUnprocessable();
});

test('rows endpoint paginates', function () {
    $world = editorWorld();
    for ($i = 1; $i <= 15; $i++) {
        makeRow($world['match']->id, $i * 10);
    }

    $response = actingAs(User::factory()->create())
        ->getJson("/alignments/{$world['match']->id}/rows?page=2&per_page=10");

    $response->assertOk();
    expect($response->json('rows'))->toHaveCount(5);
    $this->assertSame(15, $response->json('meta.total'));
    $this->assertSame(2, $response->json('meta.last_page'));
    $this->assertSame(2, $response->json('meta.current_page'));
    $this->assertSame($world['match']->id, $response->json('match.id'));
});

test('unmatched endpoint paginates and reports last_page', function () {
    $world = editorWorld(range(100, 116));

    $pageOne = actingAs(User::factory()->create())
        ->getJson("/alignments/{$world['match']->id}/unmatched?side=a&page=1")
        ->assertOk()
        ->json();

    expect($pageOne['items'])->toHaveCount(15);
    $this->assertSame(17, $pageOne['meta']['total']);
    $this->assertSame(2, $pageOne['meta']['last_page']);
    $this->assertSame(1, $pageOne['meta']['current_page']);
    $this->assertSame(15, $pageOne['meta']['per_page']);

    $pageTwo = actingAs(User::factory()->create())
        ->getJson("/alignments/{$world['match']->id}/unmatched?side=a&page=2")
        ->assertOk()
        ->json();

    expect($pageTwo['items'])->toHaveCount(2);
});

test('linked_count reflects pair count after create and delete', function () {
    $world = editorWorld([100], [100]);
    $created = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/rows", [])
        ->assertOk()
        ->json('rows.0');

    $rowId = $created['id'];

    actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences", [
            'side' => 'a',
            'meaning_match_id' => $rowId,
            'content' => 'EN link',
        ])
        ->assertOk();

    actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences", [
            'side' => 'b',
            'meaning_match_id' => $rowId,
            'content' => 'RU link',
        ])
        ->assertOk();

    $this->assertSame(1, EntityMatch::find($world['match']->id)->linked_count);

    actingAs(User::factory()->create())
        ->deleteJson("/alignments/{$world['match']->id}/rows/{$rowId}")
        ->assertOk();

    $this->assertSame(0, EntityMatch::find($world['match']->id)->linked_count);
});

test('needs-review lists low-similarity and one-sided matches', function () {
    $world = editorWorld([100, 200, 300], [100, 200, 300]);
    $en = $world['enSentences'];
    $ru = $world['ruSentences'];

    $good = makeRow($world['match']->id, 100, 0.9);
    $low = makeRow($world['match']->id, 200, 0.4);
    $enOnly = makeRow($world['match']->id, 300, 0.9);
    $ruOnly = makeRow($world['match']->id, 400, 0.8);
    $empty = makeRow($world['match']->id, 500, 1.0);

    linkSentence('a', $en[0]->id, $good->id);
    linkSentence('b', $ru[0]->id, $good->id);
    linkSentence('a', $en[1]->id, $low->id);
    linkSentence('b', $ru[1]->id, $low->id);
    linkSentence('a', $en[2]->id, $enOnly->id);
    linkSentence('b', $ru[2]->id, $ruOnly->id);

    $response = actingAs(User::factory()->create())
        ->getJson("/alignments/{$world['match']->id}/needs-review")
        ->assertOk();

    $items = $response->json('items');
    $ids = collect($items)->pluck('id')->all();

    expect($ids)->toContain($low->id);
    expect($ids)->toContain($enOnly->id);
    expect($ids)->toContain($ruOnly->id);
    expect($ids)->not->toContain($good->id);
    expect($ids)->not->toContain($empty->id);

    $lowItem = collect($items)->firstWhere('id', $low->id);
    $this->assertSame(0.4, (float) $lowItem['similarity']);
    $this->assertFalse($lowItem['one_sided']);
    $this->assertSame('EN 200', $lowItem['a_part']);
    $this->assertSame('RU 200', $lowItem['b_part']);

    $enOnlyItem = collect($items)->firstWhere('id', $enOnly->id);
    $this->assertTrue($enOnlyItem['one_sided']);
    $this->assertSame('', $enOnlyItem['b_part']);
});

test('needs-review ranks rows among all meaning matches', function () {
    $world = editorWorld([100, 200, 300], [100, 200]);
    $en = $world['enSentences'];
    $ru = $world['ruSentences'];

    makeRow($world['match']->id, 100, 0.9);
    $second = makeRow($world['match']->id, 200, 0.3);
    makeRow($world['match']->id, 300, 0.9);
    $fourth = makeRow($world['match']->id, 400, 0.5);

    linkSentence('a', $en[0]->id, $second->id);
    linkSentence('b', $ru[0]->id, $second->id);
    linkSentence('a', $en[1]->id, $fourth->id);
    linkSentence('b', $ru[1]->id, $fourth->id);

    $items = actingAs(User::factory()->create())
        ->getJson("/alignments/{$world['match']->id}/needs-review")
        ->assertOk()
        ->json('items');

    $byId = collect($items)->keyBy('id');

    $this->assertSame(2, $byId[$second->id]['rank']);
    $this->assertSame(4, $byId[$fourth->id]['rank']);
});

test('needs-review endpoint paginates', function () {
    $world = editorWorld(range(100, 130), range(100, 130));
    $en = $world['enSentences'];
    $ru = $world['ruSentences'];

    $rows = [];

    for ($i = 0; $i < 31; $i++) {
        $rows[$i] = makeRow($world['match']->id, ($i + 1) * 10, 0.3);
        linkSentence('a', $en[$i]->id, $rows[$i]->id);
        linkSentence('b', $ru[$i]->id, $rows[$i]->id);
    }

    $pageOne = actingAs(User::factory()->create())
        ->getJson("/alignments/{$world['match']->id}/needs-review?page=1")
        ->assertOk()
        ->json();

    expect($pageOne['items'])->toHaveCount(25);
    $this->assertSame(31, $pageOne['meta']['total']);
    $this->assertSame(2, $pageOne['meta']['last_page']);
    $this->assertSame(1, $pageOne['meta']['current_page']);
    $this->assertSame(25, $pageOne['meta']['per_page']);

    $pageTwo = actingAs(User::factory()->create())
        ->getJson("/alignments/{$world['match']->id}/needs-review?page=2")
        ->assertOk()
        ->json();

    expect($pageTwo['items'])->toHaveCount(6);
    $this->assertSame(2, $pageTwo['meta']['current_page']);
});

test('new sentence in empty row gets correct order when preceding row has high-order dragged sentence', function () {
    $world = editorWorld([0, 100000, 2048, 3072]);
    $first = makeRow($world['match']->id, 100);
    $second = makeRow($world['match']->id, 200);
    $third = makeRow($world['match']->id, 300);

    linkSentence('a', $world['enSentences'][0]->id, $first->id);
    linkSentence('a', $world['enSentences'][1]->id, $first->id);
    linkSentence('a', $world['enSentences'][2]->id, $third->id);
    linkSentence('a', $world['enSentences'][3]->id, $third->id);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences", [
            'side' => 'a',
            'meaning_match_id' => $second->id,
            'content' => 'New sentence in the empty row.',
        ]);

    $response->assertOk();

    $allSentences = collect($response->json('rows'))->pluck('a_sentences')->flatten(1);
    $newSentence = $allSentences->firstWhere('content', 'New sentence in the empty row.');
    $this->assertNotNull($newSentence, 'New sentence not found in response rows');

    $order = $newSentence['order'];
    $this->assertGreaterThan(0, $order);
    $this->assertLessThan(100000, $order, "Order {$order} must stay below the high-order dragged sentence (100000)");
    $this->assertLessThan(2048, $order, "Order {$order} must stay below the next row's sentences");
});
