<?php

use App\Classes\AlignmentRefineService;
use App\Jobs\RefineEntitySentences;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

/**
 * A completed 3×4 match telling the smart-notes fusion story: the translator
 * split the long final English sentence into two Russian ones, round 1
 * committed the head as a 1:1 (0.83) and left the tail one-sided. Rows: two
 * leading 1:1s, the fusion head 1:1, and the orphaned tail.
 *
 * @return array<string, mixed>
 */
function refineWorld(): array
{
    $work = createWork();
    $en = createEntity('en', $work, ['name' => 'Refine EN']);
    $ru = createEntity('ru', $work, ['name' => 'Refine RU']);

    $enSentences = [];
    $ruSentences = [];

    foreach (['English zero.', 'English one.', 'English two long.'] as $i => $enText) {
        $enSentences[] = EntitySentence::create([
            'entity_id' => $en->id,
            'content' => $enText,
            'order' => $i + 1,
        ]);
    }

    foreach (['Русский ноль.', 'Русский один.', 'Русский два.', 'Русский три.'] as $i => $ruText) {
        $ruSentences[] = EntitySentence::create([
            'entity_id' => $ru->id,
            'content' => $ruText,
            'order' => $i + 1,
        ]);
    }

    $match = createEntityMatch($en, $ru, ['status' => 'completed', 'max_n' => 6]);

    createRefineRow($match->id, 0, 0.70, 0, aIds: [$enSentences[0]->id], bIds: [$ruSentences[0]->id]);
    $inner = createRefineRow($match->id, 1024, 0.70, 0, aIds: [$enSentences[1]->id], bIds: [$ruSentences[1]->id]);
    $fusionHead = createRefineRow($match->id, 2048, 0.83, 1, aIds: [$enSentences[2]->id], bIds: [$ruSentences[2]->id]);
    $orphan = createRefineRow($match->id, 3072, 0.0, 1, bIds: [$ruSentences[3]->id]);

    return compact('work', 'en', 'ru', 'match', 'enSentences', 'ruSentences', 'inner', 'fusionHead', 'orphan');
}

function createRefineRow(int $matchId, int $order, float $similarity, int $chunk, array $aIds = [], array $bIds = []): MeaningMatch
{
    $row = MeaningMatch::create([
        'entity_match_id' => $matchId,
        'order' => $order,
        'similarity' => $similarity,
        'alignment_chunk' => $chunk,
    ]);

    foreach ($aIds as $id) {
        SentenceMeaningMatch::create([
            'entity_match_id' => $matchId,
            'entity_sentence_id' => $id,
            'meaning_match_id' => $row->id,
            'side' => 'a',
        ]);
    }

    foreach ($bIds as $id) {
        SentenceMeaningMatch::create([
            'entity_match_id' => $matchId,
            'entity_sentence_id' => $id,
            'meaning_match_id' => $row->id,
            'side' => 'b',
        ]);
    }

    return $row;
}

/**
 * The rows of the world's match, in order, with junctions eager-loaded.
 */
function refineRows(array $w): Collection
{
    return MeaningMatch::query()
        ->where('entity_match_id', $w['match']->id)
        ->with('sentenceMeaningMatches')
        ->orderBy('order')
        ->orderBy('id')
        ->get();
}

/**
 * The regrouping python's DP would return for the orphan's region (rows
 * B–D, window-relative): the inner 1:1 stands, and the long English
 * sentence absorbs both remaining Russian tail sentences as one 1:2.
 *
 * @return list<array{a_start: int, a_end: int, b_start: int, b_end: int, score: float}>
 */
function fusionRegrouping(): array
{
    return [
        ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.70],
        ['a_start' => 1, 'a_end' => 2, 'b_start' => 1, 'b_end' => 3, 'score' => 0.87],
    ];
}

it('configures the refine job like the align job', function () {
    $job = new RefineEntitySentences(1);

    expect($job->timeout)->toBe(600)
        ->and($job->tries)->toBe(2)
        ->and($job->backoff())->toEqual([60]);
});

