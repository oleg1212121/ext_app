<?php

use App\Jobs\AlignEntitySentences;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use App\Models\User;
use Illuminate\Http\Client\Request;
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

    $this->artisan('alignments:resume')
        ->assertSuccessful()
        ->expectsOutput("Dispatched alignment for entity match #{$pending->id} (from scratch)");

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

// The 2026-09-28 prod incident: editing a sentence on the entity page flips a
// completed match to pending, and the 5-minute scheduler used to wipe every
// meaning-match row — including human alignment_chunk=-1 landmarks — by
// routing re-pended matches through beginFromScratch (ADR 0051).
it('preserves human rows when a sentence edit re-pends a completed match', function () {
    $calls = [];
    Http::fake(function (Request $request) use (&$calls) {
        $a = $request->data()['a_sentences'] ?? [];
        $b = $request->data()['b_sentences'] ?? [];
        $calls[] = ['a' => $a, 'b' => $b];

        $count = min(count($a), count($b));
        $matches = [];

        for ($i = 0; $i < $count; $i++) {
            $matches[] = ['a_start' => $i, 'a_end' => $i + 1, 'b_start' => $i, 'b_end' => $i + 1, 'score' => 0.9];
        }

        return Http::response(['matches' => $matches, 'unmatched_a' => [], 'unmatched_b' => []]);
    });

    Bus::fake();

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

    // The user edits a sentence on the entity page; the match flips to pending.
    $this->actingAs(approvedUser())
        ->patchJson("/entities/en/{$enEntity->id}/sentences/{$enSentences[0]->id}", [
            'content' => 'English 1 edited.',
            'sentence_type_id' => $typeId,
        ])
        ->assertOk();

    expect($entityMatch->refresh()->status)->toBe('pending');

    $this->artisan('alignments:resume --dry-run')
        ->assertSuccessful()
        ->expectsOutput("Would resume entity match #{$entityMatch->id} (a_entity_id={$entityMatch->a_entity_id}, b_entity_id={$entityMatch->b_entity_id}, landmarks preserved)");

    $this->artisan('alignments:resume')
        ->assertSuccessful()
        ->expectsOutput("Dispatched alignment for entity match #{$entityMatch->id} (landmarks preserved)");

    // The landmark survived the resume begin; only the low-confidence row went.
    expect(MeaningMatch::find($humanRow->id))->not->toBeNull()
        ->and(MeaningMatch::find($machineRow->id))->toBeNull();

    (new AlignEntitySentences($entityMatch->id))->handle();

    $guard = 0;

    while ($entityMatch->refresh()->status === 'aligning' && $guard < 10) {
        (new AlignEntitySentences($entityMatch->id))->handle();
        $guard++;
    }

    expect($guard)->toBeLessThan(10)
        ->and($entityMatch->status)->toBe('completed')
        ->and($entityMatch->a_last_sentence_offset)->toBe(3)
        ->and($entityMatch->b_last_sentence_offset)->toBe(3)
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)->count())->toBe(3);

    $humanRow->refresh();

    expect($humanRow->alignment_chunk)->toBe(-1)
        ->and($humanRow->similarity)->toBe('1.0000')
        ->and($humanRow->sideSentenceMeaningMatches('a')->pluck('entity_sentence_id')->all())->toEqual([$enSentences[0]->id])
        ->and($humanRow->sideSentenceMeaningMatches('b')->pluck('entity_sentence_id')->all())->toEqual([$ruSentences[0]->id]);

    // The re-align pool spans the sentences after the landmark, machine-derived at 0.9.
    expect($calls)->toHaveCount(1)
        ->and($calls[0]['a'])->toBe(['English 2.', 'English 3.'])
        ->and($calls[0]['b'])->toBe(['Russian 2.', 'Russian 3.']);
});
