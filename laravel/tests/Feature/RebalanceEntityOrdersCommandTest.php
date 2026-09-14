<?php

use App\Classes\SparseOrderService;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceType;

it('dry runs targeted entity sentence rebalancing without changing rows', function () {
    $sentenceType = SentenceType::create(['name' => 'sentence']);
    $entity = createEntity('en', null, ['name' => 'Dense English text']);

    EntitySentence::create([
        'entity_id' => $entity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'First.',
        'order' => 7,
    ]);
    EntitySentence::create([
        'entity_id' => $entity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'Second.',
        'order' => 8,
    ]);

    $this->artisan('entity-orders:rebalance', [
        '--entity-id' => $entity->id,
        '--dry-run' => true,
    ])->assertSuccessful();

    expect(EntitySentence::query()->where('entity_id', $entity->id)->orderBy('order')->pluck('order')->all())
        ->toBe([7, 8]);
});

it('rebalances targeted entity sentence orders', function () {
    $sentenceType = SentenceType::create(['name' => 'sentence']);
    $entity = createEntity('en', null, ['name' => 'Dense English text']);

    EntitySentence::create([
        'entity_id' => $entity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'First.',
        'order' => 7,
    ]);
    EntitySentence::create([
        'entity_id' => $entity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'Second.',
        'order' => 8,
    ]);

    $this->artisan('entity-orders:rebalance', [
        '--entity-id' => $entity->id,
    ])->assertSuccessful();

    expect(EntitySentence::query()->where('entity_id', $entity->id)->orderBy('order')->pluck('order')->all())
        ->toBe([0, SparseOrderService::STRIDE]);
});

it('rebalances targeted meaning match orders with the unique order constraint', function () {
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English text']);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian text']);
    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'completed',
    ]);

    MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 1,
        'similarity' => 1.0,
        'alignment_chunk' => 0,
    ]);
    MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 2,
        'similarity' => 1.0,
        'alignment_chunk' => 0,
    ]);

    $this->artisan('entity-orders:rebalance', [
        '--entity-match-id' => $entityMatch->id,
    ])->assertSuccessful();

    expect(MeaningMatch::query()
        ->where('entity_match_id', $entityMatch->id)
        ->orderBy('order')
        ->pluck('order')
        ->all())->toBe([0, SparseOrderService::STRIDE]);
});
