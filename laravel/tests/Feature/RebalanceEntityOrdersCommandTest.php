<?php

use App\Classes\SparseOrderService;
use App\Models\EnEntity;
use App\Models\EnEntitySentence;
use App\Models\EnRuEntityMatch;
use App\Models\EnRuMeaningMatch;
use App\Models\RuEntity;
use App\Models\SentenceType;

it('dry runs targeted entity sentence rebalancing without changing rows', function () {
    $sentenceType = SentenceType::create(['name' => 'sentence']);
    $entity = EnEntity::create(['name' => 'Dense English text']);

    EnEntitySentence::create([
        'en_entity_id' => $entity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'First.',
        'order' => 7,
    ]);
    EnEntitySentence::create([
        'en_entity_id' => $entity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'Second.',
        'order' => 8,
    ]);

    $this->artisan('entity-orders:rebalance', [
        '--lang' => 'en',
        '--entity-id' => $entity->id,
        '--dry-run' => true,
    ])->assertSuccessful();

    expect(EnEntitySentence::query()->where('en_entity_id', $entity->id)->orderBy('order')->pluck('order')->all())
        ->toBe([7, 8]);
});

it('rebalances targeted entity sentence orders', function () {
    $sentenceType = SentenceType::create(['name' => 'sentence']);
    $entity = EnEntity::create(['name' => 'Dense English text']);

    EnEntitySentence::create([
        'en_entity_id' => $entity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'First.',
        'order' => 7,
    ]);
    EnEntitySentence::create([
        'en_entity_id' => $entity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'Second.',
        'order' => 8,
    ]);

    $this->artisan('entity-orders:rebalance', [
        '--lang' => 'en',
        '--entity-id' => $entity->id,
    ])->assertSuccessful();

    expect(EnEntitySentence::query()->where('en_entity_id', $entity->id)->orderBy('order')->pluck('order')->all())
        ->toBe([0, SparseOrderService::STRIDE]);
});

it('rebalances targeted meaning match orders with the unique order constraint', function () {
    $enEntity = EnEntity::create(['name' => 'English text']);
    $ruEntity = RuEntity::create(['name' => 'Russian text']);
    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'completed',
    ]);

    EnRuMeaningMatch::create([
        'en_ru_entity_match_id' => $entityMatch->id,
        'order' => 1,
        'similarity' => 1.0,
        'alignment_chunk' => 0,
    ]);
    EnRuMeaningMatch::create([
        'en_ru_entity_match_id' => $entityMatch->id,
        'order' => 2,
        'similarity' => 1.0,
        'alignment_chunk' => 0,
    ]);

    $this->artisan('entity-orders:rebalance', [
        '--entity-match-id' => $entityMatch->id,
    ])->assertSuccessful();

    expect(EnRuMeaningMatch::query()
        ->where('en_ru_entity_match_id', $entityMatch->id)
        ->orderBy('order')
        ->pluck('order')
        ->all())->toBe([0, SparseOrderService::STRIDE]);
});
