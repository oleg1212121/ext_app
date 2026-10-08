<?php

use App\Classes\EntityTextHasher;
use App\Filament\Resources\EntityResource\Pages\ListEntities;
use App\Jobs\AlignEntitySentences;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

// Guard: these tests stop at Bus::fake(), but anything that leaks an HTTP call
// must hit a fake response instead of hanging on the Python service timeout.
// (The find-match form's candidate computation is local cosine math.)
beforeEach(fn () => Http::fake());

/**
 * An alignable entity: signature present and at least one sentence — the
 * Find Match action's visibility conditions.
 */
function fmAlignableEntity(string $languageCode, object $work, string $name): Entity
{
    $sentenceType = SentenceType::query()->firstOrCreate(['name' => 'Narration']);

    $entity = createEntity($languageCode, $work, [
        'name' => $name,
        'signature' => json_encode([1.0, 0.0]),
    ]);

    EntitySentence::create([
        'entity_id' => $entity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'Text.',
        'order' => 1,
    ]);

    return $entity;
}

/**
 * A completed alignment with curated rows: one human-made meaning match
 * (alignment_chunk = -1) and one machine row, each junctioned on both sides —
 * exactly the curation a creation attempt must never destroy. Both sides
 * carry a signature and sentences, so the Find Match action accepts them.
 *
 * @return array{match: EntityMatch, en: Entity, ru: Entity}
 */
