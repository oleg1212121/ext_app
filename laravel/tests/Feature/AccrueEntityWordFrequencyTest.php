<?php

use App\Classes\CrosswordLevel;
use App\Models\Entity;
use App\Models\EntityWord;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    createLanguages();
    createWordClasses();
});

/**
 * An entity whose word list is already indexed and linked: a map of
 * l_word => count (count descending order decides entity positions).
 */
function accrualEntity(string $lang, array $entries, array $attributes = []): Entity
{
    $entity = createEntity($lang, attributes: [
        'words_indexed_at' => now()->subHour(),
        ...$attributes,
    ]);

    foreach ($entries as $lWord => $count) {
        $word = Word::query()
            ->where('l_word', $lWord)
            ->whereHas('language', fn ($q) => $q->where('code', $lang))
            ->first();

        EntityWord::query()->create([
            'entity_id' => $entity->id,
            'word_id' => $word?->id,
            'l_word' => $lWord,
            'token' => $lWord,
            'count' => $count,
        ]);
    }

    return $entity;
}

/**
 * Pad the entity word list so the given l_word lands on the wanted
 * position (1-based), counting only linked entries.
 */
function padToPosition(Entity $entity, int $position): void
{
    for ($i = 1; $i < $position; $i++) {
        EntityWord::query()->create([
            'entity_id' => $entity->id,
            'word_id' => ($word = Word::query()->first() ?? createWord('en', 'filler'))->id,
            'l_word' => "filler-{$entity->id}-{$i}",
            'token' => "filler-{$i}",
            'count' => 1000,
        ]);
    }
}

it('pulls 2% of the current rank toward the entity position', function () {
    $word = createWord('en', 'target', 'noun', ['frequency' => 123]);
    $entity = accrualEntity('en', ['target' => 1]);
    padToPosition($entity, 500);

    $this->artisan('words:accrue-entity-frequency', ['--grace' => 0])
        ->assertSuccessful();

    expect($word->refresh()->frequency)->toBe('125.46');
    expect($entity->refresh()->frequency_counted_at)->not->toBeNull();
});

it('pulls corpus-rare ranks up by the full step', function () {
    $word = createWord('en', 'target', 'noun', ['frequency' => 5000]);
    $entity = accrualEntity('en', ['target' => 1]);
    padToPosition($entity, 100);

    $this->artisan('words:accrue-entity-frequency', ['--grace' => 0])
        ->assertSuccessful();

    expect($word->refresh()->frequency)->toBe('4900.00');
});

it('clamps the pull to the exact position when the gap is smaller than the step', function () {
    $word = createWord('en', 'target', 'noun', ['frequency' => 100.5]);
    $entity = accrualEntity('en', ['target' => 1]);
    padToPosition($entity, 100);

    $this->artisan('words:accrue-entity-frequency', ['--grace' => 0])
        ->assertSuccessful();

    expect($word->refresh()->frequency)->toBe('100.00');
});

it('never goes below rank 1', function () {
    $word = createWord('en', 'target', 'noun', ['frequency' => 1.01]);
    accrualEntity('en', ['target' => 5]);

    $this->artisan('words:accrue-entity-frequency', ['--grace' => 0])
        ->assertSuccessful();

    expect($word->refresh()->frequency)->toBe('1.00');
});

it('pulls unranked words out of the unranked marker', function () {
    $word = createWord('en', 'target', 'noun');
    accrualEntity('en', ['target' => 5]);

    expect($word->refresh()->frequency)->toBe(Word::FREQUENCY_UNRANKED.'.00');

    $this->artisan('words:accrue-entity-frequency', ['--grace' => 0])
        ->assertSuccessful();

    expect($word->refresh()->frequency)->toBe('1078000.00');
});

it('applies each entity once and never re-applies', function () {
    $word = createWord('en', 'target', 'noun', ['frequency' => 123]);
    accrualEntity('en', ['target' => 5]);

    $this->artisan('words:accrue-entity-frequency', ['--grace' => 0])->assertSuccessful();
    $afterFirst = $word->refresh()->frequency;

    $this->artisan('words:accrue-entity-frequency', ['--grace' => 0])->assertSuccessful();

    expect($word->refresh()->frequency)->toBe($afterFirst);
});

it('skips entities still inside the grace window', function () {
    $word = createWord('en', 'target', 'noun', ['frequency' => 123]);
    accrualEntity('en', ['target' => 1], ['words_indexed_at' => now()]);

    $this->artisan('words:accrue-entity-frequency')->assertSuccessful();

    expect($word->refresh()->frequency)->toBe('123.00');
    expect(Entity::query()->whereNull('frequency_counted_at')->count())->toBe(1);

    $this->artisan('words:accrue-entity-frequency', ['--grace' => 0])->assertSuccessful();

    expect($word->refresh()->frequency)->toBe('120.54');
});

it('ignores unlinked tokens when counting positions', function () {
    $word = createWord('en', 'target', 'noun');
    $entity = accrualEntity('en', ['unlinked' => 100, 'target' => 1]);

    expect($entity->entityWords()->whereNull('word_id')->count())->toBe(1);

    $this->artisan('words:accrue-entity-frequency', ['--grace' => 0])
        ->assertSuccessful();

    // Position 1, not 2 — the unlinked token takes no slot.
    expect($word->refresh()->frequency)->toBe('1078000.00');
});

it('moves every word class of the headword together', function () {
    $noun = createWord('en', 'target', 'noun', ['frequency' => 123]);
    $verb = createWord('en', 'target', 'verb', ['frequency' => 123]);
    $entity = accrualEntity('en', ['target' => 1]);
    padToPosition($entity, 500);

    $this->artisan('words:accrue-entity-frequency', ['--grace' => 0])
        ->assertSuccessful();

    expect($noun->refresh()->frequency)->toBe('125.46');
    expect($verb->refresh()->frequency)->toBe('125.46');
});

it('honours the per-run limit in entity id order', function () {
    $word = createWord('en', 'target', 'noun', ['frequency' => 123]);
    accrualEntity('en', ['target' => 5]);
    accrualEntity('en', ['target' => 5]);

    $this->artisan('words:accrue-entity-frequency', ['--grace' => 0, '--limit' => 1])
        ->assertSuccessful();

    // One 2% pull applied (first entity only), the second still pending.
    expect($word->refresh()->frequency)->toBe('120.54');
    expect(Entity::query()->whereNull('frequency_counted_at')->count())->toBe(1);
});

it('pulls through --entity regardless of marker or grace', function () {
    $word = createWord('en', 'target', 'noun', ['frequency' => 123]);
    $entity = accrualEntity('en', ['target' => 1], ['words_indexed_at' => now(), 'frequency_counted_at' => now()]);

    $this->artisan('words:accrue-entity-frequency', ['--entity' => [$entity->id]])
        ->assertSuccessful();

    expect($word->refresh()->frequency)->toBe('120.54');
});

it('lets heavily-read unranked words cross the last level cutoff', function () {
    $word = createWord('en', 'target', 'noun');

    for ($i = 0; $i < 5; $i++) {
        accrualEntity('en', ['target' => 1]);
    }

    $this->artisan('words:accrue-entity-frequency', ['--grace' => 0])
        ->assertSuccessful();

    expect((float) $word->refresh()->frequency)
        ->toBeLessThan(CrosswordLevel::cutoff(7));
});
