<?php

use App\Classes\EntityWordAdoption;
use App\Classes\EntityWordIndexer;
use App\Classes\EntityWordLinker;
use App\Jobs\RefreshEntityWords;
use App\Models\EntitySentence;
use App\Models\EntityWord;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function runRefreshJob($entity): void
{
    (new RefreshEntityWords($entity->id))
        ->handle(app(EntityWordIndexer::class), app(EntityWordLinker::class), app(EntityWordAdoption::class));
}

function refreshSentence($entity, string $content, ?int $order = null): EntitySentence
{
    return EntitySentence::query()->create([
        'entity_id' => $entity->id,
        'content' => $content,
        'order' => $order ?? 1024,
    ]);
}

function runRefreshJobWithBudget($entity, int $cursor, int $budget): void
{
    (new RefreshEntityWords($entity->id, cursor: $cursor, sentenceBudget: $budget))
        ->handle(app(EntityWordIndexer::class), app(EntityWordLinker::class), app(EntityWordAdoption::class));
}

it('builds and links the word list for a stale entity', function () {
    $entity = createEntity('en');
    refreshSentence($entity, 'The cat sleeps. The dog barks.');
    $catNoun = createWord('en', 'cat', 'noun');
    createWord('en', 'cat', 'unknown');
    createWord('en', 'dog', 'verb');

    runRefreshJob($entity);

    $rows = EntityWord::query()->where('entity_id', $entity->id)->get()->keyBy('l_word');
    expect($rows)->toHaveCount(5);
    // Noun wins over the same lowercase form's other word classes.
    expect($rows['cat']->word_id)->toBe($catNoun->id);
    expect($rows['dog']->word_id)->not->toBeNull();
    // 'the' has no dictionary entry: the linker stamps it unmatchable and
    // the adoption pass creates an 'unknown'-class word so the token is
    // still clickable in the simulator.
    expect($rows['the']->word_id)->not->toBeNull();
    expect(Word::query()->findOrFail($rows['the']->word_id)->wordClass->slug)->toBe('unknown');
    expect($entity->refresh()->words_indexed_at)->not->toBeNull();
});

it('does not re-index when the list is fresh but still links unlinked tokens', function () {
    $entity = createEntity('en');
    refreshSentence($entity, 'The cat sleeps.');
    $cat = createWord('en', 'cat', 'noun');

    app(EntityWordIndexer::class)->index($entity);

    $idsBefore = EntityWord::query()->where('entity_id', $entity->id)->orderBy('id')->pluck('id')->all();

    runRefreshJob($entity);

    // No wholesale rebuild: the row ids survive.
    expect(EntityWord::query()->where('entity_id', $entity->id)->orderBy('id')->pluck('id')->all())->toBe($idsBefore);
    // But the link pass ran.
    expect(EntityWord::query()->where('entity_id', $entity->id)->where('l_word', 'cat')->value('word_id'))->toBe($cat->id);
});

it('is idempotent on a second run', function () {
    $entity = createEntity('en');
    refreshSentence($entity, 'The cat sleeps.');
    createWord('en', 'cat', 'noun');

    runRefreshJob($entity);
    $before = EntityWord::query()->where('entity_id', $entity->id)->orderBy('id')->get(['id', 'word_id'])->toJson();
    runRefreshJob($entity);
    $after = EntityWord::query()->where('entity_id', $entity->id)->orderBy('id')->get(['id', 'word_id'])->toJson();

    expect($after)->toBe($before);
});

it('skips a missing entity without failing', function () {
    (new RefreshEntityWords(999999))->handle(app(EntityWordIndexer::class), app(EntityWordLinker::class), app(EntityWordAdoption::class));

    expect(EntityWord::query()->count())->toBe(0);
});

it('chains stale sentences through self re-dispatch within the per-run budget', function () {
    Queue::fake();

    $entity = createEntity('en');
    $sentences = collect(['One two.', 'Three four.', 'Five six.'])
        ->map(fn (string $content) => refreshSentence(
            $entity,
            $content,
            EntitySentence::query()->where('entity_id', $entity->id)->count() * 1024 + 1024,
        ));

    runRefreshJobWithBudget($entity, cursor: 0, budget: 2);

    // Mid-chain the entity is still building: no marker, nothing derived.
    expect($entity->fresh()->words_indexed_at)->toBeNull()
        ->and(EntityWord::query()->where('entity_id', $entity->id)->count())->toBe(0);

    Queue::assertPushed(RefreshEntityWords::class, 1);

    $chained = Queue::pushed(RefreshEntityWords::class)->first();
    [$chainedEntityId, $chainedCursor, $chainedBudget] = Closure::bind(
        fn (): array => [$this->entityId, $this->cursor, $this->sentenceBudget],
        $chained,
        $chained::class,
    )();

    expect($chainedEntityId)->toBe($entity->id)
        ->and($chainedCursor)->toBe($sentences[1]->id)
        ->and($chainedBudget)->toBe(2);

    // The continuation finishes the chain: no further dispatch, the
    // wholesale derivation runs, and the marker lands as the max stamp.
    runRefreshJobWithBudget($entity, cursor: $chainedCursor, budget: 2);

    Queue::assertPushed(RefreshEntityWords::class, 1);

    $maxStamp = EntitySentence::query()->where('entity_id', $entity->id)->max('words_indexed_at');

    expect(EntityWord::query()->where('entity_id', $entity->id)->count())->toBe(6)
        ->and($entity->fresh()->words_indexed_at->equalTo($maxStamp))->toBeTrue();
});

it('does not chain when the run consumed the last stale sentence', function () {
    Queue::fake();

    $entity = createEntity('en');
    refreshSentence($entity, 'The cat sleeps.');

    runRefreshJobWithBudget($entity, cursor: 0, budget: 10);

    Queue::assertNothingPushed();
    expect($entity->fresh()->words_indexed_at)->not->toBeNull();
});

it('logs per-run stats including the chain state', function () {
    Log::spy();

    $entity = createEntity('en');
    refreshSentence($entity, 'The cat sleeps. The dog barks.');
    createWord('en', 'cat', 'noun');

    runRefreshJob($entity);

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
        return $message === 'RefreshEntityWords completed'
            && ($context['reindexed'] ?? false) === true
            && ($context['indexed'] ?? 0) === 1
            && ($context['chained'] ?? false) === false // array_filter drops the falsy flag
            && ($context['linked'] ?? 0) >= 1
            && array_key_exists('unique_words', $context)
            && array_key_exists('total_ms', $context);
    });
});
