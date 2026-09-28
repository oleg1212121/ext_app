<?php

use App\Jobs\AlignEntitySentences;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\SentenceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// Guard: these tests stop at Bus::fake(), but anything that leaks an HTTP call
// must hit a fake response instead of hanging on the Python service timeout.
beforeEach(function () {
    Http::fake();

    config([
        'limits.entities_processing_per_user' => 2,
        'limits.alignments_processing_per_user' => 1,
    ]);
});

/**
 * @return array{enEntity: Entity, ruEntity: Entity, work: object}
 */
function limitAlignablePair(): array
{
    $sentenceType = SentenceType::query()->firstOrCreate(['name' => 'Narration']);
    $work = createWork();

    $enEntity = createEntity('en', $work, [
        'name' => 'English chapter '.Entity::query()->count(),
        'signature' => json_encode([1.0, 0.0]),
    ]);
    $ruEntity = createEntity('ru', $work, [
        'name' => 'Russian chapter '.Entity::query()->count(),
        'signature' => json_encode([1.0, 0.0]),
    ]);

    EntitySentence::create([
        'entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'An English sentence.',
        'order' => 1,
    ]);
    EntitySentence::create([
        'entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'Русское предложение.',
        'order' => 1,
    ]);

    return ['enEntity' => $enEntity, 'ruEntity' => $ruEntity, 'work' => $work];
}

test('a match records its creator', function () {
    Bus::fake();
    $user = User::factory()->create();
    ['enEntity' => $enEntity, 'ruEntity' => $ruEntity] = limitAlignablePair();

    $this->actingAs($user)->post("/works/{$enEntity->work_id}/alignments", [
        'first_entity_id' => $enEntity->id,
        'second_entity_id' => $ruEntity->id,
        'chunk_size' => 50,
        'max_n' => 4,
    ])->assertSessionHasNoErrors();

    $match = EntityMatch::query()
        ->where('a_entity_id', $enEntity->id)
        ->where('b_entity_id', $ruEntity->id)
        ->firstOrFail();

    expect($match->created_by)->toBe($user->id)
        ->and($match->status)->toBe('aligning');
});

test('a non-admin with an alignment in flight cannot start another', function () {
    Bus::fake();
    $user = User::factory()->create();
    ['enEntity' => $enEntity, 'ruEntity' => $ruEntity] = limitAlignablePair();

    createEntityMatch($enEntity, $ruEntity, ['created_by' => $user->id, 'status' => 'pending']);

    $secondPair = limitAlignablePair();

    $this->actingAs($user)->post("/works/{$secondPair['work']->id}/alignments", [
        'first_entity_id' => $secondPair['enEntity']->id,
        'second_entity_id' => $secondPair['ruEntity']->id,
        'chunk_size' => 50,
        'max_n' => 4,
    ])->assertSessionHasErrors('limit');

    expect(EntityMatch::query()->where('a_entity_id', $secondPair['enEntity']->id)->exists())->toBeFalse()
        ->and(EntityMatch::query()->count())->toBe(1);
    Bus::assertNotDispatched(AlignEntitySentences::class);
});

test('an aligning match holds the creator\'s slot', function () {
    Bus::fake();
    $user = User::factory()->create();
    ['enEntity' => $enEntity, 'ruEntity' => $ruEntity] = limitAlignablePair();

    createEntityMatch($enEntity, $ruEntity, ['created_by' => $user->id, 'status' => 'aligning']);

    $secondPair = limitAlignablePair();

    $this->actingAs($user)->post("/works/{$secondPair['work']->id}/alignments", [
        'first_entity_id' => $secondPair['enEntity']->id,
        'second_entity_id' => $secondPair['ruEntity']->id,
        'chunk_size' => 50,
        'max_n' => 4,
    ])->assertSessionHasErrors('limit');
});

test('completed and failed matches hold no slot', function () {
    Bus::fake();
    $user = User::factory()->create();
    ['enEntity' => $enEntity, 'ruEntity' => $ruEntity] = limitAlignablePair();

    createEntityMatch($enEntity, $ruEntity, ['created_by' => $user->id, 'status' => 'completed']);

    $secondPair = limitAlignablePair();
    createEntityMatch($secondPair['enEntity'], $secondPair['ruEntity'], ['created_by' => $user->id, 'status' => 'failed']);

    $thirdPair = limitAlignablePair();

    $this->actingAs($user)->post("/works/{$thirdPair['work']->id}/alignments", [
        'first_entity_id' => $thirdPair['enEntity']->id,
        'second_entity_id' => $thirdPair['ruEntity']->id,
        'chunk_size' => 50,
        'max_n' => 4,
    ])->assertSessionHasNoErrors();

    Bus::assertDispatched(AlignEntitySentences::class);
});

test('another user\'s in-flight match does not block creation', function () {
    Bus::fake();
    $user = User::factory()->create();
    ['enEntity' => $enEntity, 'ruEntity' => $ruEntity] = limitAlignablePair();

    createEntityMatch($enEntity, $ruEntity, [
        'created_by' => User::factory()->create()->id,
        'status' => 'aligning',
    ]);

    $secondPair = limitAlignablePair();

    $this->actingAs($user)->post("/works/{$secondPair['work']->id}/alignments", [
        'first_entity_id' => $secondPair['enEntity']->id,
        'second_entity_id' => $secondPair['ruEntity']->id,
        'chunk_size' => 50,
        'max_n' => 4,
    ])->assertSessionHasNoErrors();
});

test('an admin ignores the alignment limit', function () {
    Bus::fake();
    $admin = User::factory()->admin()->approved()->create();
    ['enEntity' => $enEntity, 'ruEntity' => $ruEntity] = limitAlignablePair();

    createEntityMatch($enEntity, $ruEntity, ['created_by' => $admin->id, 'status' => 'aligning']);

    $secondPair = limitAlignablePair();

    $this->actingAs($admin)->post("/works/{$secondPair['work']->id}/alignments", [
        'first_entity_id' => $secondPair['enEntity']->id,
        'second_entity_id' => $secondPair['ruEntity']->id,
        'chunk_size' => 50,
        'max_n' => 4,
    ])->assertSessionHasNoErrors();
});
