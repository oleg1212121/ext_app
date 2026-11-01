<?php

use App\Classes\EntityWordAdoption;
use App\Classes\EntityWordIndexer;
use App\Classes\EntityWordLinker;
use App\Classes\EntityWordMap;
use App\Jobs\RefreshEntityWords;
use App\Models\EntitySentence;
use App\Models\EntityWord;
use App\Models\Word;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

function mergeSentence($entity, string $content): EntitySentence
{
    return EntitySentence::query()->create([
        'entity_id' => $entity->id,
        'content' => $content,
        'order' => EntitySentence::query()->where('entity_id', $entity->id)->count() * 1024 + 1024,
    ]);
}

function mergeRows($entity): array
{
    // Raw rows (base builder, no model casts): every column compared exactly
    // as the database stores it — timestamps and all.
    return EntityWord::query()
        ->where('entity_id', $entity->id)
        ->orderBy('l_word')
        ->toBase()
        ->get([
            'id', 'word_id', 'l_word', 'token', 'count', 'unmatchable_at', 'created_at', 'updated_at',
        ])
        ->keyBy('l_word')
        ->all();
}

it('keeps the popup word map populated across an incremental run after an edit', function () {
    // The regression: the old derivation wiped entity_words, so between the
    // index step and the next link pass every surviving word rendered as
    // "not in the dictionary" — the whole book's popups went flat.
    $entity = createEntity('en');
    $first = mergeSentence($entity, 'The old cat sleeps.');
    mergeSentence($entity, 'The dog runs.');
    $cat = createWord('en', 'cat', 'noun');

    app(EntityWordIndexer::class)->index($entity);
    (new EntityWordLinker)->link($entity);

    $catRowId = EntityWord::query()->where('entity_id', $entity->id)->where('l_word', 'cat')->value('id');
    $user = approvedUser();
    expect(app(EntityWordMap::class)->forEntity($entity, $user->id))->toHaveKey('cat');

    $this->travel(1)->hours();
    $first->update(['content' => 'The old cat purrs.']);

    // The completing incremental run — deliberately WITHOUT a link pass:
    // this is the window the outage lived in.
    $stats = app(EntityWordIndexer::class)->indexBatch($entity);

    expect($stats['derived'])->toBeTrue();

    $map = app(EntityWordMap::class)->forEntity($entity->fresh(), $user->id);

    // Every surviving dictionary-linked word is still in the map — same
    // row, same link, no relink needed.
    expect($map)->toHaveKey('cat')
        ->and($map['cat']['w'])->toBe($cat->id)
        ->and(EntityWord::query()->find($catRowId)->word_id)->toBe($cat->id)
        // Vanished token removed, new token present but unlinked (the
        // ordinary pass links it later).
        ->and(EntityWord::query()->where('entity_id', $entity->id)->where('l_word', 'sleeps')->exists())->toBeFalse()
        ->and(EntityWord::query()->where('entity_id', $entity->id)->where('l_word', 'purrs')->value('word_id'))->toBeNull();
});

it('keeps links across the synchronous full pass the same way', function () {
    // Full rebuilds (post-deploy backfill, dictionary re-import sweeps) run
    // the same merge — the outage window must not reopen on that path.
    $entity = createEntity('en');
    $first = mergeSentence($entity, 'Silver water falls.');
    $water = createWord('en', 'water', 'noun');

    app(EntityWordIndexer::class)->index($entity);
    (new EntityWordLinker)->link($entity);

    $rowId = EntityWord::query()->where('entity_id', $entity->id)->where('l_word', 'water')->value('id');
    $user = approvedUser();

    $this->travel(1)->hours();
    $first->update(['content' => 'Silver water flows.']);
    mergeSentence($entity, 'More water.');

    app(EntityWordIndexer::class)->index($entity);

    $map = app(EntityWordMap::class)->forEntity($entity->fresh(), $user->id);

    expect($map)->toHaveKey('water')
        ->and($map['water']['w'])->toBe($water->id)
        ->and(EntityWord::query()->find($rowId)->word_id)->toBe($water->id)
        ->and(EntityWord::query()->where('entity_id', $entity->id)->where('l_word', 'water')->value('count'))->toBe(2)
        ->and(EntityWord::query()->where('entity_id', $entity->id)->where('l_word', 'falls')->exists())->toBeFalse()
        ->and(EntityWord::query()->where('entity_id', $entity->id)->where('l_word', 'flows')->value('word_id'))->toBeNull();
});