function fmCuratedAlignment(): array
{
    $sentenceType = SentenceType::query()->firstOrCreate(['name' => 'Narration']);
    $work = createWork();

    $en = createEntity('en', $work, ['name' => 'FM Curated EN', 'signature' => json_encode([1.0, 0.0])]);
    $ru = createEntity('ru', $work, ['name' => 'FM Curated RU', 'signature' => json_encode([1.0, 0.0])]);

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
function fmHashedEntity(string $lang, object $work, string $name): Entity
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
function fmExactCopy(Entity $source, string $name): Entity
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
function fmCompletedSource(Entity $a, Entity $b): EntityMatch
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

test('find match action creates the match through the creation module', function () {
    Bus::fake();

    // An admin is immune to processing limits, keeping this success path
    // independent of the limit behavior covered further down.
    $admin = User::factory()->admin()->approved()->create();
    $work = createWork();
    $enEntity = fmAlignableEntity('en', $work, 'FM En Original');
    $ruEntity = fmAlignableEntity('ru', $work, 'FM Ru Translation');

    Livewire::actingAs($admin)
        ->test(ListEntities::class)
        ->callTableAction('findMatch', $enEntity, data: [
            'other_entity_id' => $ruEntity->id,
        ])
        ->assertNotified('Alignment started');

    $match = EntityMatch::query()->sole();

    // The EN entity was created first (lower id), so it is canonicalized to
    // the a side even though it is the action's record.
    expect($match->a_entity_id)->toBe($enEntity->id)
        ->and($match->b_entity_id)->toBe($ruEntity->id)
        ->and($match->created_by)->toBe($admin->id)
        // The pipeline's synchronous preamble flips the pending-born row.
        ->and($match->status)->toBe('aligning')
        // The module is the only writer: no leftover action-local insert.
        ->and(EntityMatch::query()->count())->toBe(1);

    Bus::assertDispatchedTimes(AlignEntitySentences::class, 1);
});

test('find match action on a duplicate notifies with a link and destroys nothing', function () {
    Bus::fake();

    $admin = User::factory()->admin()->approved()->create();
    ['match' => $existing, 'en' => $enEntity, 'ru' => $ruEntity] = fmCuratedAlignment();

    Livewire::actingAs($admin)
        ->test(ListEntities::class)
        ->callTableAction('findMatch', $enEntity, data: [
            'other_entity_id' => $ruEntity->id,
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
    // the row action's old delete-before-create duplicate path.
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

test('find match action completes an exact-copy pair by copy without the pipeline', function () {
    Bus::fake();

    $admin = User::factory()->admin()->approved()->create();
    $work = createWork();
    $enSource = fmHashedEntity('en', $work, 'FM Source EN');
    $ruSource = fmHashedEntity('ru', $work, 'FM Source RU');
    $source = fmCompletedSource($enSource, $ruSource);
    $enCopy = fmExactCopy($enSource, 'FM Copy EN');
    $ruCopy = fmExactCopy($ruSource, 'FM Copy RU');

    Livewire::actingAs($admin)
        ->test(ListEntities::class)
        ->callTableAction('findMatch', $enCopy, data: [
            'other_entity_id' => $ruCopy->id,
        ])
        ->assertNotified('Alignment copied');

    $match = EntityMatch::query()->whereKeyNot($source->id)->sole();

    expect($match->a_entity_id)->toBe(min($enCopy->id, $ruCopy->id))
        ->and($match->b_entity_id)->toBe(max($enCopy->id, $ruCopy->id))
        ->and($match->created_by)->toBe($admin->id)
        ->and($match->status)->toBe('completed')
        ->and($match->meaningMatches()->count())->toBe($source->meaningMatches()->count());

    // This action used to always run the full pipeline; copy reuse is the
    // migration's headline gain here.
    Bus::assertNotDispatched(AlignEntitySentences::class);
});

test('a stale find-match submission whose entity vanished is reported, not a 404', function () {
    Bus::fake();

    $admin = User::factory()->admin()->approved()->create();
    $work = createWork();
    $enEntity = fmAlignableEntity('en', $work, 'Stale FM En');

    $page = Livewire::actingAs($admin)
        ->test(ListEntities::class)
        ->instance();

    // A stale form submission: the entity id was offered by the candidate
    // select when the form rendered, but is gone by the time the action
    // runs. Reached directly because Filament's own options validation
    // already stops unknown ids in the normal form flow — this pins the
    // action's graceful backstop (find + notification) instead of
    // findOrFail's 404.
    $action = collect($page->getTable()->getRecordActions())
        ->first(fn (Action $action): bool => $action->getName() === 'findMatch');

    $action->getActionFunction()($enEntity, ['other_entity_id' => 999999999]);

    $notifications = new Notifications;
    $notifications->mount();
    $sent = $notifications->notifications->first(
        fn (Notification $notification): bool => $notification->getTitle() === 'Entity not found.',
    );

    expect($sent)->not->toBeNull()
        ->and(EntityMatch::query()->count())->toBe(0);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

test('find match action stops a non-admin at the alignment limit with a danger notification', function () {
    Bus::fake();

    config(['limits.alignments_processing_per_user' => 1]);

    $user = User::factory()->create();
    $work = createWork();
    $limitEn = fmAlignableEntity('en', $work, 'FM Limit Occupied En');
    $limitRu = fmAlignableEntity('ru', $work, 'FM Limit Occupied Ru');
    createEntityMatch($limitEn, $limitRu, ['created_by' => $user->id, 'status' => 'pending']);

    $enEntity = fmAlignableEntity('en', $work, 'FM Limit Rejected En');
    $ruEntity = fmAlignableEntity('ru', $work, 'FM Limit Rejected Ru');

    Livewire::actingAs($user)
        ->test(ListEntities::class)
        ->callTableAction('findMatch', $enEntity, data: [
            'other_entity_id' => $ruEntity->id,
        ])
        // The module's message, not an action-local guard's.
        ->assertNotified('You already have an alignment being processed. Wait for it to finish before starting another.');

    expect(EntityMatch::query()->count())->toBe(1);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

test('find match action rejects a cross-work pair submitted outside the candidate filter', function () {
    Bus::fake();

    $admin = User::factory()->admin()->approved()->create();
    $workA = createWork(['title' => 'FM Work A']);
    $workB = createWork(['title' => 'FM Work B']);
    $enEntity = fmAlignableEntity('en', $workA, 'FM A En');
    $ruEntity = fmAlignableEntity('ru', $workB, 'FM B Ru');

    // Forged submission: the other entity belongs to another work and would
    // never appear in the candidate select. Filament's own options validation
    // stops it at the form layer; behind that, the module throws
    // CrossWorkEntityPair for any caller that slips a pair past a form
    // (pinned at the module level in EntityMatchCreationTest). Either way a
    // cross-Work submission through this action creates nothing.
    Livewire::actingAs($admin)
        ->test(ListEntities::class)
        ->callTableAction('findMatch', $enEntity, data: [
            'other_entity_id' => $ruEntity->id,
        ])
        ->assertHasTableActionErrors(['other_entity_id']);

    expect(EntityMatch::query()->count())->toBe(0);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});
