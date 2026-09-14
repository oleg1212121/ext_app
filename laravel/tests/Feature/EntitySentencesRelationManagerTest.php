<?php

use App\Classes\SparseOrderService;
use App\Filament\Resources\EntityResource\Pages\EditEntity;
use App\Filament\Resources\EntityResource\RelationManagers\SentencesRelationManager;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    SentenceType::firstOrCreate(
        ['name' => 'sentence'],
        ['description' => 'A standard sentence'],
    );
});

// The relation manager is shared by all languages now; the dataset still
// exercises both an English and a Russian owner record.
$languageConfigs = [
    'english' => 'en',
    'russian' => 'ru',
];

it('can render the sentences relation manager', function (string $languageCode) {
    $entity = createEntity($languageCode, null, ['name' => 'Test entity']);

    Livewire::test(SentencesRelationManager::class, [
        'ownerRecord' => $entity,
        'pageClass' => EditEntity::class,
    ])->assertSuccessful();
})->with($languageConfigs);

it('lists sentences ordered by order column', function (string $languageCode) {
    $entity = createEntity($languageCode, null, ['name' => 'Test entity']);

    $second = EntitySentence::create([
        'entity_id' => $entity->id,
        'content' => 'Second sentence',
        'order' => 100,
    ]);

    $first = EntitySentence::create([
        'entity_id' => $entity->id,
        'content' => 'First sentence',
        'order' => 50,
    ]);

    Livewire::test(SentencesRelationManager::class, [
        'ownerRecord' => $entity,
        'pageClass' => EditEntity::class,
    ])
        ->assertCanSeeTableRecords([$first, $second], inOrder: true);
})->with($languageConfigs);

it('can create a sentence appended to the end', function (string $languageCode) {
    $entity = createEntity($languageCode, null, ['name' => 'Test entity']);
    $sentenceTypeId = SentenceType::where('name', 'sentence')->value('id');

    Livewire::test(SentencesRelationManager::class, [
        'ownerRecord' => $entity,
        'pageClass' => EditEntity::class,
    ])
        ->callTableAction(CreateAction::class, data: [
            'content' => 'Appended sentence',
            'sentence_type_id' => $sentenceTypeId,
            'insert_after' => SparseOrderService::BEGINNING_SENTINEL,
        ])
        ->assertHasNoTableActionErrors();

    $sentence = EntitySentence::query()
        ->where('entity_id', $entity->id)
        ->where('content', 'Appended sentence')
        ->first();

    expect($sentence)
        ->not->toBeNull()
        ->sentence_type_id->toBe((int) $sentenceTypeId)
        ->order->toBe(0);
})->with($languageConfigs);

it('can create a sentence between existing sentences using sparse order', function (string $languageCode) {
    $entity = createEntity($languageCode, null, ['name' => 'Test entity']);
    $sentenceTypeId = SentenceType::where('name', 'sentence')->value('id');

    $first = EntitySentence::create([
        'entity_id' => $entity->id,
        'content' => 'First',
        'order' => 0,
    ]);

    $second = EntitySentence::create([
        'entity_id' => $entity->id,
        'content' => 'Second',
        'order' => 1024,
    ]);

    Livewire::test(SentencesRelationManager::class, [
        'ownerRecord' => $entity,
        'pageClass' => EditEntity::class,
    ])
        ->callTableAction(CreateAction::class, data: [
            'content' => 'Between',
            'sentence_type_id' => $sentenceTypeId,
            'insert_after' => (string) $first->id,
        ])
        ->assertHasNoTableActionErrors();

    $newSentence = EntitySentence::query()
        ->where('entity_id', $entity->id)
        ->where('content', 'Between')
        ->first();

    expect($newSentence)
        ->not->toBeNull()
        ->order->toBeGreaterThan($first->order)
        ->order->toBeLessThan($second->order);
})->with($languageConfigs);

it('can edit a sentence and reorder it', function (string $languageCode) {
    $entity = createEntity($languageCode, null, ['name' => 'Test entity']);
    $sentenceTypeId = SentenceType::where('name', 'sentence')->value('id');

    $first = EntitySentence::create([
        'entity_id' => $entity->id,
        'content' => 'First',
        'order' => 0,
        'sentence_type_id' => $sentenceTypeId,
    ]);

    $second = EntitySentence::create([
        'entity_id' => $entity->id,
        'content' => 'Second',
        'order' => 1024,
        'sentence_type_id' => $sentenceTypeId,
    ]);

    $third = EntitySentence::create([
        'entity_id' => $entity->id,
        'content' => 'Third',
        'order' => 2048,
        'sentence_type_id' => $sentenceTypeId,
    ]);

    Livewire::test(SentencesRelationManager::class, [
        'ownerRecord' => $entity,
        'pageClass' => EditEntity::class,
    ])
        ->callTableAction(EditAction::class, $third, data: [
            'content' => 'Third moved',
            'sentence_type_id' => $sentenceTypeId,
            'insert_after' => (string) $first->id,
        ])
        ->assertHasNoTableActionErrors();

    expect($third->refresh())
        ->content->toBe('Third moved')
        ->order->toBeGreaterThan($first->order)
        ->order->toBeLessThan($second->order);
})->with($languageConfigs);

it('deletes a sentence and removes empty meaning matches', function (string $languageCode) {
    $work = createWork();
    $entity = createEntity($languageCode, $work, ['name' => 'Test entity']);
    $otherLanguageCode = $languageCode === 'en' ? 'ru' : 'en';
    $other = createEntity($otherLanguageCode, $work, ['name' => "Pair {$otherLanguageCode}"]);

    $sentence = EntitySentence::create([
        'entity_id' => $entity->id,
        'content' => 'Aligned sentence',
        'order' => 0,
    ]);

    $entityMatch = createEntityMatch($entity, $other, [
        'status' => 'completed',
        'linked_count' => 1,
    ]);

    $meaningMatch = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 0.5,
    ]);

    SentenceMeaningMatch::create([
        'entity_sentence_id' => $sentence->id,
        'meaning_match_id' => $meaningMatch->id,
        'side' => $entity->id < $other->id ? 'a' : 'b',
    ]);

    Livewire::test(SentencesRelationManager::class, [
        'ownerRecord' => $entity,
        'pageClass' => EditEntity::class,
    ])
        ->callTableAction(DeleteAction::class, $sentence);

    expect(EntitySentence::find($sentence->id))->toBeNull();
    expect(MeaningMatch::find($meaningMatch->id))->toBeNull();
    expect($entityMatch->refresh()->linked_count)->toBe(0);
})->with($languageConfigs);
