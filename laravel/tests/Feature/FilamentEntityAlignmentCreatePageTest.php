<?php

use App\Classes\EntityTextHasher;
use App\Filament\Resources\EntityMatchResource\Pages\CreateEntityMatch;
use App\Jobs\AlignEntitySentences;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use App\Models\User;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

// Guard: the creation path dispatches the alignment pipeline; anything that
// leaks an HTTP call must hit a fake response instead of hanging on the
// Python service timeout.
beforeEach(fn () => Http::fake());

test('filament alignment create page lists entities without signature or sentences', function () {
    $user = User::factory()->create();

    $work = createWork();
    $entity = createEntity('en', $work, [
        'name' => 'Freshly Created English Entity',
        'description' => null,
        'signature' => null,
        'file_path' => null,
    ]);

    Livewire::actingAs($user)
        ->test(CreateEntityMatch::class)
        ->assertSuccessful()
        ->assertSee($entity->name, false)
        ->assertSee('First Entity')
        ->assertSee('Second Entity');
});

test('creating an alignment persists the chosen pair and settings', function () {
    Bus::fake();

    $user = User::factory()->create();
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'En Original']);
    $ruEntity = createEntity('ru', $work, ['name' => 'Ru Translation']);

    Livewire::actingAs($user)
        ->test(CreateEntityMatch::class)
        ->fillForm([
            'first_entity_id' => $ruEntity->id,
            'second_entity_id' => $enEntity->id,
            'chunk_size' => 50,
            'max_n' => 4,
        ])
        ->call('create');

    // The EN entity was created first (lower id), so it is canonicalized to the a side.
    $this->assertDatabaseHas('entity_matches', [
        'a_entity_id' => $enEntity->id,
        'b_entity_id' => $ruEntity->id,
        'chunk_size' => 50,
        'max_n' => 4,
    ]);
});

test('creating an alignment defaults chunk size and max span', function () {
    Bus::fake();

    $user = User::factory()->create();
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'En Default']);
    $ruEntity = createEntity('ru', $work, ['name' => 'Ru Default']);

    Livewire::actingAs($user)
        ->test(CreateEntityMatch::class)
        ->fillForm([
            'first_entity_id' => $enEntity->id,
            'second_entity_id' => $ruEntity->id,
        ])
        ->call('create');

    $this->assertDatabaseHas('entity_matches', [
        'a_entity_id' => $enEntity->id,
        'b_entity_id' => $ruEntity->id,
        'chunk_size' => 75,
        'max_n' => 6,
    ]);
});

/**
 * Two alignable entities of one work: signatures present, one sentence each —
 * the minimum for the pipeline's synchronous preamble to reach aligning.
 *
 * @return array{en: Entity, ru: Entity, work: object}
 */
