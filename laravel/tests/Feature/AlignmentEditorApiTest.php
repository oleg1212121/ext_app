<?php

use App\Models\EnEntity;
use App\Models\EnEntitySentence;
use App\Models\EnRuEntityMatch;
use App\Models\EnRuMeaningMatch;
use App\Models\EnSentenceMeaningMatch;
use App\Models\RuEntity;
use App\Models\RuEntitySentence;
use App\Models\RuSentenceMeaningMatch;
use App\Models\SentenceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function editorWorld(array $enOrders = [], array $ruOrders = []): array
{
    $type = SentenceType::create(['name' => 'sentence']);
    $en = EnEntity::create(['name' => 'En text']);
    $ru = RuEntity::create(['name' => 'Ru text']);

    $match = EnRuEntityMatch::create([
        'en_entity_id' => $en->id,
        'ru_entity_id' => $ru->id,
        'status' => 'pending',
    ]);

    $enSentences = [];
    foreach ($enOrders as $order) {
        $enSentences[] = EnEntitySentence::create([
            'en_entity_id' => $en->id,
            'sentence_type_id' => $type->id,
            'content' => "EN {$order}",
            'order' => $order,
        ]);
    }

    $ruSentences = [];
    foreach ($ruOrders as $order) {
        $ruSentences[] = RuEntitySentence::create([
            'ru_entity_id' => $ru->id,
            'sentence_type_id' => $type->id,
            'content' => "RU {$order}",
            'order' => $order,
        ]);
    }

    return compact('match', 'type', 'en', 'ru', 'enSentences', 'ruSentences');
}

function makeRow(int $matchId, int $order, float $similarity = 0.9): EnRuMeaningMatch
{
    return EnRuMeaningMatch::create([
        'en_ru_entity_match_id' => $matchId,
        'order' => $order,
        'similarity' => $similarity,
        'alignment_chunk' => 0,
    ]);
}

function linkSentence(string $lang, int $sentenceId, int $rowId): void
{
    if ($lang === 'en') {
        EnSentenceMeaningMatch::create([
            'en_entity_sentence_id' => $sentenceId,
            'en_ru_meaning_match_id' => $rowId,
        ]);

        return;
    }

    RuSentenceMeaningMatch::create([
        'ru_entity_sentence_id' => $sentenceId,
        'en_ru_meaning_match_id' => $rowId,
    ]);
}

test('guests cannot call editor endpoints', function () {
    $world = editorWorld();

    $this->postJson("/alignments/{$world['match']->id}/rows", [])
        ->assertUnauthorized();

    $this->getJson("/alignments/{$world['match']->id}/rows")
        ->assertUnauthorized();

    $this->getJson("/alignments/{$world['match']->id}/unmatched?lang=en")
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
    $this->assertDatabaseHas('en_ru_meaning_matches', [
        'id' => $row->id,
        'similarity' => 1.0,
        'alignment_chunk' => -1,
    ]);
});

test('cannot approve a row belonging to another entity match', function () {
    $world = editorWorld();
    $otherMatch = EnRuEntityMatch::create([
        'en_entity_id' => EnEntity::create(['name' => 'Other En'])->id,
        'ru_entity_id' => RuEntity::create(['name' => 'Other Ru'])->id,
        'status' => 'pending',
    ]);
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
    $this->assertSame([], $row['en_sentences']);
    $this->assertSame([], $row['ru_sentences']);
    $this->assertGreaterThan(100, $row['order']);
    $this->assertLessThan(200, $row['order']);

    $this->assertSame(3, $response->json('match.linked_count'));

    $this->assertDatabaseHas('en_ru_meaning_matches', ['id' => $row['id']]);
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
    $this->assertDatabaseMissing('en_ru_meaning_matches', ['id' => $row->id]);
});

