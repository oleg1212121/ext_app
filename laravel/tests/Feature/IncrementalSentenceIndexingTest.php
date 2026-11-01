<?php

use App\Classes\EntityWordIndexer;
use App\Models\EntitySentence;
use App\Models\EntitySentenceWord;
use App\Models\EntityWord;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

function addIndexSentence($entity, string $content): EntitySentence
{
    return EntitySentence::query()->create([
        'entity_id' => $entity->id,
        'content' => $content,
        'order' => EntitySentence::query()->where('entity_id', $entity->id)->count() * 1024 + 1024,
    ]);
}

/**
 * Row identity (id) plus content: untouched sentences must keep their
 * exact rows — a re-insert would produce identical columns but new ids.
 */
function occurrenceRows(int $sentenceId): array
{
    return EntitySentenceWord::query()
        ->where('entity_sentence_id', $sentenceId)
        ->orderBy('position')
        ->get()
        ->map(fn ($row) => $row->only(['id', 'position', 'start', 'end', 'l_word', 'token']))
        ->all();
}

it('re-tokenizes and re-stamps only the edited sentence', function () {
    $entity = createEntity('en');
    $first = addIndexSentence($entity, 'Alpha beta gamma.');
    $second = addIndexSentence($entity, 'Delta epsilon.');
    $third = addIndexSentence($entity, 'Zeta eta theta.');

    app(EntityWordIndexer::class)->index($entity);

    $firstRows = occurrenceRows($first->id);
    $thirdRows = occurrenceRows($third->id);
    $firstStamp = $first->refresh()->words_indexed_at;
    $thirdStamp = $third->refresh()->words_indexed_at;

    $this->travel(1)->hours();
    $second->update(['content' => 'Delta epsilon omega.']);

    $stats = app(EntityWordIndexer::class)->indexBatch($entity);

    expect($stats['processed'])->toBe(1)
        ->and($stats['remaining'])->toBeFalse()
        ->and($stats['derived'])->toBeTrue();

    // The edited sentence's rows reflect its new content; its stamp is its
    // own updated_at (never the index time).
    expect(array_column(occurrenceRows($second->id), 'l_word'))->toBe(['delta', 'epsilon', 'omega'])
        ->and($second->refresh()->words_indexed_at->equalTo($second->updated_at))->toBeTrue();

    // Every other sentence: byte-identical rows (same DB ids) and an
    // untouched stamp.
    expect(occurrenceRows($first->id))->toBe($firstRows)
        ->and(occurrenceRows($third->id))->toBe($thirdRows)
        ->and($first->refresh()->words_indexed_at->equalTo($firstStamp))->toBeTrue()
        ->and($third->refresh()->words_indexed_at->equalTo($thirdStamp))->toBeTrue();

    // The entity marker is the derived max over the per-sentence stamps —
    // here the re-stamped sentence's own updated_at.
    expect($entity->refresh()->words_indexed_at->equalTo($second->refresh()->words_indexed_at))->toBeTrue();
});

it('indexes a fresh entity as a chain of bounded runs that derives only at the end', function () {
    // Sentences that pre-date the feature: every stamp null → every sentence
    // stale — the full-backfill shape of a fresh upload.
    $entity = createEntity('en');
    $sentences = collect([
        'One two three.',
        'Four five.',
        'Six seven eight.',
        'Nine ten.',
        'Eleven twelve.',
    ])->map(fn (string $content) => addIndexSentence($entity, $content));

    $indexer = app(EntityWordIndexer::class);

    $run1 = $indexer->indexBatch($entity, afterId: 0, budget: 2);

    expect($run1)->toBe([
        'processed' => 2,
        'cursor' => $sentences[1]->id,
        'remaining' => true,
        'derived' => false,
        'unique_words' => null,
    ]);

    // Mid-chain: nothing derived, no entity marker — the word list is not
    // readable as finished while the chain is still running.
    expect(EntityWord::query()->where('entity_id', $entity->id)->count())->toBe(0)
        ->and($entity->fresh()->words_indexed_at)->toBeNull();

    $run2 = $indexer->indexBatch($entity, afterId: $run1['cursor'], budget: 2);

    expect($run2['processed'])->toBe(2)
        ->and($run2['cursor'])->toBe($sentences[3]->id)
        ->and($run2['remaining'])->toBeTrue()
        ->and($run2['derived'])->toBeFalse();

    $run3 = $indexer->indexBatch($entity, afterId: $run2['cursor'], budget: 2);

    expect($run3['processed'])->toBe(1)
        ->and($run3['remaining'])->toBeFalse()
        ->and($run3['derived'])->toBeTrue()
        ->and($run3['unique_words'])->toBe(EntityWord::query()->where('entity_id', $entity->id)->count());

    // Chain complete: every sentence stamped and the entity marker is the
    // max per-sentence stamp.
    $maxStamp = EntitySentence::query()->where('entity_id', $entity->id)->max('words_indexed_at');

    expect($sentences->every(fn ($sentence) => $sentence->refresh()->words_indexed_at !== null))->toBeTrue()
        ->and($entity->fresh()->words_indexed_at->equalTo(Carbon::parse($maxStamp)))->toBeTrue();

    // The wholesale derivation over the rows is the same list a full build
    // produces: 12 unique tokens, occurrence counts summed per key.
    $totalOccurrences = EntityWord::query()->where('entity_id', $entity->id)->sum('count');

    expect(EntityWord::query()->where('entity_id', $entity->id)->count())->toBe(12)
        ->and((int) $totalOccurrences)->toBe(12);
});

it('treats a processed-nothing run on an unmarked entity as a completing run', function () {
    // A chain that died after stamping everything but before the marker
    // write is impossible (one transaction), but a sentence-less entity
    // dispatched by the --entity override lands here: the run heals the
    // marker with the now() fallback instead of leaving the entity unlocked.
    $entity = createEntity('en'); // no sentences, never indexed

    $stats = app(EntityWordIndexer::class)->indexBatch($entity);

    expect($stats['processed'])->toBe(0)
        ->and($stats['remaining'])->toBeFalse()
        ->and($stats['derived'])->toBeTrue()
        ->and($entity->refresh()->words_indexed_at)->not->toBeNull();
});

it('makes a processed-nothing run on a marked entity a full no-op', function () {
    // A duplicate run over a fully-fresh entity must not re-derive (row ids
    // survive) nor touch the marker — the old job's staleness-skip shape.
    $entity = createEntity('en');
    addIndexSentence($entity, 'Alpha beta.');

    app(EntityWordIndexer::class)->index($entity);

    $wordIds = EntityWord::query()->where('entity_id', $entity->id)->orderBy('id')->pluck('id')->all();
    $marker = $entity->refresh()->words_indexed_at;

    $this->travel(1)->hours();

    $stats = app(EntityWordIndexer::class)->indexBatch($entity);

    expect($stats['processed'])->toBe(0)
        ->and($stats['derived'])->toBeFalse()
        ->and(EntityWord::query()->where('entity_id', $entity->id)->orderBy('id')->pluck('id')->all())->toBe($wordIds)
        ->and($entity->refresh()->words_indexed_at->equalTo($marker))->toBeTrue();
});