it('re-groups a fusion head plus orphan into a 1:2 via dp and joined windows', function () {
    $w = refineWorld();
    $fake = fakePython();

    $fake->alignHandler = function (array $payload): array {
        // The region spans the orphan's ±2 row context: the fusion head 1:1,
        // the inner 1:1, and the orphan — the leading 1:1 stays outside.
        expect($payload['algorithm'])->toBe('dp')
            ->and($payload['window_embed'])->toBe('joined')
            ->and($payload['max_window'])->toBe(6)
            ->and($payload['a_sentences'])->toBe(['English one.', 'English two long.'])
            ->and($payload['b_sentences'])->toBe(['Русский один.', 'Русский два.', 'Русский три.'])
            ->and($payload)->not->toHaveKey('landmarks');

        return fusionRegrouping();
    };

    $summary = AlignmentRefineService::create()->refine($w['match']);

    expect($summary['status'])->toBe('refined')
        ->and($summary['regions'])->toBe(1)
        ->and($summary['applied'])->toBe(1)
        ->and($summary['rejected'])->toBe(0)
        ->and($summary['one_sided_before'])->toBe(1)
        ->and($summary['one_sided_after'])->toBe(0);

    $w['match']->refresh();
    expect($w['match']->status)->toBe('completed');

    // The old fusion-head 1:1 + orphan were replaced by one three-sentence
    // row; the leading 1:1 (outside the region) kept its e0↔r0 junctions.
    $rows = refineRows($w);
    expect($rows)->toHaveCount(3);

    $leading = $rows->first();
    expect($leading->sentenceMeaningMatches->count())->toBe(2)
        ->and($leading->sentenceMeaningMatches->where('side', 'a')->pluck('entity_sentence_id')->all())
        ->toEqual([$w['enSentences'][0]->id]);

    $fusion = $rows->first(fn (MeaningMatch $row) => $row->sentenceMeaningMatches->count() === 3);
    expect($fusion)->not->toBeNull()
        ->and((float) $fusion->similarity)->toBe(0.87)
        ->and($fusion->sentenceMeaningMatches->where('side', 'a')->pluck('entity_sentence_id')->all())
        ->toEqual([$w['enSentences'][2]->id])
        ->and($fusion->sentenceMeaningMatches->where('side', 'b')->pluck('entity_sentence_id')->sort()->values()->all())
        ->toEqual([$w['ruSentences'][2]->id, $w['ruSentences'][3]->id]);

    // Strict junction uniqueness (ADR 0048): every sentence junctioned once.
    $junctions = SentenceMeaningMatch::query()->where('entity_match_id', $w['match']->id)->get();
    expect($junctions)->toHaveCount(7)
        ->and($junctions->pluck('entity_sentence_id')->unique())->toHaveCount(7);
});

it('leaves every row untouched when the regrouping does not improve the score sum', function () {
    $w = refineWorld();
    $before = refineRows($w)->pluck('id')->all();

    fakePython()->aligning([
        // The same grouping round 1 committed — the fusion head stays 1:1 and
        // the tail stays one-sided: 0.70 + 0.83 is not an improvement.
        ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.70],
        ['a_start' => 1, 'a_end' => 2, 'b_start' => 1, 'b_end' => 2, 'score' => 0.83],
    ]);

    $summary = AlignmentRefineService::create()->refine($w['match']);

    expect($summary['status'])->toBe('refined')
        ->and($summary['applied'])->toBe(0)
        ->and($summary['rejected'])->toBe(1)
        ->and($summary['one_sided_before'])->toBe(1)
        ->and($summary['one_sided_after'])->toBe(1)
        ->and(refineRows($w)->pluck('id')->all())->toEqual($before)
        ->and($w['match']->refresh()->status)->toBe('completed');
});

it('rejects a regrouping that trades coverage for a sub-floor garbage match', function () {
    $w = refineWorld();
    // Weak incumbent rows so the poisoned grouping would win on sum alone.
    MeaningMatch::query()->where('entity_match_id', $w['match']->id)->update(['similarity' => 0.20]);
    $before = refineRows($w)->pluck('id')->all();

    fakePython()->aligning([
        ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.70],
        ['a_start' => 1, 'a_end' => 2, 'b_start' => 1, 'b_end' => 3, 'score' => 0.10],
    ]);

    $summary = AlignmentRefineService::create()->refine($w['match']);

    expect($summary['applied'])->toBe(0)
        ->and($summary['rejected'])->toBe(1)
        ->and(refineRows($w)->pluck('id')->all())->toEqual($before);
});

it('pins human rows, passes them to python as landmarks, and never deletes them', function () {
    $w = refineWorld();

    // Promote the inner 1:1 — inside the orphan's region — to a pin.
    $pin = $w['inner'];
    $pin->update(['alignment_chunk' => MeaningMatch::HUMAN_CHUNK, 'similarity' => 1.0]);

    $fake = fakePython();

    $fake->alignHandler = function (array $payload): array {
        expect($payload['landmarks'])->toEqual([
            ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1],
        ]);

        return [
            ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 1.0],
            ['a_start' => 1, 'a_end' => 2, 'b_start' => 1, 'b_end' => 3, 'score' => 0.87],
        ];
    };

    $summary = AlignmentRefineService::create()->refine($w['match']);

    expect($summary['applied'])->toBe(1);

    // The pin row survived with its identity and junctions; the machine rows
    // behind it regrouped into the fusion.
    $pin->refresh();
    expect($pin->alignment_chunk)->toBe(MeaningMatch::HUMAN_CHUNK)
        ->and((float) $pin->similarity)->toBe(1.0)
        ->and($pin->sentenceMeaningMatches()->count())->toBe(2);

    $fusion = refineRows($w)->first(fn (MeaningMatch $row) => $row->sentenceMeaningMatches->count() === 3);
    expect($fusion)->not->toBeNull()
        ->and($fusion->id)->not->toBe($pin->id);
});

