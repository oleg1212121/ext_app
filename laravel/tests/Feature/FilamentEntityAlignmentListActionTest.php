<?php

use App\Filament\Resources\EntityMatchResource\Pages\ListEntityMatches;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

function createListActionEntity(string $languageCode, Work $work, string $name): Entity
{
    $entity = createEntity($languageCode, $work, [
        'name' => $name,
        'signature' => json_encode([1.0, 0.0]),
    ]);

    EntitySentence::create([
        'entity_id' => $entity->id,
        'sentence_type_id' => null,
        'content' => 'Text.',
        'order' => 1,
    ]);

    return $entity;
}

test('new alignment action persists the chosen pair in canonical side order', function () {
    Bus::fake();

    $user = User::factory()->create();
    $work = createWork();
    $enEntity = createListActionEntity('en', $work, 'En List Original');
    $ruEntity = createListActionEntity('ru', $work, 'Ru List Translation');

    Livewire::actingAs($user)
        ->test(ListEntityMatches::class)
        ->callAction('create', data: [
            'first_entity_id' => $ruEntity->id,
            'second_entity_id' => $enEntity->id,
        ]);

    // The EN entity was created first (lower id), so it is canonicalized to the a side.
    $this->assertDatabaseHas('entity_matches', [
        'a_entity_id' => $enEntity->id,
        'b_entity_id' => $ruEntity->id,
    ]);
});

test('new alignment action derives the original side from the work', function () {
    Bus::fake();

    $user = User::factory()->create();
    $work = createWork(); // defaults to English as the original language
    $enEntity = createListActionEntity('en', $work, 'En List Default');
    $ruEntity = createListActionEntity('ru', $work, 'Ru List Default');

    Livewire::actingAs($user)
        ->test(ListEntityMatches::class)
        ->callAction('create', data: [
            'first_entity_id' => $enEntity->id,
            'second_entity_id' => $ruEntity->id,
        ]);

    $match = EntityMatch::query()
        ->where('a_entity_id', $enEntity->id)
        ->where('b_entity_id', $ruEntity->id)
        ->first();

    expect($match)->not->toBeNull()
        ->and($match->originalSide())->toBe('a');
});

test('alignment list shows the pair and work columns', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ListEntityMatches::class)
        ->assertSuccessful()
        ->assertSee('A Entity')
        ->assertSee('B Entity')
        ->assertSee('Work');
});
