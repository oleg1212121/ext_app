<?php

use App\Classes\SparseOrderService;
use App\Filament\Resources\EnEntityResource\Pages\EditEnEntity;
use App\Filament\Resources\EnEntityResource\RelationManagers\SentencesRelationManager as EnSentencesRelationManager;
use App\Filament\Resources\RuEntityResource\Pages\EditRuEntity;
use App\Filament\Resources\RuEntityResource\RelationManagers\SentencesRelationManager as RuSentencesRelationManager;
use App\Models\EnEntity;
use App\Models\EnEntitySentence;
use App\Models\EnRuEntityMatch;
use App\Models\EnRuMeaningMatch;
use App\Models\EnSentenceMeaningMatch;
use App\Models\RuEntity;
use App\Models\RuEntitySentence;
use App\Models\RuSentenceMeaningMatch;
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

$englishConfig = [
    'entityClass' => EnEntity::class,
    'sentenceClass' => EnEntitySentence::class,
    'relationManager' => EnSentencesRelationManager::class,
    'editPage' => EditEnEntity::class,
    'entityForeignKey' => 'en_entity_id',
    'sentenceMeaningMatchClass' => EnSentenceMeaningMatch::class,
    'sentenceMeaningMatchForeignKey' => 'en_entity_sentence_id',
];

$russianConfig = [
    'entityClass' => RuEntity::class,
    'sentenceClass' => RuEntitySentence::class,
    'relationManager' => RuSentencesRelationManager::class,
    'editPage' => EditRuEntity::class,
    'entityForeignKey' => 'ru_entity_id',
    'sentenceMeaningMatchClass' => RuSentenceMeaningMatch::class,
    'sentenceMeaningMatchForeignKey' => 'ru_entity_sentence_id',
];

$languageConfigs = [
    'english' => [$englishConfig],
    'russian' => [$russianConfig],
];

it('can render the sentences relation manager', function (array $config) {
    $entity = $config['entityClass']::create(['name' => 'Test entity']);

    Livewire::test($config['relationManager'], [
        'ownerRecord' => $entity,
        'pageClass' => $config['editPage'],
    ])->assertSuccessful();
})->with($languageConfigs);

it('lists sentences ordered by order column', function (array $config) {
    $entity = $config['entityClass']::create(['name' => 'Test entity']);

    $second = $config['sentenceClass']::create([
        $config['entityForeignKey'] => $entity->id,
        'content' => 'Second sentence',
        'order' => 100,
    ]);

    $first = $config['sentenceClass']::create([
        $config['entityForeignKey'] => $entity->id,
        'content' => 'First sentence',
        'order' => 50,
    ]);

    Livewire::test($config['relationManager'], [
        'ownerRecord' => $entity,
        'pageClass' => $config['editPage'],
    ])
        ->assertCanSeeTableRecords([$first, $second], inOrder: true);
})->with($languageConfigs);

it('can create a sentence appended to the end', function (array $config) {
    $entity = $config['entityClass']::create(['name' => 'Test entity']);
    $sentenceTypeId = SentenceType::where('name', 'sentence')->value('id');

    Livewire::test($config['relationManager'], [
        'ownerRecord' => $entity,
        'pageClass' => $config['editPage'],
    ])
        ->callTableAction(CreateAction::class, data: [
            'content' => 'Appended sentence',
            'sentence_type_id' => $sentenceTypeId,
            'insert_after' => SparseOrderService::BEGINNING_SENTINEL,
        ])
        ->assertHasNoTableActionErrors();

    $sentence = $config['sentenceClass']::query()
        ->where($config['entityForeignKey'], $entity->id)
        ->where('content', 'Appended sentence')
        ->first();

    expect($sentence)
        ->not->toBeNull()
        ->sentence_type_id->toBe((int) $sentenceTypeId)
        ->order->toBe(0);
})->with($languageConfigs);

it('can create a sentence between existing sentences using sparse order', function (array $config) {
    $entity = $config['entityClass']::create(['name' => 'Test entity']);
    $sentenceTypeId = SentenceType::where('name', 'sentence')->value('id');

    $first = $config['sentenceClass']::create([
        $config['entityForeignKey'] => $entity->id,
        'content' => 'First',
        'order' => 0,
    ]);

    $second = $config['sentenceClass']::create([
        $config['entityForeignKey'] => $entity->id,
        'content' => 'Second',
        'order' => 1024,
    ]);

    Livewire::test($config['relationManager'], [
        'ownerRecord' => $entity,
        'pageClass' => $config['editPage'],
    ])
        ->callTableAction(CreateAction::class, data: [
            'content' => 'Between',
            'sentence_type_id' => $sentenceTypeId,
            'insert_after' => (string) $first->id,
        ])
        ->assertHasNoTableActionErrors();

    $newSentence = $config['sentenceClass']::query()
        ->where($config['entityForeignKey'], $entity->id)
        ->where('content', 'Between')
        ->first();

    expect($newSentence)
        ->not->toBeNull()
        ->order->toBeGreaterThan($first->order)
        ->order->toBeLessThan($second->order);
})->with($languageConfigs);

it('can edit a sentence and reorder it', function (array $config) {
    $entity = $config['entityClass']::create(['name' => 'Test entity']);
    $sentenceTypeId = SentenceType::where('name', 'sentence')->value('id');

    $first = $config['sentenceClass']::create([
        $config['entityForeignKey'] => $entity->id,
        'content' => 'First',
        'order' => 0,
        'sentence_type_id' => $sentenceTypeId,
    ]);

    $second = $config['sentenceClass']::create([
        $config['entityForeignKey'] => $entity->id,
        'content' => 'Second',
        'order' => 1024,
        'sentence_type_id' => $sentenceTypeId,
    ]);

    $third = $config['sentenceClass']::create([
        $config['entityForeignKey'] => $entity->id,
        'content' => 'Third',
        'order' => 2048,
        'sentence_type_id' => $sentenceTypeId,
    ]);

    Livewire::test($config['relationManager'], [
        'ownerRecord' => $entity,
        'pageClass' => $config['editPage'],
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

it('deletes a sentence and removes empty meaning matches', function (array $config) {
    $entity = $config['entityClass']::create(['name' => 'Test entity']);

    $sentence = $config['sentenceClass']::create([
        $config['entityForeignKey'] => $entity->id,
        'content' => 'Aligned sentence',
        'order' => 0,
    ]);

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $config['entityClass'] === EnEntity::class ? $entity->id : EnEntity::create(['name' => 'Pair EN'])->id,
        'ru_entity_id' => $config['entityClass'] === RuEntity::class ? $entity->id : RuEntity::create(['name' => 'Pair RU'])->id,
        'status' => 'completed',
        'linked_count' => 1,
    ]);

    $meaningMatch = EnRuMeaningMatch::create([
        'en_ru_entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 0.5,
    ]);

    $config['sentenceMeaningMatchClass']::create([
        $config['sentenceMeaningMatchForeignKey'] => $sentence->id,
        'en_ru_meaning_match_id' => $meaningMatch->id,
        'order' => 0,
    ]);

    Livewire::test($config['relationManager'], [
        'ownerRecord' => $entity,
        'pageClass' => $config['editPage'],
    ])
        ->callTableAction(DeleteAction::class, $sentence);

    expect($config['sentenceClass']::find($sentence->id))->toBeNull();
    expect(EnRuMeaningMatch::find($meaningMatch->id))->toBeNull();
    expect($entityMatch->refresh()->linked_count)->toBe(0);
})->with($languageConfigs);