test('deleting a non-empty pair unlinks its sentences to unmatched', function () {
    $world = editorWorld([100], [100]);
    $row = makeRow($world['match']->id, 100);
    linkSentence('en', $world['enSentences'][0]->id, $row->id);
    linkSentence('ru', $world['ruSentences'][0]->id, $row->id);

    $response = actingAs(User::factory()->create())
        ->deleteJson("/alignments/{$world['match']->id}/rows/{$row->id}");

    $response->assertOk();
    $this->assertSame([$row->id], $response->json('deleted_rows'));
    expect($response->json('unmatched_changed'))->toContain('en')->toContain('ru');
    $this->assertDatabaseMissing('en_ru_meaning_matches', ['id' => $row->id]);
    $this->assertDatabaseMissing('en_sentence_meaning_matches', ['en_ru_meaning_match_id' => $row->id]);
    $this->assertDatabaseMissing('ru_sentence_meaning_matches', ['en_ru_meaning_match_id' => $row->id]);
    $this->assertDatabaseHas('en_entity_sentences', ['id' => $world['enSentences'][0]->id]);
    $this->assertDatabaseHas('ru_entity_sentences', ['id' => $world['ruSentences'][0]->id]);

    $unmatched = actingAs(User::factory()->create())
        ->getJson("/alignments/{$world['match']->id}/unmatched?lang=en")
        ->assertOk()
        ->json();

    expect(collect($unmatched['items'])->pluck('id'))->toContain($world['enSentences'][0]->id);
});

test('adds a sentence to a row after the last sentence of that row', function () {
    $world = editorWorld([100, 150]);
    $row = makeRow($world['match']->id, 100);
    linkSentence('en', $world['enSentences'][0]->id, $row->id);
    linkSentence('en', $world['enSentences'][1]->id, $row->id);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences", [
            'lang' => 'en',
            'meaning_match_id' => $row->id,
            'content' => 'A brand new sentence.',
        ]);

    $response->assertOk();

    $sentences = $response->json('rows.0.en_sentences');
    $this->assertCount(3, $sentences);
    $this->assertSame('A brand new sentence.', $sentences[2]['content']);
    $this->assertGreaterThan(150, $sentences[2]['order']);

    $this->assertDatabaseHas('en_sentence_meaning_matches', [
        'en_entity_sentence_id' => $sentences[2]['id'],
        'en_ru_meaning_match_id' => $row->id,
    ]);

    $this->assertSame(3, $response->json('match.en_total_sentences'));
});

test('a new sentence is placed at the document boundary', function () {
    $world = editorWorld([100, 150, 200]);
    $row = makeRow($world['match']->id, 100);
    [$a, $b, $c] = $world['enSentences'];
    linkSentence('en', $a->id, $row->id);
    linkSentence('en', $b->id, $row->id);
    linkSentence('en', $c->id, $row->id);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences", [
            'lang' => 'en',
            'meaning_match_id' => $row->id,
            'content' => 'A brand new sentence.',
        ]);

    $response->assertOk();

    $sentences = $response->json('rows.0.en_sentences');
    $this->assertCount(4, $sentences);
    $newId = $sentences[3]['id'];

    $newSentence = EnEntitySentence::query()->whereKey($newId)->firstOrFail();
    $this->assertGreaterThan(200, $newSentence->order);

    $this->assertDatabaseHas('en_sentence_meaning_matches', [
        'en_entity_sentence_id' => $newId,
        'en_ru_meaning_match_id' => $row->id,
    ]);

    $allSentenceIds = EnSentenceMeaningMatch::query()
        ->where('en_ru_meaning_match_id', $row->id)
        ->get()
        ->map(fn ($match) => $match->enEntitySentence?->order ?? 0)
        ->sort()
        ->values()
        ->all();

    $this->assertSame($allSentenceIds, collect($allSentenceIds)->sort()->values()->all());
});

test('adds a sentence to an empty row after the previous row last sentence', function () {
    $world = editorWorld([100]);
    $first = makeRow($world['match']->id, 100);
    $second = makeRow($world['match']->id, 200);
    linkSentence('en', $world['enSentences'][0]->id, $first->id);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences", [
            'lang' => 'en',
            'meaning_match_id' => $second->id,
            'content' => 'Fills the empty row.',
        ]);

    $response->assertOk();
    $sentences = $response->json('rows.0.en_sentences');
    $this->assertCount(1, $sentences);
    $this->assertGreaterThan(100, $sentences[0]['order']);
});

test('adds a sentence to a row with no prior sentences and gets a non-negative order', function () {
    $world = editorWorld([100]);
    $row = makeRow($world['match']->id, 100);
    linkSentence('en', $world['enSentences'][0]->id, $row->id);

    $newRow = makeRow($world['match']->id, 200);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences", [
            'lang' => 'en',
            'meaning_match_id' => $newRow->id,
            'content' => 'First EN sentence in an empty row with no prior RU rows.',
        ]);

    $response->assertOk();
    $sentences = $response->json('rows.0.en_sentences');
    $this->assertCount(1, $sentences);
    $this->assertGreaterThanOrEqual(0, $sentences[0]['order']);
});