function fcAlignablePair(): array
{
    $sentenceType = SentenceType::query()->firstOrCreate(['name' => 'Narration']);
    $work = createWork();

    $enEntity = createEntity('en', $work, [
        'name' => 'Page EN',
        'signature' => json_encode([1.0, 0.0]),
    ]);
    $ruEntity = createEntity('ru', $work, [
        'name' => 'Page RU',
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
function fcHashedEntity(string $lang, object $work, string $name): Entity
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
function fcExactCopy(Entity $source, string $name): Entity
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
function fcCompletedSource(Entity $a, Entity $b): EntityMatch
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

/**
 * A completed alignment with curated rows: one human-made meaning match
 * (alignment_chunk = -1) and one machine row, each junctioned on both sides —
 * exactly the curation a creation attempt must never destroy.
 *
 * @return array{match: EntityMatch, en: Entity, ru: Entity}
 */
function fcCuratedAlignment(): array
{
    $sentenceType = SentenceType::query()->firstOrCreate(['name' => 'Narration']);
    $work = createWork();

    $en = createEntity('en', $work, ['name' => 'Curated EN']);
    $ru = createEntity('ru', $work, ['name' => 'Curated RU']);

    $enSentences = collect(['First.', 'Second.'])->map(fn (string $content, int $index): EntitySentence => EntitySentence::create([
        'entity_id' => $en->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => $content,
        'order' => ($index + 1) * 1024,
    ]));
    $ruSentences = collect(['Первое.', 'Второе.'])->map(fn (string $content, int $index): EntitySentence => EntitySentence::create([
        'entity_id' => $ru->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => $content,
        'order' => ($index + 1) * 1024,
    ]));

    $match = createEntityMatch($en, $ru, ['status' => 'completed', 'completed_at' => now()]);

    foreach ($enSentences->values() as $index => $enSentence) {
        $meaning = MeaningMatch::create([
            'entity_match_id' => $match->id,
            'order' => ($index + 1) * 1024,
            'similarity' => 0.9,
            'alignment_chunk' => $index === 0 ? MeaningMatch::HUMAN_CHUNK : 0,
        ]);

        SentenceMeaningMatch::create([
            'entity_sentence_id' => $enSentence->id,
            'meaning_match_id' => $meaning->id,
            'side' => 'a',
        ]);

        SentenceMeaningMatch::create([
            'entity_sentence_id' => $ruSentences->values()->get($index)->id,
            'meaning_match_id' => $meaning->id,
            'side' => 'b',
        ]);
    }

    return ['match' => $match, 'en' => $en, 'ru' => $ru];
}

test('a fresh pair submitted on the page creates the match through the creation module', function () {
    Bus::fake();

    $admin = User::factory()->admin()->approved()->create();
    ['en' => $enEntity, 'ru' => $ruEntity] = fcAlignablePair();

    // Reversed form order on purpose: canonical sides are the module's rule.
    Livewire::actingAs($admin)
        ->test(CreateEntityMatch::class)
        ->fillForm([
            'first_entity_id' => $ruEntity->id,
            'second_entity_id' => $enEntity->id,
            'chunk_size' => 50,
            'max_n' => 4,
        ])
        ->call('create')
        ->assertNotified('Alignment started')
        ->assertRedirect();

    $match = EntityMatch::query()->sole();

    // max_n pins the form -> module knob handoff (4, not the module default 6).
    // chunk_size is not asserted: for sentences within one chunk the pipeline's
    // synchronous preamble rewrites the row to the effective chunk (1 here) —
    // existing tests pin knob persistence on rows that bail at verification.
    expect($match->a_entity_id)->toBe(min($enEntity->id, $ruEntity->id))
        ->and($match->b_entity_id)->toBe(max($enEntity->id, $ruEntity->id))
        ->and($match->created_by)->toBe($admin->id)
        ->and($match->max_n)->toBe(4)
        // The pipeline's synchronous preamble flips the pending-born row.
        ->and($match->status)->toBe('aligning')
        // The module is the only writer: no leftover page-local insert.
        ->and(EntityMatch::query()->count())->toBe(1);

    Bus::assertDispatchedTimes(AlignEntitySentences::class, 1);
});

test('a duplicate attempt on the page surfaces the existing match and destroys nothing', function () {
    Bus::fake();

    $admin = User::factory()->admin()->approved()->create();
    ['match' => $existing, 'en' => $enEntity, 'ru' => $ruEntity] = fcCuratedAlignment();

    Livewire::actingAs($admin)
        ->test(CreateEntityMatch::class)
        ->fillForm([
            // Reversed form order on purpose: the duplicate rule is canonical.
            'first_entity_id' => $ruEntity->id,
            'second_entity_id' => $enEntity->id,
        ])
        ->call('create')
        ->assertNoRedirect();

    // The notification references the existing match and carries a link to
    // its Filament view page. Inspected via Filament's own session seam —
    // Notifications::mount() pulls the notifications out of the session, so
    // this cannot ride on assertNotified (each mount consumes them).
    $notifications = new Notifications;
    $notifications->mount();
    $sent = $notifications->notifications->first(
        fn (Notification $notification): bool => $notification->getTitle() === 'Match already exists',
    );

    expect($sent)->not->toBeNull()
        ->and($sent->getBody())->toContain('already exists')
        ->and($sent->toArray()['actions'][0]['url'] ?? null)
        ->toContain("/sentence-alignments/{$existing->id}");

    // Nothing was created or deleted: the curated alignment survives whole,
    // human-curated rows (alignment_chunk = -1) included.
    expect(EntityMatch::query()->count())->toBe(1)
        ->and($existing->refresh()->status)->toBe('completed')
        ->and(MeaningMatch::query()->where('entity_match_id', $existing->id)->count())->toBe(2)
        ->and(MeaningMatch::query()
            ->where('entity_match_id', $existing->id)
            ->where('alignment_chunk', MeaningMatch::HUMAN_CHUNK)
            ->count())->toBe(1)
        ->and(SentenceMeaningMatch::query()->where('entity_match_id', $existing->id)->count())->toBe(4);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

test('a cross-work pair is rejected by the module with a danger notification', function () {
    Bus::fake();

    $admin = User::factory()->admin()->approved()->create();
    $workA = createWork(['title' => 'Work A']);
    $workB = createWork(['title' => 'Work B']);
    $enEntity = createEntity('en', $workA, ['name' => 'Work A entity']);
    $ruEntity = createEntity('ru', $workB, ['name' => 'Work B entity']);

    Livewire::actingAs($admin)
        ->test(CreateEntityMatch::class)
        ->fillForm([
            'first_entity_id' => $enEntity->id,
            'second_entity_id' => $ruEntity->id,
        ])
        ->call('create')
        // The module's message, not a page-local guard's.
        ->assertNotified('Both entities must belong to the same work.')
        ->assertNoRedirect();

    expect(EntityMatch::query()->count())->toBe(0);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

test('a stale submission whose entity vanished is reported, not a 404', function () {
    Bus::fake();

    $admin = User::factory()->admin()->approved()->create();
    ['ru' => $ruEntity] = fcAlignablePair();

    $page = Livewire::actingAs($admin)
        ->test(CreateEntityMatch::class)
        ->instance();

    // A stale form submission: the entity id was on the page when it
    // rendered, but is gone by the time handleRecordCreation runs. Reached
    // directly because Filament's own select in-validation already stops
    // stale ids in the normal form flow — this pins the method's graceful
    // backstop (the old page's behavior) instead of findOrFail's 404.
    $method = new ReflectionMethod(CreateEntityMatch::class, 'handleRecordCreation');

    try {
        $method->invoke($page, [
            'first_entity_id' => 999999999,
            'second_entity_id' => $ruEntity->id,
        ]);

        $this->fail('Expected the create flow to halt.');
    } catch (Halt) {
        // The graceful stop: the action aborts without creating anything.
    }

    $notifications = new Notifications;
    $notifications->mount();
    $sent = $notifications->notifications->first(
        fn (Notification $notification): bool => $notification->getTitle() === 'Entity not found.',
    );

    expect($sent)->not->toBeNull()
        ->and(EntityMatch::query()->count())->toBe(0);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

test('an exact-copy pair submitted on the page completes by copy without the pipeline', function () {
    Bus::fake();

    $admin = User::factory()->admin()->approved()->create();
    $work = createWork();
    $enSource = fcHashedEntity('en', $work, 'Source EN');
    $ruSource = fcHashedEntity('ru', $work, 'Source RU');
    $source = fcCompletedSource($enSource, $ruSource);
    $enCopy = fcExactCopy($enSource, 'Copy EN');
    $ruCopy = fcExactCopy($ruSource, 'Copy RU');

    Livewire::actingAs($admin)
        ->test(CreateEntityMatch::class)
        ->fillForm([
            'first_entity_id' => $enCopy->id,
            'second_entity_id' => $ruCopy->id,
        ])
        ->call('create')
        ->assertNotified('Alignment copied')
        ->assertRedirect();

    $match = EntityMatch::query()->whereKeyNot($source->id)->sole();

    expect($match->a_entity_id)->toBe(min($enCopy->id, $ruCopy->id))
        ->and($match->b_entity_id)->toBe(max($enCopy->id, $ruCopy->id))
        ->and($match->created_by)->toBe($admin->id)
        ->and($match->status)->toBe('completed')
        ->and($match->meaningMatches()->count())->toBe($source->meaningMatches()->count());

    Bus::assertNotDispatched(AlignEntitySentences::class);
});
