<?php

use App\Jobs\AlignEntitySentences;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

if (! function_exists('approvedUser')) {
    function approvedUser(): User
    {
        return User::factory()->create(['is_approved' => true]);
    }
}

// Guard: these tests stop at Bus::fake(), but anything that leaks an HTTP call
// must fail loudly instead of hanging on the Python service timeout. A bare
// Http::fake() here would shadow any scenario fake registered by a test —
// stub callbacks resolve first-registered-wins — so stray-request prevention
// is the guard and tests that need responses register their own fake.
beforeEach(fn () => Http::preventStrayRequests());

function createVerifiablePair(string $enName = 'En', string $ruName = 'Ru'): EntityMatch
{
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => $enName, 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = createEntity('ru', $work, ['name' => $ruName, 'signature' => json_encode([1.0, 0.0])]);
    EntitySentence::create(['entity_id' => $enEntity->id, 'content' => 'En 1.', 'order' => 1]);
    EntitySentence::create(['entity_id' => $ruEntity->id, 'content' => 'Ru 1.', 'order' => 1]);

    return createEntityMatch($enEntity, $ruEntity, ['status' => 'pending']);
}

it('picks pending entity matches and dispatches alignment starts', function () {
    Bus::fake();

    $pending = createVerifiablePair('En A', 'Ru A');
    $alreadyAligning = createVerifiablePair('En B', 'Ru B');
    $alreadyAligning->update(['status' => 'aligning']);
    $completed = createVerifiablePair('En C', 'Ru C');
    $completed->update(['status' => 'completed']);
    $stale = createVerifiablePair('En D', 'Ru D');
    $stale->update(['status' => 'stale']);

    $this->artisan('alignments:resume')
        ->assertSuccessful()
        ->expectsOutput("Dispatched alignment for entity match #{$pending->id} (from scratch)");

    $pending->refresh();
    $alreadyAligning->refresh();
    $completed->refresh();
    $stale->refresh();

    expect($pending->status)->toBe('aligning')
        ->and($alreadyAligning->status)->toBe('aligning')
        ->and($completed->status)->toBe('completed')
        ->and($stale->status)->toBe('stale');
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

    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'En', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = createEntity('ru', $work, ['name' => 'Ru', 'signature' => json_encode([0.0, 1.0])]);

    EntitySentence::create(['entity_id' => $enEntity->id, 'content' => 'En 1.', 'order' => 1]);
    EntitySentence::create(['entity_id' => $ruEntity->id, 'content' => 'Ru 1.', 'order' => 1]);

    $entityMatch = createEntityMatch($enEntity, $ruEntity, ['status' => 'pending']);

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
        ->expectsOutput("Would resume entity match #{$entityMatch->id} (a_entity_id={$entityMatch->a_entity_id}, b_entity_id={$entityMatch->b_entity_id}, from scratch)");

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('pending');

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

it('reports when there are no pending entity matches', function () {
    $this->artisan('alignments:resume')
        ->assertSuccessful()
        ->expectsOutput('No pending entity matches to resume.');
});

// The 2026-09-28 prod incident (ADR 0051) and its 2026-09-30 repeat (ADR 0055):
// editing a sentence on the entity page used to flip a completed match to
// pending, and the 5-minute scheduler picked it up and re-aligned it,
// re-deriving every machine row in a new order. The edit now produces a
// display-only stale status the scheduler never picks up.
it('never re-aligns a match that a sentence edit made stale', function () {
    $typeId = SentenceType::firstOrCreate(
        ['name' => 'sentence'],
        ['description' => 'A standard sentence'],
    )->id;
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'Incident en', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = createEntity('ru', $work, ['name' => 'Incident ru', 'signature' => json_encode([1.0, 0.0])]);

    $enSentences = collect(range(1, 3))->map(fn (int $order): EntitySentence => EntitySentence::create([
        'entity_id' => $enEntity->id,
        'sentence_type_id' => $typeId,
        'content' => "English {$order}.",
        'order' => $order,
    ]));
    $ruSentences = collect(range(1, 3))->map(fn (int $order): EntitySentence => EntitySentence::create([
        'entity_id' => $ruEntity->id,
        'sentence_type_id' => $typeId,
        'content' => "Russian {$order}.",
        'order' => $order,
    ]));

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'completed',
        'chunk_size' => 75,
        'max_n' => 2,
        'a_total_sentences' => 3,
        'b_total_sentences' => 3,
        'linked_count' => 2,
        'a_last_sentence_offset' => 3,
        'b_last_sentence_offset' => 3,
    ]);

    $humanRow = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 1.0,
        'alignment_chunk' => -1,
    ]);
    SentenceMeaningMatch::create(['entity_sentence_id' => $enSentences[0]->id, 'meaning_match_id' => $humanRow->id, 'side' => 'a']);
    SentenceMeaningMatch::create(['entity_sentence_id' => $ruSentences[0]->id, 'meaning_match_id' => $humanRow->id, 'side' => 'b']);

    $machineRow = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 1024,
        'similarity' => 0.5,
        'alignment_chunk' => 0,
    ]);
    SentenceMeaningMatch::create(['entity_sentence_id' => $enSentences[1]->id, 'meaning_match_id' => $machineRow->id, 'side' => 'a']);
    SentenceMeaningMatch::create(['entity_sentence_id' => $ruSentences[1]->id, 'meaning_match_id' => $machineRow->id, 'side' => 'b']);

    $rowsBefore = matchRowsSnapshot($entityMatch->id);
    $junctionsBefore = junctionSnapshot($entityMatch->id);

    // The user edits a sentence on the entity page; the match flips to stale.
    $this->actingAs(approvedUser())
        ->patchJson("/works/{$enEntity->work_id}/entities/{$enEntity->id}/sentences/{$enSentences[0]->id}", [
            'content' => 'English 1 edited.',
            'sentence_type_id' => $typeId,
        ])
        ->assertOk();

    expect($entityMatch->refresh()->status)->toBe('stale');

    // The scheduler only picks fresh pending matches: stale is invisible to
    // it, in dry-run and for real, however many ticks pass.
    $this->artisan('alignments:resume --dry-run')
        ->assertSuccessful()
        ->expectsOutput('No pending entity matches to resume.');

    $this->artisan('alignments:resume')
        ->assertSuccessful()
        ->expectsOutput('No pending entity matches to resume.');

    $this->artisan('alignments:resume')
        ->assertSuccessful()
        ->expectsOutput('No pending entity matches to resume.');

    expect($entityMatch->refresh()->status)->toBe('stale')
        ->and(matchRowsSnapshot($entityMatch->id))->toBe($rowsBefore)
        ->and(junctionSnapshot($entityMatch->id))->toBe($junctionsBefore)
        ->and(MeaningMatch::find($humanRow->id))->not->toBeNull()
        ->and(MeaningMatch::find($machineRow->id))->not->toBeNull();
});

function matchRowsSnapshot(int $entityMatchId): string
{
    return MeaningMatch::query()
        ->where('entity_match_id', $entityMatchId)
        ->orderBy('id')
        ->get(['id', 'order', 'similarity', 'alignment_chunk'])
        ->toJson();
}

function junctionSnapshot(int $entityMatchId): string
{
    return SentenceMeaningMatch::query()
        ->whereIn('meaning_match_id', MeaningMatch::query()->where('entity_match_id', $entityMatchId)->pluck('id'))
        ->orderBy('id')
        ->get(['id', 'meaning_match_id', 'entity_sentence_id', 'side'])
        ->toJson();
}