it('bounds a candidate region at a single-sided human pin', function () {
    $w = refineWorld();

    // The leading row is an approved one-sided pin: it delimits no span the
    // aligner can honor, so the region starts below it and its sentence
    // never enters the window.
    $pin = refineRows($w)->first();
    $pin->sentenceMeaningMatches()->where('side', 'b')->delete();
    $pin->update(['alignment_chunk' => MeaningMatch::HUMAN_CHUNK, 'similarity' => 1.0]);

    $fake = fakePython();

    $fake->alignHandler = function (array $payload): array {
        expect($payload['a_sentences'])->toBe(['English one.', 'English two long.'])
            ->and($payload['b_sentences'])->toBe(['Русский один.', 'Русский два.', 'Русский три.'])
            ->and($payload)->not->toHaveKey('landmarks');

        return [
            ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.70],
            ['a_start' => 1, 'a_end' => 2, 'b_start' => 1, 'b_end' => 3, 'score' => 0.87],
        ];
    };

    $summary = AlignmentRefineService::create()->refine($w['match']);

    expect($summary['applied'])->toBe(1)
        ->and($pin->refresh()->sentenceMeaningMatches()->count())->toBe(1);
});

it('skips a match whose status is not completed', function () {
    $w = refineWorld();
    $w['match']->update(['status' => 'aligning']);

    $fake = fakePython();

    $summary = AlignmentRefineService::create()->refine($w['match']);

    expect($summary['status'])->toBe('skipped')
        ->and($summary['applied'])->toBe(0)
        ->and($fake->alignPayloads)->toBe([])
        ->and($w['match']->refresh()->status)->toBe('aligning');
});

it('refines through the artisan command', function () {
    $w = refineWorld();

    fakePython()->aligning(fusionRegrouping());

    artisan('alignments:refine', ['entityMatch' => $w['match']->id])
        ->assertSuccessful();

    expect(refineRows($w))->toHaveCount(3)
        ->and($w['match']->refresh()->status)->toBe('completed');
});

it('queues the refine round from the editor endpoint for an editor', function () {
    Bus::fake();

    $w = refineWorld();
    $user = User::factory()->create(['role' => User::ROLE_ADMIN]);

    actingAs($user)
        ->postJson("/works/{$w['work']->id}/alignments/{$w['match']->id}/refine")
        ->assertOk()
        ->assertJson(['queued' => true]);

    Bus::assertDispatched(RefineEntitySentences::class);
});

it('forbids the refine endpoint for a user without edit access', function () {
    Bus::fake();

    $w = refineWorld();
    // An approved side freezes every alignment it takes part in (ADR 0034).
    $w['en']->update(['is_approved' => true]);
    $user = User::factory()->create();

    actingAs($user)
        ->postJson("/works/{$w['work']->id}/alignments/{$w['match']->id}/refine")
        ->assertForbidden();

    Bus::assertNotDispatched(RefineEntitySentences::class);
});

it('reports a 404 when the match belongs to another work', function () {
    Bus::fake();

    $w = refineWorld();
    $otherWork = createWork(['title' => 'Other Work']);
    $user = User::factory()->create(['role' => User::ROLE_ADMIN]);

    actingAs($user)
        ->postJson("/works/{$otherWork->id}/alignments/{$w['match']->id}/refine")
        ->assertNotFound();

    Bus::assertNotDispatched(RefineEntitySentences::class);
});

it('does not select candidates when only two-sided rows exist', function () {
    $w = refineWorld();

    // Close the orphan's one-sidedness: retire the fusion head and give its
    // English sentence to the orphan row as a plain 1:1.
    $w['fusionHead']->delete();
    $orphan = $w['orphan'];
    $orphan->sentenceMeaningMatches()->create([
        'entity_match_id' => $w['match']->id,
        'entity_sentence_id' => $w['enSentences'][2]->id,
        'side' => 'a',
    ]);

    $fake = fakePython();

    $summary = AlignmentRefineService::create()->refine($w['match']);

    expect($summary['status'])->toBe('refined')
        ->and($summary['regions'])->toBe(0)
        ->and($fake->alignPayloads)->toBe([]);
});
