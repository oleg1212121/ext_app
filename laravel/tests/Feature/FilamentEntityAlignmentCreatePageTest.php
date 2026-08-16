<?php

use App\Filament\Resources\EnRuEntityMatchResource\Pages\CreateEnRuEntityMatch;
use App\Models\EnEntity;
use App\Models\RuEntity;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

test('filament alignment create page lists entities without signature or sentences', function () {
    $user = User::factory()->create();

    $entity = EnEntity::create([
        'name' => 'Freshly Created English Entity',
        'description' => null,
        'signature' => null,
        'file_path' => null,
    ]);

    Livewire::actingAs($user)
        ->test(CreateEnRuEntityMatch::class)
        ->assertSuccessful()
        ->assertSee($entity->name, false)
        ->assertSee('Original Text');
});

test('creating an alignment persists the chosen original text language', function () {
    Bus::fake();

    $user = User::factory()->create();
    $enEntity = EnEntity::create(['name' => 'En Original']);
    $ruEntity = RuEntity::create(['name' => 'Ru Translation']);

    Livewire::actingAs($user)
        ->test(CreateEnRuEntityMatch::class)
        ->fillForm([
            'en_entity_id' => $enEntity->id,
            'ru_entity_id' => $ruEntity->id,
            'is_original_en' => 0,
        ])
        ->call('create');

    $this->assertDatabaseHas('en_ru_entity_matches', [
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'is_original_en' => false,
    ]);
});

test('creating an alignment defaults the original text to English', function () {
    Bus::fake();

    $user = User::factory()->create();
    $enEntity = EnEntity::create(['name' => 'En Default']);
    $ruEntity = RuEntity::create(['name' => 'Ru Default']);

    Livewire::actingAs($user)
        ->test(CreateEnRuEntityMatch::class)
        ->fillForm([
            'en_entity_id' => $enEntity->id,
            'ru_entity_id' => $ruEntity->id,
        ])
        ->call('create');

    $this->assertDatabaseHas('en_ru_entity_matches', [
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'is_original_en' => true,
    ]);
});