test('adds the very first sentence of a language and gets a non-negative order', function () {
    $world = editorWorld([], []);
    $row = makeRow($world['match']->id, 100);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences", [
            'lang' => 'en',
            'meaning_match_id' => $row->id,
            'content' => 'The very first EN sentence.',
        ]);

    $response->assertOk();
    $sentences = $response->json('rows.0.en_sentences');
    $this->assertCount(1, $sentences);
    $this->assertGreaterThanOrEqual(0, $sentences[0]['order']);
});

test('rejects empty sentence content', function () {
    $world = editorWorld([100]);
    $row = makeRow($world['match']->id, 100);
    $sentence = $world['enSentences'][0];
    linkSentence('en', $sentence->id, $row->id);

    actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences", [
            'lang' => 'en',
            'meaning_match_id' => $row->id,
            'content' => '   ',
        ])
        ->assertUnprocessable();

    actingAs(User::factory()->create())
        ->patchJson("/alignments/{$world['match']->id}/sentences/{$sentence->id}", [
            'lang' => 'en',
            'content' => '',
        ])
        ->assertUnprocessable();
});

test('edits sentence content', function () {
    $world = editorWorld([100]);
    $row = makeRow($world['match']->id, 100);
    $sentence = $world['enSentences'][0];
    linkSentence('en', $sentence->id, $row->id);

    $response = actingAs(User::factory()->create())
        ->patchJson("/alignments/{$world['match']->id}/sentences/{$sentence->id}", [
            'lang' => 'en',
            'content' => 'Edited content.',
        ]);

    $response->assertOk();
    $this->assertSame('Edited content.', $response->json('rows.0.en_sentences.0.content'));
    $this->assertDatabaseHas('en_entity_sentences', ['id' => $sentence->id, 'content' => 'Edited content.']);
});

test('unlinks a sentence to unmatched', function () {
    $world = editorWorld([100]);
    $row = makeRow($world['match']->id, 100);
    $sentence = $world['enSentences'][0];
    linkSentence('en', $sentence->id, $row->id);

    $response = actingAs(User::factory()->create())
        ->deleteJson("/alignments/{$world['match']->id}/sentences/{$sentence->id}", ['lang' => 'en']);

    $response->assertOk();
    $this->assertSame([], $response->json('rows.0.en_sentences'));
    expect($response->json('unmatched_changed'))->toContain('en');
    $this->assertDatabaseMissing('en_sentence_meaning_matches', ['en_entity_sentence_id' => $sentence->id]);
});

test('moves a sentence within a row to reorder it', function () {
    $world = editorWorld([213, 240, 250]);
    $row = makeRow($world['match']->id, 100);
    [$a, $b, $c] = $world['enSentences'];
    linkSentence('en', $a->id, $row->id);
    linkSentence('en', $b->id, $row->id);
    linkSentence('en', $c->id, $row->id);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences/move", [
            'lang' => 'en',
            'sentence_id' => $c->id,
            'to_row_id' => $row->id,
            'index' => 1,
        ]);

    $response->assertOk();

    $sentenceIdsByDocumentOrder = EnSentenceMeaningMatch::query()
        ->where('en_ru_meaning_match_id', $row->id)
        ->get()
        ->map(fn ($match) => [
            'id' => $match->en_entity_sentence_id,
            'order' => $match->enEntitySentence?->order ?? 0,
        ])
        ->sortBy('order')
        ->pluck('id')
        ->all();

    expect($sentenceIdsByDocumentOrder)->toBe([$a->id, $c->id, $b->id]);

    $sentenceOrders = EnEntitySentence::query()
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
    linkSentence('en', $a->id, $row->id);
    linkSentence('en', $b->id, $row->id);
    linkSentence('en', $c->id, $row->id);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences/move", [
            'lang' => 'en',
            'sentence_id' => $c->id,
            'to_row_id' => $row->id,
            'index' => 1,
        ]);

    $response->assertOk();

    $sentenceOrders = EnEntitySentence::query()
        ->whereIn('id', [$a->id, $b->id, $c->id])
        ->pluck('order', 'id')
        ->all();

    $allOrders = EnEntitySentence::query()
        ->where('en_entity_id', $world['en']->id)
        ->pluck('order')
        ->sort()
        ->values()
        ->all();

    $indexOfA = array_search($sentenceOrders[$a->id], $allOrders, true);
    $indexOfB = array_search($sentenceOrders[$b->id], $allOrders, true);
    $indexOfC = array_search($sentenceOrders[$c->id], $allOrders, true);

    expect($indexOfA)->toBeLessThan($indexOfC);
    expect($indexOfC)->toBeLessThan($indexOfB);

    expect($sentenceOrders[$c->id])->toBeGreaterThan(5);
    expect($sentenceOrders[$c->id])->toBeLessThan(50);
});

