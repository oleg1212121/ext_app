<?php

use App\Jobs\AlignEntitySentences;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\SentenceType;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

// Guard: these tests stop at Bus::fake(), but anything that leaks an HTTP call
// must hit a fake response instead of hanging on the Python service timeout.
beforeEach(fn () => Http::fake());

/**
 * @return array{enEntity: Entity, ruEntity: Entity, work: object}
 */
function createAlignablePair(): array
{
    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();

    $enEntity = createEntity('en', $work, [
        'name' => 'English chapter',
        'signature' => json_encode([1.0, 0.0]),
    ]);
    $ruEntity = createEntity('ru', $work, [
        'name' => 'Russian chapter',
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

test('guests are redirected from the create match page', function () {
    $work = createWork();

    $this->get("/library/{$work->id}/alignments/create")->assertRedirect(route('login'));
});

test('the creation page lists only the work\'s alignable readable entities', function () {
    $user = User::factory()->create();
    ['enEntity' => $enEntity, 'ruEntity' => $ruEntity, 'work' => $work] = createAlignablePair();

    // Eligible, but of a different work — must not leak into the form.
    $otherWork = createWork(['title' => 'Other work']);
    createEntity('en', $otherWork, [
        'name' => 'Other work entity',
        'signature' => json_encode([1.0, 0.0]),
    ]);

    // Ineligible: no signature.
    createEntity('en', $work, ['name' => 'No signature']);
    // Ineligible: no sentences.
    createEntity('ru', $work, ['name' => 'No sentences', 'signature' => json_encode([1.0, 0.0])]);
    // Ineligible: restricted and not granted.
    createEntity('en', $work, [
        'name' => 'Restricted EN',
        'signature' => json_encode([1.0, 0.0]),
        'is_restricted' => true,
    ]);

    $this->actingAs($user)
        ->get("/library/{$work->id}/alignments/create")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Library/CreateAlignment')
            ->where('work.id', $work->id)
            ->has('entities.en', 1)
            ->where('entities.en.0.id', $enEntity->id)
            ->where('entities.en.0.text', 'English chapter')
            ->has('entities.ru', 1)
            ->where('entities.ru.0.id', $ruEntity->id)
            ->where('entities.ru.0.text', 'Russian chapter'));
});

test('creating a match stores the settings, starts the pipeline and redirects to the work tab', function () {
    Bus::fake();
    $user = User::factory()->create();
    ['enEntity' => $enEntity, 'ruEntity' => $ruEntity, 'work' => $work] = createAlignablePair();

    $response = $this->actingAs($user)->post("/library/{$work->id}/alignments", [
        'first_entity_id' => $enEntity->id,
        'second_entity_id' => $ruEntity->id,
        'chunk_size' => 50,
        'max_n' => 4,
    ]);

    $response->assertRedirect("/library/{$work->id}?tab=alignments");
    $response->assertSessionHas('success');

    $match = EntityMatch::query()
        ->where('a_entity_id', $enEntity->id)
        ->where('b_entity_id', $ruEntity->id)
        ->first();

    expect($match)->not->toBeNull()
        ->and($match->originalSide())->toBe('a')
        ->and($match->max_n)->toBe(4)
        ->and($match->status)->toBe('aligning');

    Bus::assertDispatched(AlignEntitySentences::class);
});

test('out-of-range chunk size and max_n are rejected', function () {
    $user = User::factory()->create();
    ['enEntity' => $enEntity, 'ruEntity' => $ruEntity, 'work' => $work] = createAlignablePair();

    $this->actingAs($user)
        ->from("/library/{$work->id}/alignments/create")
        ->post("/library/{$work->id}/alignments", [
            'first_entity_id' => $enEntity->id,
            'second_entity_id' => $ruEntity->id,
            'chunk_size' => 20,
            'max_n' => 9,
        ])
        ->assertStatus(302)
        ->assertSessionHasErrors(['chunk_size', 'max_n']);

    expect(EntityMatch::query()->count())->toBe(0);
});

test('a duplicate entity pair is blocked with a link to the existing match', function () {
    $user = User::factory()->create();
    ['enEntity' => $enEntity, 'ruEntity' => $ruEntity, 'work' => $work] = createAlignablePair();

    $existing = createEntityMatch($enEntity, $ruEntity, ['status' => 'completed']);

    $this->actingAs($user)
        ->from("/library/{$work->id}/alignments/create")
        ->post("/library/{$work->id}/alignments", [
            'first_entity_id' => $enEntity->id,
            'second_entity_id' => $ruEntity->id,
            'chunk_size' => 75,
            'max_n' => 6,
        ])
        ->assertRedirect("/library/{$work->id}/alignments/create")
        ->assertSessionHasErrors('second_entity_id')
        ->assertSessionHas('existing_match_id', $existing->id);

    expect(EntityMatch::query()->count())->toBe(1);
});

test('entities of another work are rejected even under a valid work route', function () {
    $user = User::factory()->create();
    $work = createWork();
    $enEntity = createEntity('en', null, ['name' => 'English chapter']);
    $ruEntity = createEntity('ru', null, ['name' => 'Russian chapter']);

    $this->actingAs($user)
        ->from("/library/{$work->id}/alignments/create")
        ->post("/library/{$work->id}/alignments", [
            'first_entity_id' => $enEntity->id,
            'second_entity_id' => $ruEntity->id,
            'chunk_size' => 75,
            'max_n' => 6,
        ])
        ->assertRedirect("/library/{$work->id}/alignments/create")
        ->assertSessionHasErrors('second_entity_id');

    expect(EntityMatch::query()->count())->toBe(0);
});

test('a mixed pair — one entity of the work, one of another — is rejected', function () {
    Bus::fake();
    $user = User::factory()->create();
    ['enEntity' => $enEntity, 'work' => $work] = createAlignablePair();
    $otherWork = createWork(['title' => 'Elsewhere']);
    $outsider = createEntity('ru', $otherWork, [
        'name' => 'Outsider RU',
        'signature' => json_encode([1.0, 0.0]),
    ]);

    $this->actingAs($user)
        ->from("/library/{$work->id}/alignments/create")
        ->post("/library/{$work->id}/alignments", [
            'first_entity_id' => $enEntity->id,
            'second_entity_id' => $outsider->id,
            'chunk_size' => 75,
            'max_n' => 6,
        ])
        ->assertRedirect("/library/{$work->id}/alignments/create")
        ->assertSessionHasErrors('second_entity_id');

    expect(EntityMatch::query()->count())->toBe(0);
});

test('the creation page lists same-language entities of the work', function () {
    $user = User::factory()->create();
    ['enEntity' => $enEntity, 'work' => $work] = createAlignablePair();

    $answers = createEntity('en', $work, [
        'name' => 'Answer key',
        'signature' => json_encode([0.9, 0.1]),
    ]);

    EntitySentence::create([
        'entity_id' => $answers->id,
        'sentence_type_id' => SentenceType::query()->where('name', 'Narration')->value('id'),
        'content' => 'An answer.',
        'order' => 1,
    ]);

    $this->actingAs($user)
        ->get("/library/{$work->id}/alignments/create")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Library/CreateAlignment')
            ->has('entities.en', 2)
            ->where('entities.en.0.id', $answers->id)
            ->where('entities.en.1.id', $enEntity->id));
});

test('a same-language entity pair can be matched', function () {
    Bus::fake();
    $user = User::factory()->create();
    ['enEntity' => $enEntity, 'work' => $work] = createAlignablePair();

    $answers = createEntity('en', $enEntity->work, [
        'name' => 'Answer key',
        'signature' => json_encode([0.9, 0.1]),
    ]);

    EntitySentence::create([
        'entity_id' => $answers->id,
        'sentence_type_id' => SentenceType::query()->where('name', 'Narration')->value('id'),
        'content' => 'An answer.',
        'order' => 1,
    ]);

    $response = $this->actingAs($user)->post("/library/{$work->id}/alignments", [
        'first_entity_id' => $enEntity->id,
        'second_entity_id' => $answers->id,
        'chunk_size' => 75,
        'max_n' => 6,
    ]);

    $response->assertRedirect("/library/{$work->id}?tab=alignments");

    $match = EntityMatch::query()
        ->where('a_entity_id', min($enEntity->id, $answers->id))
        ->where('b_entity_id', max($enEntity->id, $answers->id))
        ->first();

    expect($match)->not->toBeNull();

    Bus::assertDispatched(AlignEntitySentences::class);
});

test('cannot create a match involving an entity the user cannot read', function () {
    $user = User::factory()->create();
    ['ruEntity' => $ruEntity, 'enEntity' => $pairEn, 'work' => $work] = createAlignablePair();

    $restricted = createEntity('en', $work, [
        'name' => 'Restricted EN',
        'signature' => json_encode([1.0, 0.0]),
        'is_restricted' => true,
    ]);

    $this->actingAs($user)
        ->post("/library/{$work->id}/alignments", [
            'first_entity_id' => $restricted->id,
            'second_entity_id' => $ruEntity->id,
            'chunk_size' => 75,
            'max_n' => 6,
        ])
        ->assertForbidden();

    expect(EntityMatch::query()->count())->toBe(0);
});
