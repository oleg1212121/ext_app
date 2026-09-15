<?php

use App\Models\EntitySentence;
use App\Models\EntityWord;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use App\Models\User;
use App\Models\UserWord;
use App\Models\UserWordEvent;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    SentenceType::create(['name' => 'sentence', 'description' => 'A standard sentence']);
});

/**
 * An aligned EN/RU pair with one meaning match, so tests have a real
 * mm:{id} row key to reference.
 *
 * @return array{meaningMatch: MeaningMatch, enSentence: EntitySentence, ruSentence: EntitySentence}
 */
function createEventScope(): array
{
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English']);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian']);
    $enSentence = EntitySentence::create([
        'entity_id' => $enEntity->id,
        'content' => 'The cat sleeps.',
        'order' => 1,
    ]);
    $ruSentence = EntitySentence::create([
        'entity_id' => $ruEntity->id,
        'content' => 'Кот спит.',
        'order' => 1,
    ]);
    $entityMatch = createEntityMatch($enEntity, $ruEntity, ['status' => 'completed']);
    $meaningMatch = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 0.95,
        'alignment_chunk' => 0,
    ]);
    SentenceMeaningMatch::create(['entity_sentence_id' => $enSentence->id, 'meaning_match_id' => $meaningMatch->id, 'side' => 'a']);
    SentenceMeaningMatch::create(['entity_sentence_id' => $ruSentence->id, 'meaning_match_id' => $meaningMatch->id, 'side' => 'b']);

    return ['meaningMatch' => $meaningMatch, 'enSentence' => $enSentence, 'ruSentence' => $ruSentence];
}

function linkEntityWord(int $entityId, Word $word): void
{
    EntityWord::create([
        'entity_id' => $entityId,
        'word_id' => $word->id,
        'l_word' => $word->l_word,
        'token' => $word->word,
        'count' => 1,
    ]);
}

it('credits a read event with +1 familiarity', function () {
    $user = User::factory()->create();
    ['meaningMatch' => $meaningMatch] = createEventScope();
    $cat = createWord('en', 'cat', 'noun');

    $response = $this->actingAs($user)
        ->postJson(route('word.events.store'), [
            'events' => [
                ['row_key' => 'mm:'.$meaningMatch->id, 'kind' => 'read', 'word_ids' => [$cat->id]],
            ],
        ])
        ->assertOk();

    expect($response->json('data.familiarity'))->toBe([$cat->id => 1]);
    expect(UserWord::query()->where('user_id', $user->id)->where('word_id', $cat->id)->value('familiarity'))->toBe(1);
});

