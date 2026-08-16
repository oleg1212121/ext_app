<?php

use App\Filament\Resources\EnRuEntityMatchResource\Pages\ListEnRuEntityMatches;
use App\Models\EnEntity;
use App\Models\EnEntitySentence;
use App\Models\RuEntity;
use App\Models\RuEntitySentence;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

function createListActionEntity(string $lang, string $name): int
{
    if ($lang === 'en') {
        $entity = EnEntity::create([
            'name' => $name,
            'signature' => json_encode([1.0, 0.0]),
        ]);
        EnEntitySentence::create([
            'en_entity_id' => $entity->id,
            'sentence_type_id' => null,
            'content' => 'English.',
            'order' => 1,
        ]);

        return $entity->id;
    }

    $entity = RuEntity::create([
        'name' => $name,
        'signature' => json_encode([1.0, 0.0]),
    ]);
    RuEntitySentence::create([
        'ru_entity_id' => $entity->id,
        'sentence_type_id' => null,
        'content' => 'Russian.',
        'order' => 1,
    ]);

    return $entity->id;
}

test('new alignment action persists the chosen original text language', function () {
    Bus::fake();

    $user = User::factory()->create();
    $enEntityId = createListActionEntity('en', 'En List Original');
    $ruEntityId = createListActionEntity('ru', 'Ru List Translation');

    Livewire::actingAs($user)
        ->test(ListEnRuEntityMatches::class)
        ->callAction('create', [
            'en_entity_id' => $enEntityId,
            'ru_entity_id' => $ruEntityId,
            'is_original_en' => 0,
        ]);

    $this->assertDatabaseHas('en_ru_entity_matches', [
        'en_entity_id' => $enEntityId,
        'ru_entity_id' => $ruEntityId,
        'is_original_en' => false,
    ]);
});

test('new alignment action defaults the original text to English', function () {
    Bus::fake();

    $user = User::factory()->create();
    $enEntityId = createListActionEntity('en', 'En List Default');
    $ruEntityId = createListActionEntity('ru', 'Ru List Default');

    Livewire::actingAs($user)
        ->test(ListEnRuEntityMatches::class)
        ->callAction('create', [
            'en_entity_id' => $enEntityId,
            'ru_entity_id' => $ruEntityId,
        ]);

    $this->assertDatabaseHas('en_ru_entity_matches', [
        'en_entity_id' => $enEntityId,
        'ru_entity_id' => $ruEntityId,
        'is_original_en' => true,
    ]);
});

test('alignment list shows the original text column', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ListEnRuEntityMatches::class)
        ->assertSuccessful()
        ->assertSee('Original');
});
