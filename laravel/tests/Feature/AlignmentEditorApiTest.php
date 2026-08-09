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

function linkSentence(string $lang, int $sentenceId, int $rowId, int $order): void
{
    if ($lang === 'en') {
        EnSentenceMeaningMatch::create([
            'en_entity_sentence_id' => $sentenceId,
            'en_ru_meaning_match_id' => $rowId,
            'order' => $order,
        ]);

        return;
    }

    RuSentenceMeaningMatch::create([
        'ru_entity_sentence_id' => $sentenceId,
        'en_ru_meaning_match_id' => $rowId,
        'order' => $order,
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
    linkSentence('en', $world['enSentences'][0]->id, $row->id, 100);
    linkSentence('ru', $world['ruSentences'][0]->id, $row->id, 100);

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
    linkSentence('en', $world['enSentences'][0]->id, $row->id, 100);
    linkSentence('en', $world['enSentences'][1]->id, $row->id, 150);

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
        'order' => $sentences[2]['order'],
    ]);

    $this->assertSame(3, $response->json('match.en_total_sentences'));
});

test('adds a sentence to an empty row after the previous row last sentence', function () {
    $world = editorWorld([100]);
    $first = makeRow($world['match']->id, 100);
    $second = makeRow($world['match']->id, 200);
    linkSentence('en', $world['enSentences'][0]->id, $first->id, 100);

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

test('rejects empty sentence content', function () {
    $world = editorWorld([100]);
    $row = makeRow($world['match']->id, 100);
    $sentence = $world['enSentences'][0];
    linkSentence('en', $sentence->id, $row->id, 100);

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
    linkSentence('en', $sentence->id, $row->id, 100);

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
    linkSentence('en', $sentence->id, $row->id, 100);

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
    linkSentence('en', $a->id, $row->id, 213);
    linkSentence('en', $b->id, $row->id, 240);
    linkSentence('en', $c->id, $row->id, 250);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences/move", [
            'lang' => 'en',
            'sentence_id' => $c->id,
            'to_row_id' => $row->id,
            'index' => 1,
        ]);

    $response->assertOk();

    $orders = EnSentenceMeaningMatch::query()
        ->where('en_ru_meaning_match_id', $row->id)
        ->orderBy('order')
        ->orderBy('id')
        ->get('en_entity_sentence_id')
        ->pluck('en_entity_sentence_id')
        ->all();

    expect($orders)->toBe([$a->id, $c->id, $b->id]);

    $sentenceOrders = EnEntitySentence::query()
        ->whereIn('id', [$a->id, $b->id, $c->id])
        ->pluck('order')
        ->values()
        ->all();

    expect(count(array_unique($sentenceOrders)))->toBe(3);
});

test('moves a sentence from one row to another', function () {
    $world = editorWorld([100, 110]);
    $rowA = makeRow($world['match']->id, 100);
    $rowB = makeRow($world['match']->id, 200);
    [$a, $b] = $world['enSentences'];
    linkSentence('en', $a->id, $rowA->id, 100);
    linkSentence('en', $b->id, $rowB->id, 110);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences/move", [
            'lang' => 'en',
            'sentence_id' => $a->id,
            'to_row_id' => $rowB->id,
            'index' => 0,
        ]);

    $response->assertOk();

    $rowAIds = $response->json('rows.0.en_sentences');
    $rowBIds = $response->json('rows.1.en_sentences');

    $this->assertSame([], $rowAIds);
    expect(collect($rowBIds)->pluck('id'))->toContain($a->id);
    $this->assertDatabaseMissing('en_sentence_meaning_matches', ['en_entity_sentence_id' => $a->id, 'en_ru_meaning_match_id' => $rowA->id]);
    $this->assertDatabaseHas('en_sentence_meaning_matches', ['en_entity_sentence_id' => $a->id, 'en_ru_meaning_match_id' => $rowB->id]);
});

test('moves a sentence from unmatched into a row', function () {
    $world = editorWorld([100, 300]);
    $row = makeRow($world['match']->id, 100);
    [$a, $unmatched] = $world['enSentences'];
    linkSentence('en', $a->id, $row->id, 100);

    $response = actingAs(User::factory()->create())
        ->postJson("/alignments/{$world['match']->id}/sentences/move", [
            'lang' => 'en',
            'sentence_id' => $unmatched->id,
            'to_row_id' => $row->id,
            'index' => 1,
        ]);

    $response->assertOk();

    $sentences = $response->json('rows.0.en_sentences');
    expect(collect($sentences)->pluck('id'))->toContain($unmatched->id);
    $this->assertDatabaseHas('en_sentence_meaning_matches', ['en_entity_sentence_id' => $unmatched->id, 'en_ru_meaning_match_id' => $row->id]);
});

test('moves a sentence from a row out to unmatched', function () {
    $world = editorWorld([100]);
    $row = makeRow($world['match']->id, 100);
    $sentence = $world['enSentences'][0];
    linkSentence('en', $sentence->id, $row->id, 100);

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
    linkSentence('en', $sentence->id, $row->id, 100);

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