it('never credits the same word, row and kind twice', function () {
    $user = User::factory()->create();
    ['meaningMatch' => $meaningMatch] = createEventScope();
    $cat = createWord('en', 'cat', 'noun');
    $event = ['row_key' => 'mm:'.$meaningMatch->id, 'kind' => 'read', 'word_ids' => [$cat->id]];

    $this->actingAs($user)->postJson(route('word.events.store'), ['events' => [$event]])->assertOk();

    // Same event again: the ledger absorbs it, no delta.
    $response = $this->actingAs($user)
        ->postJson(route('word.events.store'), ['events' => [$event]])
        ->assertOk();

    expect($response->json('data.familiarity'))->toBe([$cat->id => 1]);
    expect(UserWordEvent::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('credits the same word again from a different row', function () {
    $user = User::factory()->create();
    ['meaningMatch' => $first, 'enSentence' => $enSentence] = createEventScope();
    $cat = createWord('en', 'cat', 'noun');

    $this->actingAs($user)
        ->postJson(route('word.events.store'), ['events' => [
            ['row_key' => 'mm:'.$first->id, 'kind' => 'read', 'word_ids' => [$cat->id]],
        ]])
        ->assertOk();

    $response = $this->actingAs($user)
        ->postJson(route('word.events.store'), ['events' => [
            ['row_key' => 'es:'.$enSentence->id, 'kind' => 'read', 'word_ids' => [$cat->id]],
        ]])
        ->assertOk();

    expect($response->json('data.familiarity'))->toBe([$cat->id => 2]);
});

it('credits each row of a batch separately', function () {
    $user = User::factory()->create();
    ['meaningMatch' => $first, 'enSentence' => $second] = createEventScope();
    $cat = createWord('en', 'cat', 'noun');

    $response = $this->actingAs($user)
        ->postJson(route('word.events.store'), ['events' => [
            ['row_key' => 'mm:'.$first->id, 'kind' => 'read', 'word_ids' => [$cat->id]],
            ['row_key' => 'es:'.$second->id, 'kind' => 'read', 'word_ids' => [$cat->id]],
        ]])
        ->assertOk();

    expect($response->json('data.familiarity'))->toBe([$cat->id => 2]);
});

it('penalizes the first lookup of a word in a row but not repeats', function () {
    $user = User::factory()->create();
    ['meaningMatch' => $meaningMatch] = createEventScope();
    $cat = createWord('en', 'cat', 'noun');
    UserWord::create(['user_id' => $user->id, 'word_id' => $cat->id, 'familiarity' => 5]);

    $event = ['row_key' => 'mm:'.$meaningMatch->id, 'kind' => 'lookup', 'word_ids' => [$cat->id]];
    $response = $this->actingAs($user)
        ->postJson(route('word.events.store'), ['events' => [$event]])
        ->assertOk();
    expect($response->json('data.familiarity'))->toBe([$cat->id => 3]);

    $response = $this->actingAs($user)
        ->postJson(route('word.events.store'), ['events' => [$event]])
        ->assertOk();
    expect($response->json('data.familiarity'))->toBe([$cat->id => 3]);
});

it('clamps familiarity to the 0-100 band', function () {
    $user = User::factory()->create();
    ['meaningMatch' => $meaningMatch] = createEventScope();
    $floor = createWord('en', 'floor', 'noun');
    $ceiling = createWord('en', 'ceiling', 'noun');
    UserWord::create(['user_id' => $user->id, 'word_id' => $floor->id, 'familiarity' => 1]);
    UserWord::create(['user_id' => $user->id, 'word_id' => $ceiling->id, 'familiarity' => 100]);

    $response = $this->actingAs($user)
        ->postJson(route('word.events.store'), ['events' => [
            ['row_key' => 'mm:'.$meaningMatch->id, 'kind' => 'lookup', 'word_ids' => [$floor->id]],
            ['row_key' => 'mm:'.$meaningMatch->id, 'kind' => 'read', 'word_ids' => [$ceiling->id]],
        ]])
        ->assertOk();

    expect($response->json('data.familiarity.'.$floor->id))->toBe(0)
        ->and($response->json('data.familiarity.'.$ceiling->id))->toBe(100);
});

it('rejects malformed events', function () {
    $user = User::factory()->create();
    ['meaningMatch' => $meaningMatch] = createEventScope();
    $cat = createWord('en', 'cat', 'noun');

    $this->actingAs($user)
        ->postJson(route('word.events.store'), ['events' => [
            ['row_key' => 'banana', 'kind' => 'read', 'word_ids' => [$cat->id]],
        ]])
        ->assertJsonValidationErrors(['events.0.row_key']);

    $this->actingAs($user)
        ->postJson(route('word.events.store'), ['events' => [
            ['row_key' => 'mm:99999999', 'kind' => 'read', 'word_ids' => [$cat->id]],
        ]])
        ->assertJsonValidationErrors(['events.0.row_key']);

    $this->actingAs($user)
        ->postJson(route('word.events.store'), ['events' => [
            ['row_key' => 'mm:'.$meaningMatch->id, 'kind' => 'banana', 'word_ids' => [$cat->id]],
        ]])
        ->assertJsonValidationErrors(['events.0.kind']);

    $this->assertDatabaseCount('user_word', 0);
});

it('requires authentication for word events', function () {
    ['meaningMatch' => $meaningMatch] = createEventScope();
    $cat = createWord('en', 'cat', 'noun');

    $this->postJson(route('word.events.store'), ['events' => [
        ['row_key' => 'mm:'.$meaningMatch->id, 'kind' => 'read', 'word_ids' => [$cat->id]],
    ]])->assertUnauthorized();
});
