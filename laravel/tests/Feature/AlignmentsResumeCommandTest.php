<?php

use App\Jobs\AlignEntitySentences;
use App\Models\EnEntity;
use App\Models\EnEntitySentence;
use App\Models\EnRuEntityMatch;
use App\Models\RuEntity;
use App\Models\RuEntitySentence;
use Illuminate\Support\Facades\Bus;

function createVerifiablePair(string $enName = 'En', string $ruName = 'Ru'): EnRuEntityMatch
{
    $enEntity = EnEntity::create(['name' => $enName, 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = RuEntity::create(['name' => $ruName, 'signature' => json_encode([1.0, 0.0])]);
    EnEntitySentence::create(['en_entity_id' => $enEntity->id, 'content' => 'En 1.', 'order' => 1]);
    RuEntitySentence::create(['ru_entity_id' => $ruEntity->id, 'content' => 'Ru 1.', 'order' => 1]);

    return EnRuEntityMatch::create(['en_entity_id' => $enEntity->id, 'ru_entity_id' => $ruEntity->id, 'status' => 'pending']);
}

it('picks pending entity matches and dispatches alignment starts', function () {
    Bus::fake();

    $pending = createVerifiablePair('En A', 'Ru A');
    $alreadyAligning = createVerifiablePair('En B', 'Ru B');
    $alreadyAligning->update(['status' => 'aligning']);
    $completed = createVerifiablePair('En C', 'Ru C');
    $completed->update(['status' => 'completed']);

    $this->artisan('alignments:resume')
        ->assertSuccessful()
        ->expectsOutput("Dispatched alignment for entity match #{$pending->id}");

    $pending->refresh();
    $alreadyAligning->refresh();
    $completed->refresh();

    expect($pending->status)->toBe('aligning')
        ->and($alreadyAligning->status)->toBe('aligning')
        ->and($completed->status)->toBe('completed');
});

it('respects the limit option', function () {
    Bus::fake();

    $first = createVerifiablePair('En A', 'Ru A');
    $second = createVerifiablePair('En B', 'Ru B');

    $this->artisan('alignments:resume --limit=1')->assertSuccessful();

    $first->refresh();
    $second->refresh();

    Bus::assertDispatched(AlignEntitySentences::class, 1);
    expect($first->status)->toBe('aligning')
        ->and($second->status)->toBe('pending');
});

it('marks verify-failed entity matches as failed and does not dispatch', function () {
    Bus::fake();

    $enEntity = EnEntity::create(['name' => 'En', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = RuEntity::create(['name' => 'Ru', 'signature' => json_encode([0.0, 1.0])]);

    EnEntitySentence::create(['en_entity_id' => $enEntity->id, 'content' => 'En 1.', 'order' => 1]);
    RuEntitySentence::create(['ru_entity_id' => $ruEntity->id, 'content' => 'Ru 1.', 'order' => 1]);

    $entityMatch = EnRuEntityMatch::create(['en_entity_id' => $enEntity->id, 'ru_entity_id' => $ruEntity->id, 'status' => 'pending']);

    $this->artisan('alignments:resume')->assertSuccessful();

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('failed')
        ->and($entityMatch->error_message)->not->toBeNull();

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

it('does nothing in dry-run mode', function () {
    Bus::fake();

    $entityMatch = createVerifiablePair('En A', 'Ru A');

    $this->artisan('alignments:resume --dry-run')
        ->assertSuccessful()
        ->expectsOutput("Would resume entity match #{$entityMatch->id} (en_entity_id={$entityMatch->en_entity_id}, ru_entity_id={$entityMatch->ru_entity_id})");

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('pending');

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

it('reports when there are no pending entity matches', function () {
    $this->artisan('alignments:resume')
        ->assertSuccessful()
        ->expectsOutput('No pending entity matches to resume.');
});
