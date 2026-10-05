<?php

use App\Classes\EntitySentenceStore;
use App\Enums\SentenceAnchor;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;

if (! function_exists('sentenceStore')) {
    function sentenceStore(): EntitySentenceStore
    {
        return app(EntitySentenceStore::class);
    }
}

if (! function_exists('sentenceTypeId')) {
    function sentenceTypeId(): int
    {
        return (int) SentenceType::where('name', 'sentence')->value('id');
    }
}

beforeEach(function () {
    createLanguages();
    SentenceType::firstOrCreate(
        ['name' => 'sentence'],
        ['description' => 'A standard sentence'],
    );
});

// ─── insert ──────────────────────────────────────────────────────────────────

it('inserts at the anchor and flips completed matches stale with resynced totals in one flow', function () {
    $work = createWork();
    $entity = createEntity('en', $work, ['name' => 'Aligned']);
    $match = createEntityMatch($entity, createEntity('ru', $work, ['name' => 'Pair']), [
        'status' => 'completed',
        'a_total_sentences' => 99,
        'b_total_sentences' => 99,
    ]);

    $entity->forceFill(['sentences_updated_at' => now()->subDay()])->save();

    $sentence = sentenceStore()->insert($entity, [
        'content' => 'Added',
        'sentence_type_id' => sentenceTypeId(),
    ], SentenceAnchor::end());

    expect($sentence->exists)->toBeTrue()
        ->and($sentence->order)->toBe(0)
        ->and($match->refresh()->status)->toBe('stale')
        ->and($match->a_total_sentences)->toBe(1)
        ->and($entity->refresh()->sentences_updated_at->timestamp)->toBeGreaterThan(now()->subHour()->timestamp);
});

it('flips aligning and failed matches stale but leaves a fresh pending match pending (ADR 0055)', function () {
    $work = createWork();
    $entity = createEntity('en', $work, ['name' => 'Aligned']);

    $aligning = createEntityMatch($entity, createEntity('ru', $work, ['name' => 'A']), ['status' => 'aligning']);
    $failed = createEntityMatch($entity, createEntity('ru', $work, ['name' => 'B']), ['status' => 'failed']);
    $pending = createEntityMatch($entity, createEntity('ru', $work, ['name' => 'C']), ['status' => 'pending']);

    sentenceStore()->insert($entity, [
        'content' => 'Added',
        'sentence_type_id' => sentenceTypeId(),
    ], SentenceAnchor::end());

    expect($aligning->refresh()->status)->toBe('stale')
        ->and($failed->refresh()->status)->toBe('stale')
        ->and($pending->refresh()->status)->toBe('pending');
});

it('rolls the insert flow back when the anchor cannot be resolved', function () {
    $work = createWork();
    $entity = createEntity('en', $work, ['name' => 'Aligned']);
    $match = createEntityMatch($entity, createEntity('ru', $work, ['name' => 'Pair']), [
        'status' => 'completed',
        'a_total_sentences' => 99,
        'b_total_sentences' => 99,
    ]);

    try {
        sentenceStore()->insert($entity, [
            'content' => 'Doomed',
            'sentence_type_id' => sentenceTypeId(),
        ], SentenceAnchor::after(999999));
    } catch (ModelNotFoundException) {
        // expected
    }

    expect(EntitySentence::query()->where('entity_id', $entity->id)->count())->toBe(0)
        ->and($match->refresh()->status)->toBe('completed')
        ->and($match->a_total_sentences)->toBe(99);
});

it('rolls the insert flow back when the write fails after placement', function () {
    $work = createWork();
    $entity = createEntity('en', $work, ['name' => 'Aligned']);
    $match = createEntityMatch($entity, createEntity('ru', $work, ['name' => 'Pair']), [
        'status' => 'completed',
        'a_total_sentences' => 99,
        'b_total_sentences' => 99,
    ]);

    try {
        // An unknown sentence_type_id fails the FK at the model write —
        // after placement has already renumbered the neighbourhood.
        sentenceStore()->insert($entity, [
            'content' => 'Doomed',
            'sentence_type_id' => 987654,
        ], SentenceAnchor::end());
        $this->fail('The insert should have failed on the sentence_type_id foreign key.');
    } catch (Throwable) {
        // expected
    }

    expect(EntitySentence::query()->where('entity_id', $entity->id)->count())->toBe(0)
        ->and($match->refresh()->status)->toBe('completed')
        ->and($match->a_total_sentences)->toBe(99);
});

it('ignores a stray image on a non-illustration insert', function () {
    $entity = createEntity('en', null, ['name' => 'Plain']);

    $sentence = sentenceStore()->insert($entity, [
        'content' => 'Not an illustration',
        'sentence_type_id' => sentenceTypeId(),
    ], SentenceAnchor::end(), UploadedFile::fake()->image('stray.png'));

    expect($sentence->image_path)->toBeNull();
});

// ─── update ──────────────────────────────────────────────────────────────────

