<?php

use App\Classes\EntityWordIndexer;
use App\Classes\EntityWordLinker;
use App\Jobs\RefreshEntityWords;
use App\Models\EntitySentence;
use App\Models\EntityWord;
use App\Models\Form;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function linkerSentence($entity, string $content): EntitySentence
{
    return EntitySentence::query()->create([
        'entity_id' => $entity->id,
        'content' => $content,
        'order' => EntitySentence::query()->where('entity_id', $entity->id)->count() * 1024 + 1024,
    ]);
}

function linkerWords($entity): Collection
{
    return EntityWord::query()->where('entity_id', $entity->id)->orderBy('id')->get();
}

it('stamps tokens without a dictionary match so later runs skip them', function () {
    $entity = createEntity('en');
    linkerSentence($entity, 'The cat sleeps. The dog barks.');
    createWord('en', 'cat', 'noun');
    createWord('en', 'dog', 'verb');
    app(EntityWordIndexer::class)->index($entity);

    $linker = new EntityWordLinker;

    // Unique tokens: the, cat, sleeps, dog, barks — cat and dog link.
    $first = $linker->link($entity);
    expect($first['linked'])->toBe(2)
        ->and($first['unmatched'])->toBe(3)
        ->and($first['budget_exhausted'])->toBeFalse();

    $unlinked = linkerWords($entity)->whereNull('word_id');
    expect($unlinked)->toHaveCount(3)
        ->and($unlinked->pluck('l_word')->sort()->values()->toArray())->toEqual(['barks', 'sleeps', 'the'])
        ->and($unlinked->pluck('unmatchable_at')->every(fn ($stamp) => $stamp !== null))->toBeTrue();

    // The second run skips the stamped rows entirely — nothing re-scanned.
    $second = $linker->link($entity);
    expect($second['linked'])->toBe(0)
        ->and($second['unmatched'])->toBe(0);
});

it('clears unmatchable stamps per language so an import re-attempts them', function () {
    $en = createEntity('en');
    $ru = createEntity('ru');
    linkerSentence($en, 'The cat sat.');
    linkerSentence($ru, 'Кот сидел.');
    app(EntityWordIndexer::class)->index($en);
    app(EntityWordIndexer::class)->index($ru);
    (new EntityWordLinker)->link($en);
    (new EntityWordLinker)->link($ru);

    expect(EntityWordLinker::clearUnmatchedForLanguage($en->language_id))->toBeGreaterThan(0);

    expect(linkerWords($en)->whereNull('word_id')->pluck('unmatchable_at')->every(fn ($stamp) => $stamp === null))->toBeTrue()
        ->and(linkerWords($ru)->whereNull('word_id')->pluck('unmatchable_at')->every(fn ($stamp) => $stamp !== null))->toBeTrue();
});

it('examines only the run budget window and reports the exhaustion', function () {
    $entity = createEntity('en');
    linkerSentence($entity, 'One two three four five.');
    app(EntityWordIndexer::class)->index($entity);
    // No dictionary words at all: every token is unmatched.

    $linker = new EntityWordLinker(maxRowsPerRun: 3);

    $first = $linker->link($entity);
    expect($first['budget_exhausted'])->toBeTrue()
        ->and($first['unmatched'])->toBe(3);

    expect(linkerWords($entity)->whereNotNull('unmatchable_at')->count())->toBe(3);

    $untouched = linkerWords($entity)->whereNull('unmatchable_at')->count();
    expect($untouched)->toBe(2);

    $second = $linker->link($entity);
    expect($second['budget_exhausted'])->toBeFalse()
        ->and($second['unmatched'])->toBe($untouched);
});

it('caps inflected-form candidates per token', function () {
    $entity = createEntity('en');
    linkerSentence($entity, 'Running.');
    app(EntityWordIndexer::class)->index($entity);
    $token = linkerWords($entity)->firstOrFail();

    // 250 lemmas share the surface form "running" via forms.l_word — well
    // past the per-token candidate cap. All 'unknown' class: the best-candidate
    // pick must still resolve to one of them within the capped window.
    $words = collect(range(1, 250))->map(fn (int $i) => createWord('en', "Lemma {$i}", 'unknown'));
    Form::query()->insert($words->map(fn (Word $word): array => [
        'form' => 'Running',
        'l_word' => 'running',
        'word_id' => $word->id,
    ])->all());

    $stats = (new EntityWordLinker)->link($entity);

    expect($stats['linked'])->toBe(1)
        ->and($words->pluck('id')->contains($token->refresh()->word_id))->toBeTrue();
});

it('sweep skips entities whose unlinked tokens are all stamped unmatchable', function () {
    Queue::fake();

    $entity = createEntity('en');
    linkerSentence($entity, 'The cat sat.');
    createWord('en', 'cat', 'noun');
    app(EntityWordIndexer::class)->index($entity);
    (new EntityWordLinker)->link($entity);

    expect(linkerWords($entity)->whereNull('word_id')->whereNotNull('unmatchable_at')->count())->toBeGreaterThan(0);

    $this->artisan('crossword:refresh')->assertSuccessful();

    Queue::assertNotPushed(RefreshEntityWords::class);
});

it('retry-unmatched clears stamps so a grown dictionary can link them', function () {
    $entity = createEntity('en');
    linkerSentence($entity, 'The cat sat.');
    createWord('en', 'cat', 'noun');
    app(EntityWordIndexer::class)->index($entity);
    (new EntityWordLinker)->link($entity);

    expect(linkerWords($entity)->whereNull('word_id')->whereNotNull('unmatchable_at')->count())->toBe(2);

    // The dictionary grows ("sat" is imported) — retry re-attempts the
    // stamped tokens; "sat" links, "the" stays unmatched and re-stamped.
    createWord('en', 'sat', 'verb');

    $this->artisan('crossword:link --retry-unmatched')->assertSuccessful();

    expect(linkerWords($entity)->where('l_word', 'sat')->value('word_id'))->not->toBeNull()
        ->and(linkerWords($entity)->where('l_word', 'the')->whereNull('word_id'))->not->toBeEmpty()
        ->and(linkerWords($entity)->where('l_word', 'the')->value('unmatchable_at'))->not->toBeNull();
});
