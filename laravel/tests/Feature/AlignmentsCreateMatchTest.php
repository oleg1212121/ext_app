<?php

use App\Jobs\AlignEntitySentences;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\SentenceType;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{enEntity: Entity, ruEntity: Entity}
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

    return compact('enEntity', 'ruEntity');
}

test('guests are redirected from the create match page', function () {
    $this->get(route('alignments.create'))->assertRedirect(route('login'));
});

test('the creation page lists only alignable readable entities of shared works', function () {
    $user = User::factory()->create();
    ['enEntity' => $enEntity, 'ruEntity' => $ruEntity] = createAlignablePair();

    // Ineligible: no signature.
    createEntity('en', null, ['name' => 'No signature']);
    // Ineligible: no sentences.
    createEntity('ru', null, ['name' => 'No sentences', 'signature' => json_encode([1.0, 0.0])]);
    // Ineligible: restricted and not granted.
    createEntity('en', null, [
        'name' => 'Restricted EN',
        'signature' => json_encode([1.0, 0.0]),
        'is_restricted' => true,
    ]);

    $this->actingAs($user)
        ->get(route('alignments.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Alignments/Create')
            ->has('works', 1)
            ->where('works.0.entities.en.0.id', $enEntity->id)
            ->where('works.0.entities.en.0.text', 'English chapter')
            ->where('works.0.entities.ru.0.id', $ruEntity->id)
            ->where('works.0.entities.ru.0.text', 'Russian chapter'));
});

test('creating a match stores the settings, starts the pipeline and redirects', function () {
    Bus::fake();
    $user = User::factory()->create();
    ['enEntity' => $enEntity, 'ruEntity' => $ruEntity] = createAlignablePair();

    $response = $this->actingAs($user)->post(route('alignments.store'), [
        'first_entity_id' => $enEntity->id,
        'second_entity_id' => $ruEntity->id,
        'chunk_size' => 50,
        'max_n' => 4,
    ]);

    $response->assertRedirect(route('alignments.index'));
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
    ['enEntity' => $enEntity, 'ruEntity' => $ruEntity] = createAlignablePair();

    $this->actingAs($user)
        ->from(route('alignments.create'))
        ->post(route('alignments.store'), [
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
    ['enEntity' => $enEntity, 'ruEntity' => $ruEntity] = createAlignablePair();

    $existing = createEntityMatch($enEntity, $ruEntity, ['status' => 'completed']);

    $this->actingAs($user)
        ->from(route('alignments.create'))
        ->post(route('alignments.store'), [
            'first_entity_id' => $enEntity->id,
            'second_entity_id' => $ruEntity->id,
            'chunk_size' => 75,
            'max_n' => 6,
        ])
        ->assertRedirect(route('alignments.create'))
        ->assertSessionHasErrors('second_entity_id')
        ->assertSessionHas('existing_match_id', $existing->id);

    expect(EntityMatch::query()->count())->toBe(1);
});

test('entities from different works are rejected', function () {
    $user = User::factory()->create();
    $enEntity = createEntity('en', null, ['name' => 'English chapter']);
    $ruEntity = createEntity('ru', null, ['name' => 'Russian chapter']);

    $this->actingAs($user)
        ->from(route('alignments.create'))
        ->post(route('alignments.store'), [
            'first_entity_id' => $enEntity->id,
            'second_entity_id' => $ruEntity->id,
            'chunk_size' => 75,
            'max_n' => 6,
        ])
        ->assertRedirect(route('alignments.create'))
        ->assertSessionHasErrors('second_entity_id');

    expect(EntityMatch::query()->count())->toBe(0);
});

test('cannot create a match involving an entity the user cannot read', function () {
    $user = User::factory()->create();
    ['ruEntity' => $ruEntity, 'enEntity' => $pairEn] = createAlignablePair();
    $work = $pairEn->work;

    $restricted = createEntity('en', $work, [
        'name' => 'Restricted EN',
        'signature' => json_encode([1.0, 0.0]),
        'is_restricted' => true,
    ]);

    $this->actingAs($user)
        ->post(route('alignments.store'), [
            'first_entity_id' => $restricted->id,
            'second_entity_id' => $ruEntity->id,
            'chunk_size' => 75,
            'max_n' => 6,
        ])
        ->assertForbidden();

    expect(EntityMatch::query()->count())->toBe(0);
});