test('moves a sentence from one row to another', function () {
    $world = editorWorld([10, 100, 110, 500]);
    $rowA = makeRow($world['match']->id, 100);
    $rowB = makeRow($world['match']->id, 200);
    [$low, $a, $b, $high] = $world['enSentences'];
    linkSentence('en', $a->id, $rowA->id);
    linkSentence('en', $b->id, $rowB->id);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences/move", [
            'lang' => 'en',
            'sentence_id' => $a->id,
            'to_row_id' => $rowB->id,
            'index' => 1,
        ]);

    $response->assertOk();

    $rowAIds = $response->json('rows.0.en_sentences');
    $rowBIds = $response->json('rows.1.en_sentences');

    $this->assertSame([], $rowAIds);
    expect(collect($rowBIds)->pluck('id')->all())->toBe([$b->id, $a->id]);
    $this->assertDatabaseMissing('en_sentence_meaning_matches', ['en_entity_sentence_id' => $a->id, 'en_ru_meaning_match_id' => $rowA->id]);
    $this->assertDatabaseHas('en_sentence_meaning_matches', ['en_entity_sentence_id' => $a->id, 'en_ru_meaning_match_id' => $rowB->id]);

    $sentenceOrders = EnEntitySentence::query()
        ->whereIn('id', [$a->id, $b->id])
        ->pluck('order', 'id');

    expect($sentenceOrders[$b->id])->toBe(110);
    expect($sentenceOrders[$a->id])->toBeGreaterThan(110)->toBeLessThan(500);
});

test('moves a sentence from unmatched into a row', function () {
    $world = editorWorld([100, 300, 900]);
    $row = makeRow($world['match']->id, 100);
    [$a, $unmatched, $high] = $world['enSentences'];
    linkSentence('en', $a->id, $row->id);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences/move", [
            'lang' => 'en',
            'sentence_id' => $unmatched->id,
            'to_row_id' => $row->id,
            'index' => 1,
        ]);

    $response->assertOk();

    $sentences = $response->json('rows.0.en_sentences');
    expect(collect($sentences)->pluck('id')->all())->toBe([$a->id, $unmatched->id]);
    $this->assertDatabaseHas('en_sentence_meaning_matches', ['en_entity_sentence_id' => $unmatched->id, 'en_ru_meaning_match_id' => $row->id]);
    $this->assertDatabaseHas('en_entity_sentences', ['id' => $unmatched->id, 'order' => 500]);
});

test('moves a sentence from a row out to unmatched', function () {
    $world = editorWorld([100]);
    $row = makeRow($world['match']->id, 100);
    $sentence = $world['enSentences'][0];
    linkSentence('en', $sentence->id, $row->id);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences/move", [
            'lang' => 'en',
            'sentence_id' => $sentence->id,
            'to_row_id' => null,
            'index' => 0,
        ]);

    $response->assertOk();
    $this->assertSame([], $response->json('rows.0.en_sentences'));
    $this->assertDatabaseMissing('en_sentence_meaning_matches', ['en_entity_sentence_id' => $sentence->id]);
    $this->assertDatabaseHas('en_entity_sentences', ['id' => $sentence->id, 'order' => 100]);
});

test('cross-row drop at row start stays within the destination row bounds', function () {
    $world = editorWorld([500, 1000, 2000, 3000]);
    $rowU = makeRow($world['match']->id, 100);
    $rowD = makeRow($world['match']->id, 200);
    [$below, $u1, $d1, $d2] = $world['enSentences'];
    linkSentence('en', $u1->id, $rowU->id);
    linkSentence('en', $d1->id, $rowD->id);
    linkSentence('en', $d2->id, $rowD->id);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences/move", [
            'lang' => 'en',
            'sentence_id' => $d1->id,
            'to_row_id' => $rowU->id,
            'index' => 0,
        ]);

    $response->assertOk();

    $sentenceOrders = EnEntitySentence::query()
        ->whereIn('id', [$below->id, $u1->id, $d1->id, $d2->id])
        ->pluck('order', 'id');

    expect($sentenceOrders[$d1->id])->toBe(750);
    expect($sentenceOrders[$d1->id])->toBeGreaterThan($sentenceOrders[$below->id]);
    expect($sentenceOrders[$d1->id])->toBeLessThan($sentenceOrders[$u1->id]);
    expect($sentenceOrders[$u1->id])->toBe(1000);
    expect($sentenceOrders[$d2->id])->toBe(3000);

    $rowUPayload = collect($response->json('rows'))->first(fn (array $row) => $row['id'] === $rowU->id);
    expect(collect($rowUPayload['en_sentences'])->pluck('id')->all())->toBe([$d1->id, $u1->id]);
});

