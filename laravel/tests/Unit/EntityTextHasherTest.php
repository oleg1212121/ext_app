<?php

use App\Classes\EntityTextHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('collapses whitespace runs and trims when normalizing', function () {
    expect(EntityTextHasher::normalize("  Hello \t world \n again  "))
        ->toBe('Hello world again')
        ->and(EntityTextHasher::normalize("Thin\u{00A0}space"))->toBe('Thin space');
});

it('hashes sentences in document order with normalized content', function () {
    $entity = createEntity('en');
    $entity->sentences()->createMany([
        ['sentence_type_id' => null, 'content' => '  First.  ', 'order' => 2048],
        ['sentence_type_id' => null, 'content' => "Second\tone.", 'order' => 1024],
    ]);

    $expected = hash('sha256', "Second one.\nFirst.");

    expect((new EntityTextHasher)->hash($entity))->toBe($expected);
});

it('hashes stored files by raw bytes', function () {
    Storage::fake('local');
    Storage::disk('local')->put('entities/en/a.txt', 'raw bytes');

    expect(EntityTextHasher::hashStoredFile('entities/en/a.txt'))
        ->toBe(hash('sha256', 'raw bytes'));
});

it('treats whitespace-only differences between texts as exact copies', function () {
    $hasher = new EntityTextHasher;

    $a = createEntity('en', null, ['name' => 'A']);
    $a->sentences()->createMany([
        ['sentence_type_id' => null, 'content' => 'One.', 'order' => 1024],
        ['sentence_type_id' => null, 'content' => 'Two.', 'order' => 2048],
    ]);

    $b = createEntity('en', null, ['name' => 'B']);
    $b->sentences()->createMany([
        ['sentence_type_id' => null, 'content' => " One.\t", 'order' => 1024],
        ['sentence_type_id' => null, 'content' => "Two. \n", 'order' => 2048],
    ]);

    expect($hasher->hash($a))->toBe($hasher->hash($b));
});

it('considers the hash stale when never computed or sentences changed after', function () {
    $hasher = new EntityTextHasher;
    $entity = createEntity('en');

    expect($hasher->isStale($entity))->toBeTrue();

    $entity->forceFill(['text_hash' => 'x', 'text_hashed_at' => now()])->save();
    expect($hasher->isStale($entity))->toBeFalse();

    $entity->forceFill(['sentences_updated_at' => now()->addMinute()])->save();
    expect($hasher->isStale($entity))->toBeTrue();
});

it('refreshes a stale hash and pins text_hashed_at to the observed timestamp', function () {
    $hasher = new EntityTextHasher;
    $entity = createEntity('en');
    $entity->sentences()->createMany([
        ['sentence_type_id' => null, 'content' => 'One.', 'order' => 1024],
    ]);

    $observed = Carbon::parse('2026-01-01 12:00:00');
    $entity->forceFill(['sentences_updated_at' => $observed])->save();

    $hash = $hasher->refreshIfStale($entity);

    expect($hash)->toBe($entity->text_hash)
        ->and($entity->text_hashed_at->equalTo($observed))->toBeTrue()
        ->and($hasher->isStale($entity))->toBeFalse();

    // Adding a sentence bumps sentences_updated_at via the model event, so
    // the hash is stale again and the refresh produces a new value.
    $entity->sentences()->create(['sentence_type_id' => null, 'content' => 'Two.', 'order' => 2048]);
    $entity->refresh();

    expect($hasher->isStale($entity))->toBeTrue()
        ->and($hasher->refreshIfStale($entity))->not->toBe($hash);
});