it('rewrites only count and representative token when a token survives', function () {
    // Same three keys before and after the edit: cat's count and
    // representative surface change, zqx's count changes, 'the' is
    // untouched — and every row keeps its exact link/stamp/audit columns.
    $entity = createEntity('en');
    $first = mergeSentence($entity, 'Cat zqx.');
    mergeSentence($entity, 'The cat.');

    $cat = createWord('en', 'cat', 'noun');

    app(EntityWordIndexer::class)->index($entity);
    (new EntityWordLinker)->link($entity);

    $before = mergeRows($entity);

    expect($before['cat']->word_id)->toBe($cat->id)
        ->and($before['cat']->token)->toBe('Cat')
        ->and((int) $before['cat']->count)->toBe(2)
        ->and($before['zqx']->unmatchable_at)->not->toBeNull()
        ->and($before['the']->unmatchable_at)->not->toBeNull()
        // The linker's stamp write runs through the Eloquent builder, so the
        // stamped rows carry an updated_at; the linked row's audit columns
        // are null. Whatever they are, the merge must not move them.
        ->and($before['cat']->created_at)->toBeNull()
        ->and($before['cat']->updated_at)->toBeNull();

    $this->travel(1)->hours();
    $first->update(['content' => 'CAT zqx zqx.']);

    app(EntityWordIndexer::class)->index($entity);

    $after = mergeRows($entity);

    // Presence unchanged: same three rows, same ids — word_id,
    // unmatchable_at and the audit columns preserved verbatim.
    expect(array_keys($after))->toBe(array_keys($before))
        ->and($after['cat']->id)->toBe($before['cat']->id)
        ->and($after['zqx']->id)->toBe($before['zqx']->id)
        ->and($after['the']->id)->toBe($before['the']->id)
        ->and($after['cat']->word_id)->toBe($cat->id)
        ->and($after['zqx']->unmatchable_at)->toEqual($before['zqx']->unmatchable_at)
        ->and($after['the']->unmatchable_at)->toEqual($before['the']->unmatchable_at)
        ->and($after['zqx']->updated_at)->toEqual($before['zqx']->updated_at)
        ->and($after['zqx']->created_at)->toBeNull()
        ->and($after['cat']->updated_at)->toBeNull();

    // Only the derived fields moved: cat's representative surface and zqx's
    // count; 'the' is byte-identical.
    expect($after['cat']->token)->toBe('CAT')
        ->and((int) $after['cat']->count)->toBe(2)
        ->and((int) $after['zqx']->count)->toBe(2)
        ->and($after['the'])->toEqual($before['the']);

    // Idempotent: a full pass with nothing stale re-merges and the rows are
    // untouched to the last column.
    app(EntityWordIndexer::class)->index($entity);

    expect(mergeRows($entity))->toEqual($after);
});

it('deletes removed tokens and links reintroduced ones through the ordinary pass', function () {
    $entity = createEntity('en');
    $sentence = mergeSentence($entity, 'Cat dog.');
    createWord('en', 'cat', 'noun');
    $dog = createWord('en', 'dog', 'noun');

    app(EntityWordIndexer::class)->index($entity);
    (new EntityWordLinker)->link($entity);

    // The edit removes 'dog': its row (link and all) is deleted.
    $this->travel(1)->hours();
    $sentence->update(['content' => 'Cat bird.']);

    app(EntityWordIndexer::class)->index($entity);

    $rows = mergeRows($entity);

    expect(array_keys($rows))->toBe(['bird', 'cat'])
        ->and($rows['bird']->word_id)->toBeNull()
        ->and($rows['bird']->unmatchable_at)->toBeNull();

    // A later edit reintroduces 'dog': it re-enters unlinked (the merge has
    // no memory of the deleted row) and the ordinary link pass picks it up.
    $this->travel(1)->hours();
    $sentence->update(['content' => 'Cat dog.']);

    app(EntityWordIndexer::class)->index($entity);

    $dogRow = EntityWord::query()->where('entity_id', $entity->id)->where('l_word', 'dog')->first();

    expect($dogRow->word_id)->toBeNull()
        ->and($dogRow->unmatchable_at)->toBeNull();

    (new EntityWordLinker)->link($entity);

    expect($dogRow->refresh()->word_id)->toBe($dog->id)
        ->and(EntityWord::query()->where('entity_id', $entity->id)->where('l_word', 'bird')->exists())->toBeFalse();
});

it('preserves a completed link and adoption pass across a later refresh', function () {
    Http::fake();

    $entity = createEntity('en');
    $sentence = mergeSentence($entity, 'The cat sleeps.');
    $cat = createWord('en', 'cat', 'noun');

    $runJob = fn () => (new RefreshEntityWords($entity->id))
        ->handle(app(EntityWordIndexer::class), app(EntityWordLinker::class), app(EntityWordAdoption::class));

    // First refresh: 'cat' links; 'the'/'sleeps' are stamped unmatchable and
    // then adopted onto minted unknown-class dictionary rows.
    $runJob();

    $adopted = EntityWord::query()
        ->where('entity_id', $entity->id)
        ->whereIn('l_word', ['the', 'sleeps'])
        ->get()
        ->mapWithKeys(fn ($row) => [$row->l_word => $row->word_id]);

    expect($adopted)->toHaveCount(2)
        ->and($adopted['the'])->not->toBeNull()
        ->and($adopted['sleeps'])->not->toBeNull()
        ->and(EntityWord::query()->where('entity_id', $entity->id)->where('l_word', 'cat')->value('word_id'))->toBe($cat->id);

    $before = mergeRows($entity);

    // Second refresh after an edit: surviving keys keep the pass's results.
    $this->travel(1)->hours();
    $sentence->update(['content' => 'The cat purrs.']);

    $runJob();

    $rows = EntityWord::query()->where('entity_id', $entity->id)->get()->keyBy('l_word');

    expect($rows->contains(fn ($row) => $row->l_word === 'sleeps'))->toBeFalse()
        ->and($rows['the']->word_id)->toBe($before['the']->word_id)
        ->and($rows['the']->unmatchable_at)->toBeNull()
        ->and($rows['cat']->word_id)->toBe($cat->id)
        // The minted word rows stay in the dictionary, adopted as before.
        ->and(Word::query()->find($before['the']->word_id)->exists())->toBeTrue()
        // The new token went through the ordinary passes like any debut.
        ->and($rows['purrs']->word_id)->not->toBeNull()
        ->and($rows['purrs']->unmatchable_at)->toBeNull();

    // Third refresh with nothing stale: idempotent, nothing rewritten.
    $idsBefore = EntityWord::query()->where('entity_id', $entity->id)->orderBy('id')->pluck('id')->all();
    $beforeThird = mergeRows($entity);

    $runJob();

    expect(mergeRows($entity))->toEqual($beforeThird)
        ->and(EntityWord::query()->where('entity_id', $entity->id)->orderBy('id')->pluck('id')->all())->toBe($idsBefore);
});