test('moving a sentence back to its previous row restores document order', function () {
    $world = editorWorld([500, 1000, 2000, 5000]);
    $row1 = makeRow($world['match']->id, 100);
    $row2 = makeRow($world['match']->id, 300);
    [$below, $s1, $s2, $above] = $world['enSentences'];
    linkSentence('en', $s1->id, $row1->id);
    linkSentence('en', $s2->id, $row2->id);

    actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences/move", [
            'lang' => 'en',
            'sentence_id' => $s2->id,
            'to_row_id' => $row1->id,
            'index' => 0,
        ])->assertOk();

    $midOrders = EnEntitySentence::query()
        ->whereIn('id', [$s1->id, $s2->id])
        ->pluck('order', 'id');

    expect($midOrders[$s2->id])->toBe(750);
    expect($midOrders[$s2->id])->toBeLessThan($midOrders[$s1->id]);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences/move", [
            'lang' => 'en',
            'sentence_id' => $s2->id,
            'to_row_id' => $row2->id,
            'index' => 0,
        ]);

    $response->assertOk();

    $finalOrders = EnEntitySentence::query()
        ->whereIn('id', [$s1->id, $s2->id])
        ->pluck('order', 'id');

    expect($finalOrders[$s1->id])->toBe(1000);
    expect($finalOrders[$s2->id])->toBe(2024);
    expect($finalOrders[$s2->id])->toBeGreaterThan($finalOrders[$s1->id]);

    $row1Payload = collect($response->json('rows'))->first(fn (array $row) => $row['id'] === $row1->id);
    $row2Payload = collect($response->json('rows'))->first(fn (array $row) => $row['id'] === $row2->id);
    expect(collect($row1Payload['en_sentences'])->pluck('id')->all())->toBe([$s1->id]);
    expect(collect($row2Payload['en_sentences'])->pluck('id')->all())->toBe([$s2->id]);
});

test('cross-row spread is bounded by the destination row neighborhood', function () {
    $world = editorWorld([10, 100, 101, 102, 4000, 5000]);
    $rowA = makeRow($world['match']->id, 100);
    $rowB = makeRow($world['match']->id, 200);
    [$low, $a, $b, $c, $anchor, $far] = $world['enSentences'];
    linkSentence('en', $a->id, $rowA->id);
    linkSentence('en', $b->id, $rowA->id);
    linkSentence('en', $c->id, $rowA->id);
    linkSentence('en', $far->id, $rowB->id);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences/move", [
            'lang' => 'en',
            'sentence_id' => $far->id,
            'to_row_id' => $rowA->id,
            'index' => 1,
        ]);

    $response->assertOk();

    $sentenceOrders = EnEntitySentence::query()
        ->whereIn('id', [$a->id, $b->id, $c->id, $far->id])
        ->pluck('order', 'id');

    expect($sentenceOrders[$a->id])->toBeLessThan($sentenceOrders[$far->id]);
    expect($sentenceOrders[$far->id])->toBeLessThan($sentenceOrders[$b->id]);
    expect($sentenceOrders[$b->id])->toBeLessThan($sentenceOrders[$c->id]);

    foreach ([$a->id, $b->id, $c->id, $far->id] as $id) {
        expect($sentenceOrders[$id])->toBeGreaterThan(10)->toBeLessThan(4000);
    }

    $rowAPayload = collect($response->json('rows'))->first(fn (array $row) => $row['id'] === $rowA->id);
    expect(collect($rowAPayload['en_sentences'])->pluck('id')->all())->toBe([$a->id, $far->id, $b->id, $c->id]);
});

test('hard deletes an unmatched sentence', function () {
    $world = editorWorld([100]);
    $sentence = $world['enSentences'][0];

    $response = actingAs(User::factory()->create())
        ->deleteJson("/alignments/{$world['match']->id}/unmatched/{$sentence->id}", ['lang' => 'en']);

    $response->assertOk();
    $this->assertDatabaseMissing('en_entity_sentences', ['id' => $sentence->id]);
    expect($response->json('unmatched_changed'))->toContain('en');
    $this->assertSame(0, $response->json('match.en_total_sentences'));
});

