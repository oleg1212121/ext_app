<?php

use App\Jobs\GenerateEntitySignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

function signatureJobEntityId(object $job): int
{
    return Closure::bind(fn (): int => $this->entityId, $job, $job::class)();
}

it('caps the number of dispatched signature jobs with the limit option', function () {
    Bus::fake();

    $entities = collect(range(1, 5))->map(fn (int $i) => createEntity('en', null, [
        'name' => "Entity {$i}",
        'file_path' => "entities/en/entity-{$i}.txt",
    ]));

    $this->artisan('entity:generate-signatures --limit=3')->assertSuccessful();

    Bus::assertDispatched(GenerateEntitySignature::class, 3);

    $dispatchedIds = collect(Bus::dispatched(GenerateEntitySignature::class))
        ->map(fn ($job) => signatureJobEntityId($job))
        ->sort()
        ->values();

    expect($dispatchedIds)->toEqual($entities->take(3)->pluck('id')->sort()->values());
});

it('skips entities that already have a signature', function () {
    Bus::fake();

    createEntity('en', null, ['name' => 'Signed', 'file_path' => 'entities/en/signed.txt', 'signature' => json_encode([1.0])]);
    $unsigned = createEntity('en', null, ['name' => 'Unsigned', 'file_path' => 'entities/en/unsigned.txt']);

    $this->artisan('entity:generate-signatures')->assertSuccessful();

    Bus::assertDispatched(GenerateEntitySignature::class, 1);
    expect(signatureJobEntityId(Bus::dispatched(GenerateEntitySignature::class)[0]))
        ->toBe($unsigned->id);
});
