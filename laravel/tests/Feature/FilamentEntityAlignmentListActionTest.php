<?php

use App\Classes\EntityTextHasher;
use App\Filament\Resources\EntityMatchResource\Pages\ListEntityMatches;
use App\Jobs\AlignEntitySentences;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use App\Models\User;
use App\Models\Work;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

// Guard: these tests stop at Bus::fake(), but anything that leaks an HTTP call
// must hit a fake response instead of hanging on the Python service timeout.
beforeEach(fn () => Http::fake());

function createListActionEntity(string $languageCode, Work $work, string $name): Entity
{
    $entity = createEntity($languageCode, $work, [
        'name' => $name,
        'signature' => json_encode([1.0, 0.0]),
    ]);

    EntitySentence::create([
        'entity_id' => $entity->id,
        'sentence_type_id' => null,
        'content' => 'Text.',
        'order' => 1,
    ]);

    return $entity;
}

test('new alignment action persists the chosen pair in canonical side order', function () {
    Bus::fake();

    $user = User::factory()->create();
    $work = createWork();
    $enEntity = createListActionEntity('en', $work, 'En List Original');
    $ruEntity = createListActionEntity('ru', $work, 'Ru List Translation');

    Livewire::actingAs($user)
        ->test(ListEntityMatches::class)
        ->callAction('create', data: [
            'first_entity_id' => $ruEntity->id,
            'second_entity_id' => $enEntity->id,
        ]);

    // The EN entity was created first (lower id), so it is canonicalized to the a side.
    $this->assertDatabaseHas('entity_matches', [
        'a_entity_id' => $enEntity->id,
        'b_entity_id' => $ruEntity->id,
    ]);
});

test('new alignment action derives the original side from the work', function () {
    Bus::fake();

    $user = User::factory()->create();
    $work = createWork(); // defaults to English as the original language
    $enEntity = createListActionEntity('en', $work, 'En List Default');
    $ruEntity = createListActionEntity('ru', $work, 'Ru List Default');

    Livewire::actingAs($user)
        ->test(ListEntityMatches::class)
        ->callAction('create', data: [
            'first_entity_id' => $enEntity->id,
            'second_entity_id' => $ruEntity->id,
        ]);

    $match = EntityMatch::query()
        ->where('a_entity_id', $enEntity->id)
        ->where('b_entity_id', $ruEntity->id)
        ->first();

    expect($match)->not->toBeNull()
        ->and($match->originalSide())->toBe('a');
});

test('alignment list shows the pair and work columns', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ListEntityMatches::class)
        ->assertSuccessful()
        ->assertSee('A Entity')
        ->assertSee('B Entity')
        ->assertSee('Work');
});

/**
 * A completed alignment with curated rows: one human-made meaning match
 * (alignment_chunk = -1) and one machine row, each junctioned on both sides —
 * exactly the curation a creation attempt must never destroy. Both sides
 * carry a signature and sentences, so the list action's form accepts them.
 *
 * @return array{match: EntityMatch, en: Entity, ru: Entity}
 */