test('rejects hard delete of a linked sentence', function () {
    $world = editorWorld([100]);
    $row = makeRow($world['match']->id, 100);
    $sentence = $world['enSentences'][0];
    linkSentence('en', $sentence->id, $row->id);

    actingAs(User::factory()->create())
        ->deleteJson("/alignments/{$world['match']->id}/unmatched/{$sentence->id}", ['lang' => 'en'])
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
        ->getJson("/alignments/{$world['match']->id}/unmatched?lang=en&page=1")
        ->assertOk()
        ->json();

    expect($pageOne['items'])->toHaveCount(15);
    $this->assertSame(17, $pageOne['meta']['total']);
    $this->assertSame(2, $pageOne['meta']['last_page']);
    $this->assertSame(1, $pageOne['meta']['current_page']);
    $this->assertSame(15, $pageOne['meta']['per_page']);

    $pageTwo = actingAs(User::factory()->create())
        ->getJson("/alignments/{$world['match']->id}/unmatched?lang=en&page=2")
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
            'lang' => 'en',
            'meaning_match_id' => $rowId,
            'content' => 'EN link',
        ])
        ->assertOk();

    actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences", [
            'lang' => 'ru',
            'meaning_match_id' => $rowId,
            'content' => 'RU link',
        ])
        ->assertOk();

    $this->assertSame(1, EnRuEntityMatch::find($world['match']->id)->linked_count);

    actingAs(User::factory()->create())
        ->deleteJson("/alignments/{$world['match']->id}/rows/{$rowId}")
        ->assertOk();

    $this->assertSame(0, EnRuEntityMatch::find($world['match']->id)->linked_count);
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

    linkSentence('en', $en[0]->id, $good->id);
    linkSentence('ru', $ru[0]->id, $good->id);
    linkSentence('en', $en[1]->id, $low->id);
    linkSentence('ru', $ru[1]->id, $low->id);
    linkSentence('en', $en[2]->id, $enOnly->id);
    linkSentence('ru', $ru[2]->id, $ruOnly->id);

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
    $this->assertSame('EN 200', $lowItem['en_part']);
    $this->assertSame('RU 200', $lowItem['ru_part']);

    $enOnlyItem = collect($items)->firstWhere('id', $enOnly->id);
    $this->assertTrue($enOnlyItem['one_sided']);
    $this->assertSame('', $enOnlyItem['ru_part']);
});

test('needs-review ranks rows among all meaning matches', function () {
    $world = editorWorld([100, 200, 300], [100, 200]);
    $en = $world['enSentences'];
    $ru = $world['ruSentences'];

    makeRow($world['match']->id, 100, 0.9);
    $second = makeRow($world['match']->id, 200, 0.3);
    makeRow($world['match']->id, 300, 0.9);
    $fourth = makeRow($world['match']->id, 400, 0.5);

    linkSentence('en', $en[0]->id, $second->id);
    linkSentence('ru', $ru[0]->id, $second->id);
    linkSentence('en', $en[1]->id, $fourth->id);
    linkSentence('ru', $ru[1]->id, $fourth->id);

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
        linkSentence('en', $en[$i]->id, $rows[$i]->id);
        linkSentence('ru', $ru[$i]->id, $rows[$i]->id);
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

    linkSentence('en', $world['enSentences'][0]->id, $first->id);
    linkSentence('en', $world['enSentences'][1]->id, $first->id);
    linkSentence('en', $world['enSentences'][2]->id, $third->id);
    linkSentence('en', $world['enSentences'][3]->id, $third->id);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences", [
            'lang' => 'en',
            'meaning_match_id' => $second->id,
            'content' => 'New sentence in the empty row.',
        ]);

    $response->assertOk();

    $allSentences = collect($response->json('rows'))->pluck('en_sentences')->flatten(1);
    $newSentence = $allSentences->firstWhere('content', 'New sentence in the empty row.');
    $this->assertNotNull($newSentence, 'New sentence not found in response rows');

    $order = $newSentence['order'];
    $this->assertGreaterThan(0, $order);
    $this->assertLessThan(100000, $order, "Order {$order} must stay below the high-order dragged sentence (100000)");
    $this->assertLessThan(2048, $order, "Order {$order} must stay below the next row's sentences");
});
