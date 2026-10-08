<?php

use App\Classes\EntityMatchCreationService;
use App\Classes\EntityTextHasher;
use App\Exceptions\CrossWorkEntityPair;
use App\Exceptions\ProcessingLimitReached;
use App\Jobs\AlignEntitySentences;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

// Guard: the creation path dispatches the alignment pipeline; anything that
// leaks an HTTP call must hit a fake response instead of hanging on the
// Python service timeout.
beforeEach(function () {
    Http::fake();

    config([
        'limits.entities_processing_per_user' => 2,
        'limits.alignments_processing_per_user' => 1,
    ]);
});

function mcCreationService(): EntityMatchCreationService
{
    return new EntityMatchCreationService;
}

/**
 * Two alignable entities of one work: signatures present, one sentence each —
 * the minimum for the pipeline's synchronous preamble to reach aligning.
 *
 * @return array{en: Entity, ru: Entity, work: object}
 */
function mcPair(): array
{
    $sentenceType = SentenceType::query()->firstOrCreate(['name' => 'Narration']);
    $work = createWork();

    $enEntity = createEntity('en', $work, [
        'name' => 'Creation EN',
        'signature' => json_encode([1.0, 0.0]),
    ]);
    $ruEntity = createEntity('ru', $work, [
        'name' => 'Creation RU',
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

    return ['en' => $enEntity, 'ru' => $ruEntity, 'work' => $work];
}

/**
 * An entity with three sentences and a current text hash (an Alignment-copy
 * lookup compares hashes).
 */
function mcHashedEntity(string $lang, object $work, string $name): Entity
{
    $sentenceType = SentenceType::query()->firstOrCreate(['name' => 'Narration']);

    $entity = createEntity($lang, $work, [
        'name' => $name,
        'signature' => json_encode([1.0, 0.0]),
    ]);

    foreach (['First.', 'Second.', 'Third.'] as $index => $content) {
        EntitySentence::create([
            'entity_id' => $entity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => $content,
            'order' => ($index + 1) * 1024,
        ]);
    }

    $entity->forceFill([
        'text_hash' => (new EntityTextHasher)->hash($entity),
        'text_hashed_at' => now(),
        'sentences_updated_at' => now(),
    ])->save();

    return $entity->refresh();
}

/**
 * An exact copy of $source (same sentences, same text hash) under a new name.
 */
function mcExactCopy(Entity $source, string $name): Entity
{
    $copy = createEntity($source->language->code, $source->work, [
        'name' => $name,
        'signature' => $source->signature,
    ]);

    foreach ($source->sentences()->orderBy('order')->get() as $sentence) {
        EntitySentence::create([
            'entity_id' => $copy->id,
            'sentence_type_id' => $sentence->sentence_type_id,
            'content' => $sentence->content,
            'order' => $sentence->order,
        ]);
    }

    $copy->forceFill([
        'text_hash' => $source->text_hash,
        'text_hashed_at' => now(),
        'sentences_updated_at' => now(),
    ])->save();

    return $copy->refresh();
}

/**
 * A completed alignment between the two entities: one meaning match per
 * sentence pair, junctioned on both sides (the copy source).
 */
function mcCompletedSource(Entity $a, Entity $b): EntityMatch
{
    $match = createEntityMatch($a, $b, ['status' => 'completed', 'completed_at' => now()]);

    $aSentences = $a->sentences()->orderBy('order')->get();
    $bSentences = $b->sentences()->orderBy('order')->get();

    foreach ($aSentences as $index => $aSentence) {
        $meaning = MeaningMatch::create([
            'entity_match_id' => $match->id,
            'order' => ($index + 1) * 1024,
            'similarity' => 0.9,
            'alignment_chunk' => 0,
        ]);

        SentenceMeaningMatch::create([
            'entity_sentence_id' => $aSentence->id,
            'meaning_match_id' => $meaning->id,
            'side' => 'a',
        ]);

        SentenceMeaningMatch::create([
            'entity_sentence_id' => $bSentences[$index]->id,
            'meaning_match_id' => $meaning->id,
            'side' => 'b',
        ]);
    }

    $match->update([
        'linked_count' => $match->meaningMatches()->count(),
        'a_total_sentences' => $aSentences->count(),
        'b_total_sentences' => $bSentences->count(),
        'entity_similarity' => 0.95,
    ]);

    return $match->refresh();
}

test('a fresh pair creates a match under the creator and dispatches the pipeline', function () {
    Bus::fake();
    $user = User::factory()->create();
    ['en' => $enEntity, 'ru' => $ruEntity] = mcPair();

    $outcome = mcCreationService()->create($user, $enEntity, $ruEntity, 50, 4);

    expect($outcome['status'])->toBe('created')
        ->and($outcome['existing'])->toBeNull()
        ->and($outcome['match']->a_entity_id)->toBe(min($enEntity->id, $ruEntity->id))
        ->and($outcome['match']->b_entity_id)->toBe(max($enEntity->id, $ruEntity->id))
        ->and($outcome['match']->created_by)->toBe($user->id)
        // beginFromScratch's synchronous preamble flips the pending-born row
        // to aligning before queueing the first chunk.
        ->and($outcome['match']->status)->toBe('aligning')
        ->and($outcome['match']->max_n)->toBe(4)
        ->and(EntityMatch::query()->count())->toBe(1);

    Bus::assertDispatched(AlignEntitySentences::class);
});

test('an exact-copy pair with a completed alignment is completed by copy without the pipeline', function () {
    Bus::fake();
    $work = createWork();
    $enSource = mcHashedEntity('en', $work, 'Source EN');
    $ruSource = mcHashedEntity('ru', $work, 'Source RU');
    $source = mcCompletedSource($enSource, $ruSource);

    $enCopy = mcExactCopy($enSource, 'Copy EN');
    $ruCopy = mcExactCopy($ruSource, 'Copy RU');
    $creator = User::factory()->create();

    // No knobs: the module owns the defaults (the DB column defaults).
    $outcome = mcCreationService()->create($creator, $enCopy, $ruCopy);

    expect($outcome['status'])->toBe('created_from_copy')
        ->and($outcome['existing'])->toBeNull()
        ->and($outcome['match']->status)->toBe('completed')
        ->and($outcome['match']->created_by)->toBe($creator->id)
        ->and($outcome['match']->chunk_size)->toBe(75)
        ->and($outcome['match']->max_n)->toBe(6)
        ->and($outcome['match']->meaningMatches()->count())->toBe($source->meaningMatches()->count())
        ->and(EntityMatch::query()->count())->toBe(2);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

test('a duplicate pair returns the existing match and creates nothing', function () {
    Bus::fake();
    $user = User::factory()->create();
    ['en' => $enEntity, 'ru' => $ruEntity] = mcPair();
    $existing = createEntityMatch($enEntity, $ruEntity, ['status' => 'completed']);

    // Reversed argument order on purpose: the lookup runs on canonical sides.
    $outcome = mcCreationService()->create($user, $ruEntity, $enEntity);

    expect($outcome['status'])->toBe('duplicate')
        ->and($outcome['match'])->toBeNull()
        ->and($outcome['existing']->is($existing))->toBeTrue()
        ->and(EntityMatch::query()->count())->toBe(1)
        ->and($existing->refresh()->status)->toBe('completed');

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

test('a duplicate is reported even when the creator is at their limit', function () {
    Bus::fake();
    $user = User::factory()->create();
    ['en' => $enEntity, 'ru' => $ruEntity] = mcPair();
    $existing = createEntityMatch($enEntity, $ruEntity, ['created_by' => $user->id, 'status' => 'pending']);

    $outcome = mcCreationService()->create($user, $enEntity, $ruEntity);

    expect($outcome['status'])->toBe('duplicate')
        ->and($outcome['existing']->is($existing))->toBeTrue()
        ->and(EntityMatch::query()->count())->toBe(1);
});

test('entities of different works are rejected regardless of argument order', function () {
    Bus::fake();
    $user = User::factory()->create();
    $workA = createWork(['title' => 'Work A']);
    $workB = createWork(['title' => 'Work B']);
    $enEntity = createEntity('en', $workA, ['name' => 'Work A entity']);
    $ruEntity = createEntity('ru', $workB, ['name' => 'Work B entity']);

    expect(fn () => mcCreationService()->create($user, $enEntity, $ruEntity))
        ->toThrow(CrossWorkEntityPair::class);
    expect(fn () => mcCreationService()->create($user, $ruEntity, $enEntity))
        ->toThrow(CrossWorkEntityPair::class);

    expect(EntityMatch::query()->count())->toBe(0);
});

test('sides are canonical whichever order the caller passes', function () {
    Bus::fake();
    config(['limits.alignments_processing_per_user' => 2]);
    $user = User::factory()->create();
    ['en' => $firstA, 'ru' => $firstB] = mcPair();
    ['en' => $secondA, 'ru' => $secondB] = mcPair();

    $forward = mcCreationService()->create($user, $firstA, $firstB, 50, 4)['match'];
    $reversed = mcCreationService()->create($user, $secondB, $secondA, 50, 4)['match'];

    expect($forward->a_entity_id)->toBe(min($firstA->id, $firstB->id))
        ->and($forward->b_entity_id)->toBe(max($firstA->id, $firstB->id))
        ->and($reversed->a_entity_id)->toBe(min($secondA->id, $secondB->id))
        ->and($reversed->b_entity_id)->toBe(max($secondA->id, $secondB->id));
});

test('a non-admin at the alignment limit is rejected without a new row; an admin is exempt', function () {
    Bus::fake();
    $user = User::factory()->create();
    ['en' => $enEntity, 'ru' => $ruEntity] = mcPair();
    createEntityMatch($enEntity, $ruEntity, ['created_by' => $user->id, 'status' => 'pending']);

    $second = mcPair();

    expect(fn () => mcCreationService()->create($user, $second['en'], $second['ru']))
        ->toThrow(ProcessingLimitReached::class);

    $secondPairId = min($second['en']->id, $second['ru']->id);

    expect(EntityMatch::query()->count())->toBe(1)
        ->and(EntityMatch::query()->where('a_entity_id', $secondPairId)->exists())->toBeFalse();

    Bus::assertNotDispatched(AlignEntitySentences::class);

    $admin = User::factory()->admin()->approved()->create();

    $outcome = mcCreationService()->create($admin, $second['en'], $second['ru']);

    expect($outcome['status'])->toBe('created')
        ->and($outcome['match']->created_by)->toBe($admin->id);

    Bus::assertDispatched(AlignEntitySentences::class);
});
