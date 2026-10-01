<?php

use App\Classes\ProcessingLimits;
use App\Jobs\AlignEntitySentences;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

if (! function_exists('approvedUser')) {
    function approvedUser(): User
    {
        return User::factory()->create(['is_approved' => true]);
    }
}

if (! function_exists('grantAccess')) {
    function grantAccess(User $user, Entity $entity): void
    {
        $entity->grantedUsers()->attach($user->id);
    }
}

// Guard: these tests stop at Bus::fake(), but anything that leaks an HTTP call
// must fail loudly instead of hanging on the Python service timeout. Tests
// that need responses register their own fake.
beforeEach(fn () => Http::preventStrayRequests());

/**
 * A completed match with a human landmark (en1-ru1) and a low-confidence
 * machine row (en2-ru2) — the shape a re-align acts on (ADR 0055).
 *
 * @return array{entityMatch: EntityMatch, enSentences: Collection<int, EntitySentence>}
 */
function createStaleRunFixture(): array
{
    $typeId = SentenceType::firstOrCreate(
        ['name' => 'sentence'],
        ['description' => 'A standard sentence'],
    )->id;
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'Stale run en', 'is_restricted' => true]);
    $ruEntity = createEntity('ru', $work, ['name' => 'Stale run ru', 'is_restricted' => true]);

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

    return ['entityMatch' => $entityMatch, 'enSentences' => $enSentences];
}

function fakePoolAlignment(): void
{
    Http::fake(function (Request $request) {
        $a = $request->data()['a_sentences'] ?? [];
        $b = $request->data()['b_sentences'] ?? [];

        $count = min(count($a), count($b));
        $matches = [];

        for ($i = 0; $i < $count; $i++) {
            $matches[] = ['a_start' => $i, 'a_end' => $i + 1, 'b_start' => $i, 'b_end' => $i + 1, 'score' => 0.9];
        }

        return Http::response(['matches' => $matches, 'unmatched_a' => [], 'unmatched_b' => []]);
    });
}

function drainAlignmentChain(EntityMatch $entityMatch): void
{
    (new AlignEntitySentences($entityMatch->id))->handle();

    $guard = 0;

    while ($entityMatch->refresh()->status === 'aligning' && $guard < 10) {
        (new AlignEntitySentences($entityMatch->id))->handle();
        $guard++;
    }

    expect($guard)->toBeLessThan(10);
}

// The chain finishes its repairs, but a mid-run sentence edit's stale signal
// survives — finalize() completes the match only from aligning (ADR 0055).
it('finalize leaves a match stale when a sentence edit flips it mid-run', function () {
    fakePoolAlignment();
    Bus::fake();

    ['entityMatch' => $entityMatch, 'enSentences' => $enSentences] = createStaleRunFixture();

    // An explicit Re-align starts the run.
    AlignEntitySentences::begin($entityMatch->id);
    expect($entityMatch->refresh()->status)->toBe('aligning');

    // Mid-run, a sentence edit flips the match to stale.
    $user = approvedUser();
    $enEntity = $entityMatch->aEntity;
    grantAccess($user, $enEntity);

    $this->actingAs($user)
        ->patchJson("/entities/en/{$enEntity->id}/sentences/{$enSentences[0]->id}", [
            'content' => 'English 1 edited mid-run.',
            'sentence_type_id' => SentenceType::where('name', 'sentence')->value('id'),
        ])
        ->assertOk();

    expect($entityMatch->refresh()->status)->toBe('stale');

    drainAlignmentChain($entityMatch);

    $humanRow = MeaningMatch::query()
        ->where('entity_match_id', $entityMatch->id)
        ->where('alignment_chunk', -1)
        ->first();

    expect($entityMatch->status)->toBe('stale')
        ->and($entityMatch->linked_count)->toBe(3)
        ->and($humanRow)->not->toBeNull();
});

it('failed() leaves a match stale when flipped mid-run', function () {
    Bus::fake();

    ['entityMatch' => $entityMatch] = createStaleRunFixture();
    $entityMatch->update(['status' => 'stale']);

    (new AlignEntitySentences($entityMatch->id))->failed(new RuntimeException('chain exploded'));

    expect($entityMatch->refresh()->status)->toBe('stale')
        ->and($entityMatch->error_message)->toBeNull();
});

it('failed() still marks an aligning match failed', function () {
    Bus::fake();

    ['entityMatch' => $entityMatch] = createStaleRunFixture();
    $entityMatch->update(['status' => 'aligning']);

    (new AlignEntitySentences($entityMatch->id))->failed(new RuntimeException('chain exploded'));

    expect($entityMatch->refresh()->status)->toBe('failed')
        ->and($entityMatch->error_message)->toBe('chain exploded')
        ->and($entityMatch->completed_at)->not->toBeNull();
});

it('a stale match does not hold the creator\'s processing slot', function () {
    Bus::fake();

    $user = User::factory()->create(['is_approved' => true]);
    ['entityMatch' => $entityMatch] = createStaleRunFixture();
    $entityMatch->update(['created_by' => $user->id, 'status' => 'stale']);

    $limits = app(ProcessingLimits::class);

    expect($limits->alignmentsInFlight($user))->toBe(0);

    $entityMatch->update(['status' => 'pending']);

    expect($limits->alignmentsInFlight($user))->toBe(1);
});
