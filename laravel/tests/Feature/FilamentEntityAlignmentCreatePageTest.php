<?php

use App\Filament\Resources\EntityMatchResource\Pages\CreateEntityMatch;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

test('filament alignment create page lists entities without signature or sentences', function () {
    $user = User::factory()->create();

    $work = createWork();
    $entity = createEntity('en', $work, [
        'name' => 'Freshly Created English Entity',
        'description' => null,
        'signature' => null,
        'file_path' => null,
    ]);

    Livewire::actingAs($user)
        ->test(CreateEntityMatch::class)
        ->assertSuccessful()
        ->assertSee($entity->name, false)
        ->assertSee('First Entity')
        ->assertSee('Second Entity');
});

test('creating an alignment persists the chosen pair and settings', function () {
    Bus::fake();

    $user = User::factory()->create();
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'En Original']);
    $ruEntity = createEntity('ru', $work, ['name' => 'Ru Translation']);

    Livewire::actingAs($user)
        ->test(CreateEntityMatch::class)
        ->fillForm([
            'first_entity_id' => $ruEntity->id,
            'second_entity_id' => $enEntity->id,
            'chunk_size' => 50,
            'max_n' => 4,
        ])
        ->call('create');

    // The EN entity was created first (lower id), so it is canonicalized to the a side.
    $this->assertDatabaseHas('entity_matches', [
        'a_entity_id' => $enEntity->id,
        'b_entity_id' => $ruEntity->id,
        'chunk_size' => 50,
        'max_n' => 4,
    ]);
});

test('creating an alignment defaults chunk size and max span', function () {
    Bus::fake();

    $user = User::factory()->create();
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'En Default']);
    $ruEntity = createEntity('ru', $work, ['name' => 'Ru Default']);

    Livewire::actingAs($user)
        ->test(CreateEntityMatch::class)
        ->fillForm([
            'first_entity_id' => $enEntity->id,
            'second_entity_id' => $ruEntity->id,
        ])
        ->call('create');

    $this->assertDatabaseHas('entity_matches', [
        'a_entity_id' => $enEntity->id,
        'b_entity_id' => $ruEntity->id,
        'chunk_size' => 75,
        'max_n' => 6,
    ]);
});
