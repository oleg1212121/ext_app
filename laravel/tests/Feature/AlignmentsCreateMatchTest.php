<?php

use App\Jobs\AlignEntitySentences;
use App\Models\EnEntity;
use App\Models\EnEntitySentence;
use App\Models\EnRuEntityMatch;
use App\Models\RuEntity;
use App\Models\RuEntitySentence;
use App\Models\SentenceType;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{enEntity: EnEntity, ruEntity: RuEntity}
 */
function createAlignablePair(): array
{
    $sentenceType = SentenceType::create(['name' => 'Narration']);

    $enEntity = EnEntity::create([
        'name' => 'English chapter',
        'signature' => json_encode([1.0, 0.0]),
    ]);
    $ruEntity = RuEntity::create([
        'name' => 'Russian chapter',
        'signature' => json_encode([1.0, 0.0]),
    ]);

    EnEntitySentence::create([
        'en_entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'An English sentence.',
        'order' => 1,
    ]);
    RuEntitySentence::create([
        'ru_entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'Русское предложение.',
        'order' => 1,
    ]);

    return compact('enEntity', 'ruEntity');
}

test('guests are redirected from the create match page', function () {
    $this->get(route('alignments.create'))->assertRedirect(route('login'));
});

test('the creation page lists only alignable readable entities', function () {
    $user = User::factory()->create();
    ['enEntity' => $enEntity, 'ruEntity' => $ruEntity] = createAlignablePair();

    // Ineligible: no signature.
    EnEntity::create(['name' => 'No signature']);
    // Ineligible: no sentences.
    RuEntity::create(['name' => 'No sentences', 'signature' => json_encode([1.0, 0.0])]);
    // Ineligible: restricted and not granted.
    EnEntity::create([
        'name' => 'Restricted EN',
        'signature' => json_encode([1.0, 0.0]),
        'is_restricted' => true,
    ]);

    $this->actingAs($user)
        ->get(route('alignments.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Alignments/Create')
            ->has('enEntities', 1)
            ->where('enEntities.0.id', $enEntity->id)
            ->where('enEntities.0.text', 'English chapter')
            ->has('ruEntities', 1)
            ->where('ruEntities.0.id', $ruEntity->id)
            ->where('ruEntities.0.text', 'Russian chapter'));
});

test('creating a match stores the settings, starts the pipeline and redirects', function () {
    Bus::fake();
    $user = User::factory()->create();
    ['enEntity' => $enEntity, 'ruEntity' => $ruEntity] = createAlignablePair();

    $response = $this->actingAs($user)->post(route('alignments.store'), [
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'is_original_en' => false,
        'chunk_size' => 50,
        'max_n' => 4,
    ]);

    $response->assertRedirect(route('alignments.index'));
    $response->assertSessionHas('success');

    $match = EnRuEntityMatch::query()
        ->where('en_entity_id', $enEntity->id)
        ->where('ru_entity_id', $ruEntity->id)
        ->first();

    expect($match)->not->toBeNull()
        ->and($match->is_original_en)->toBeFalse()
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
            'en_entity_id' => $enEntity->id,
            'ru_entity_id' => $ruEntity->id,
            'is_original_en' => true,
            'chunk_size' => 20,
            'max_n' => 9,
        ])
        ->assertStatus(302)
        ->assertSessionHasErrors(['chunk_size', 'max_n']);

    expect(EnRuEntityMatch::query()->count())->toBe(0);
});

test('a duplicate entity pair is blocked with a link to the existing match', function () {
    $user = User::factory()->create();
    ['enEntity' => $enEntity, 'ruEntity' => $ruEntity] = createAlignablePair();

    $existing = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'completed',
    ]);

    $this->actingAs($user)
        ->from(route('alignments.create'))
        ->post(route('alignments.store'), [
            'en_entity_id' => $enEntity->id,
            'ru_entity_id' => $ruEntity->id,
            'is_original_en' => true,
            'chunk_size' => 75,
            'max_n' => 6,
        ])
        ->assertRedirect(route('alignments.create'))
        ->assertSessionHasErrors('ru_entity_id')
        ->assertSessionHas('existing_match_id', $existing->id);

    expect(EnRuEntityMatch::query()->count())->toBe(1);
});

test('cannot create a match involving an entity the user cannot read', function () {
    $user = User::factory()->create();
    ['ruEntity' => $ruEntity] = createAlignablePair();

    $restricted = EnEntity::create([
        'name' => 'Restricted EN',
        'signature' => json_encode([1.0, 0.0]),
        'is_restricted' => true,
    ]);

    $this->actingAs($user)
        ->post(route('alignments.store'), [
            'en_entity_id' => $restricted->id,
            'ru_entity_id' => $ruEntity->id,
            'is_original_en' => true,
            'chunk_size' => 75,
            'max_n' => 6,
        ])
        ->assertForbidden();

    expect(EnRuEntityMatch::query()->count())->toBe(0);
});