it('updates content and flips the match stale with resynced totals', function () {
    $work = createWork();
    $entity = createEntity('en', $work, ['name' => 'Aligned']);
    $match = createEntityMatch($entity, createEntity('ru', $work, ['name' => 'Pair']), [
        'status' => 'completed',
        'a_total_sentences' => 99,
        'b_total_sentences' => 99,
    ]);

    $sentence = EntitySentence::create([
        'entity_id' => $entity->id,
        'content' => 'Original',
        'order' => 0,
        'sentence_type_id' => sentenceTypeId(),
    ]);

    $updated = sentenceStore()->update($sentence, [
        'content' => 'Changed',
        'sentence_type_id' => sentenceTypeId(),
    ]);

    expect($updated->content)->toBe('Changed')
        ->and($match->refresh()->status)->toBe('stale')
        ->and($match->a_total_sentences)->toBe(1);
});

it('re-places the sentence when an anchor is given', function () {
    $entity = createEntity('en', null, ['name' => 'Reordered']);

    $first = EntitySentence::create(['entity_id' => $entity->id, 'content' => 'First', 'order' => 0, 'sentence_type_id' => sentenceTypeId()]);
    $second = EntitySentence::create(['entity_id' => $entity->id, 'content' => 'Second', 'order' => 1024, 'sentence_type_id' => sentenceTypeId()]);

    sentenceStore()->update($second, [
        'content' => 'Second',
        'sentence_type_id' => sentenceTypeId(),
    ], SentenceAnchor::beginning());

    expect($second->refresh()->order)->toBeLessThan($first->refresh()->order);
});

// ─── delete ──────────────────────────────────────────────────────────────────

it('deletes with the junction cascade, stale flip and totals resync in one flow', function () {
    $work = createWork();
    $entity = createEntity('en', $work, ['name' => 'Cascade']);
    $match = createEntityMatch($entity, createEntity('ru', $work, ['name' => 'Pair']), [
        'status' => 'completed',
        'linked_count' => 1,
        'a_total_sentences' => 99,
        'b_total_sentences' => 99,
    ]);

    $sentence = EntitySentence::create(['entity_id' => $entity->id, 'content' => 'Linked', 'order' => 0, 'sentence_type_id' => sentenceTypeId()]);
    $meaningMatch = MeaningMatch::create(['entity_match_id' => $match->id, 'order' => 0, 'similarity' => 0.5]);
    SentenceMeaningMatch::create(['entity_sentence_id' => $sentence->id, 'meaning_match_id' => $meaningMatch->id, 'side' => 'a']);

    sentenceStore()->delete($sentence);

    expect(EntitySentence::find($sentence->id))->toBeNull()
        ->and(MeaningMatch::find($meaningMatch->id))->toBeNull()
        ->and($match->refresh()->linked_count)->toBe(0)
        ->and($match->status)->toBe('stale')
        ->and($match->a_total_sentences)->toBe(0);
});

it('bulk-deletes many sentences and propagates once per touched entity', function () {
    $work = createWork();
    $entity = createEntity('en', $work, ['name' => 'Bulk A']);
    $matchA = createEntityMatch($entity, createEntity('ru', $work, ['name' => 'Pair A']), ['status' => 'completed']);
    $matchB = createEntityMatch(createEntity('ru', $work, ['name' => 'Bulk B']), createEntity('ru', $work, ['name' => 'Pair B']), ['status' => 'completed']);

    $a1 = EntitySentence::create(['entity_id' => $entity->id, 'content' => 'A1', 'order' => 0, 'sentence_type_id' => sentenceTypeId()]);
    $a2 = EntitySentence::create(['entity_id' => $entity->id, 'content' => 'A2', 'order' => 1024, 'sentence_type_id' => sentenceTypeId()]);
    $b1 = EntitySentence::create(['entity_id' => $matchB->b_entity_id, 'content' => 'B1', 'order' => 0, 'sentence_type_id' => sentenceTypeId()]);

    sentenceStore()->deleteMany([$a1, $a2, $b1]);

    expect(EntitySentence::find($a1->id))->toBeNull()
        ->and(EntitySentence::find($a2->id))->toBeNull()
        ->and(EntitySentence::find($b1->id))->toBeNull()
        ->and($matchA->refresh()->status)->toBe('stale')
        ->and($matchB->refresh()->status)->toBe('stale');
});

// ─── reorder ─────────────────────────────────────────────────────────────────

it('reorders to the anchor position and flips the match stale', function () {
    $work = createWork();
    $entity = createEntity('en', $work, ['name' => 'Reorder']);
    $match = createEntityMatch($entity, createEntity('ru', $work, ['name' => 'Pair']), ['status' => 'completed']);

    $first = EntitySentence::create(['entity_id' => $entity->id, 'content' => 'First', 'order' => 0, 'sentence_type_id' => sentenceTypeId()]);
    $second = EntitySentence::create(['entity_id' => $entity->id, 'content' => 'Second', 'order' => 1024, 'sentence_type_id' => sentenceTypeId()]);

    $moved = sentenceStore()->reorder($second, SentenceAnchor::beginning());

    expect($moved->order)->toBeLessThan($first->refresh()->order)
        ->and($match->refresh()->status)->toBe('stale');
});