function laCuratedAlignment(): array
{
    $sentenceType = SentenceType::query()->firstOrCreate(['name' => 'Narration']);
    $work = createWork();

    $en = createEntity('en', $work, ['name' => 'List Curated EN', 'signature' => json_encode([1.0, 0.0])]);
    $ru = createEntity('ru', $work, ['name' => 'List Curated RU', 'signature' => json_encode([1.0, 0.0])]);

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

/**
 * An entity with three sentences, a signature, and a current text hash (an
 * Alignment-copy lookup compares hashes).
 */
function laHashedEntity(string $lang, object $work, string $name): Entity
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
function laExactCopy(Entity $source, string $name): Entity
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
function laCompletedSource(Entity $a, Entity $b): EntityMatch
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

test('new alignment action creates the match through the creation module', function () {
    Bus::fake();

    // An admin is immune to processing limits, keeping this success path
    // independent of the limit behavior covered further down.
    $admin = User::factory()->admin()->approved()->create();
    $work = createWork();
    $enEntity = createListActionEntity('en', $work, 'En Module Original');
    $ruEntity = createListActionEntity('ru', $work, 'Ru Module Translation');

    Livewire::actingAs($admin)
        ->test(ListEntityMatches::class)
        ->callAction('create', data: [
            // Reversed form order on purpose: canonical sides are the module's rule.
            'first_entity_id' => $ruEntity->id,
            'second_entity_id' => $enEntity->id,
        ])
        ->assertNotified('Alignment started');

    $match = EntityMatch::query()->sole();

    // The EN entity was created first (lower id), so it is canonicalized to the a side.
    expect($match->a_entity_id)->toBe($enEntity->id)
        ->and($match->b_entity_id)->toBe($ruEntity->id)
        ->and($match->created_by)->toBe($admin->id)
        // The pipeline's synchronous preamble flips the pending-born row.
        ->and($match->status)->toBe('aligning')
        // The module is the only writer: no leftover action-local insert.
        ->and(EntityMatch::query()->count())->toBe(1);

    Bus::assertDispatchedTimes(AlignEntitySentences::class, 1);
});

test('new alignment action on a duplicate notifies with a link and destroys nothing', function () {
    Bus::fake();

    $admin = User::factory()->admin()->approved()->create();
    ['match' => $existing, 'en' => $enEntity, 'ru' => $ruEntity] = laCuratedAlignment();

    Livewire::actingAs($admin)
        ->test(ListEntityMatches::class)
        ->callAction('create', data: [
            // Reversed form order on purpose: the duplicate rule is canonical.
            'first_entity_id' => $ruEntity->id,
            'second_entity_id' => $enEntity->id,
        ]);

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
    // human-curated rows (alignment_chunk = -1) included. This is the fix for
    // the header action's old delete-before-create duplicate path.
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

test('new alignment action rejects a cross-work pair with a danger notification', function () {
    Bus::fake();

    $admin = User::factory()->admin()->approved()->create();
    $workA = createWork(['title' => 'List Work A']);
    $workB = createWork(['title' => 'List Work B']);
    $enEntity = createListActionEntity('en', $workA, 'List A En');
    $ruEntity = createListActionEntity('ru', $workB, 'List B Ru');

    Livewire::actingAs($admin)
        ->test(ListEntityMatches::class)
        ->callAction('create', data: [
            'first_entity_id' => $enEntity->id,
            'second_entity_id' => $ruEntity->id,
        ])
        // The module's message, not a page-local guard's.
        ->assertNotified('Both entities must belong to the same work.');

    expect(EntityMatch::query()->count())->toBe(0);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

test('a stale header-action submission whose entity vanished is reported, not a 404', function () {
    Bus::fake();

    $admin = User::factory()->admin()->approved()->create();
    $work = createWork();
    $ruEntity = createListActionEntity('ru', $work, 'Stale List Ru');

    $page = Livewire::actingAs($admin)
        ->test(ListEntityMatches::class)
        ->instance();

    // A stale form submission: the entity id was on the page when it
    // rendered, but is gone by the time the action runs. Reached directly
    // because Filament's own select in-validation already stops stale ids
    // in the normal form flow — this pins the action's graceful backstop
    // (find + notification) instead of findOrFail's 404.
    $action = (new ReflectionMethod(ListEntityMatches::class, 'getHeaderActions'))->invoke($page)[0];

    $action->getActionFunction()([
        'first_entity_id' => 999999999,
        'second_entity_id' => $ruEntity->id,
    ]);

    $notifications = new Notifications;
    $notifications->mount();
    $sent = $notifications->notifications->first(
        fn (Notification $notification): bool => $notification->getTitle() === 'Entity not found.',
    );

    expect($sent)->not->toBeNull()
        ->and(EntityMatch::query()->count())->toBe(0);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

test('new alignment action stops a non-admin at the alignment limit with a danger notification', function () {
    Bus::fake();

    config(['limits.alignments_processing_per_user' => 1]);

    $user = User::factory()->create();
    $work = createWork();
    $limitEn = createListActionEntity('en', $work, 'Limit Occupied En');
    $limitRu = createListActionEntity('ru', $work, 'Limit Occupied Ru');
    createEntityMatch($limitEn, $limitRu, ['created_by' => $user->id, 'status' => 'pending']);

    $enEntity = createListActionEntity('en', $work, 'Limit Rejected En');
    $ruEntity = createListActionEntity('ru', $work, 'Limit Rejected Ru');

    Livewire::actingAs($user)
        ->test(ListEntityMatches::class)
        ->callAction('create', data: [
            'first_entity_id' => $enEntity->id,
            'second_entity_id' => $ruEntity->id,
        ])
        // The module's message, not an action-local guard's.
        ->assertNotified('You already have an alignment being processed. Wait for it to finish before starting another.');

    expect(EntityMatch::query()->count())->toBe(1);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

test('new alignment action completes an exact-copy pair by copy without the pipeline', function () {
    Bus::fake();

    $admin = User::factory()->admin()->approved()->create();
    $work = createWork();
    $enSource = laHashedEntity('en', $work, 'List Source EN');
    $ruSource = laHashedEntity('ru', $work, 'List Source RU');
    $source = laCompletedSource($enSource, $ruSource);
    $enCopy = laExactCopy($enSource, 'List Copy EN');
    $ruCopy = laExactCopy($ruSource, 'List Copy RU');

    Livewire::actingAs($admin)
        ->test(ListEntityMatches::class)
        ->callAction('create', data: [
            'first_entity_id' => $enCopy->id,
            'second_entity_id' => $ruCopy->id,
        ])
        ->assertNotified('Alignment copied');

    $match = EntityMatch::query()->whereKeyNot($source->id)->sole();

    expect($match->a_entity_id)->toBe(min($enCopy->id, $ruCopy->id))
        ->and($match->b_entity_id)->toBe(max($enCopy->id, $ruCopy->id))
        ->and($match->created_by)->toBe($admin->id)
        ->and($match->status)->toBe('completed')
        ->and($match->meaningMatches()->count())->toBe($source->meaningMatches()->count());

    Bus::assertNotDispatched(AlignEntitySentences::class);
});
