<?php

use App\Jobs\AlignEntitySentences;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

function seedMeaningMatchJunction(MeaningMatch $match, EntitySentence $a, EntitySentence $b): void
{
    SentenceMeaningMatch::create([
        'entity_sentence_id' => $a->id,
        'meaning_match_id' => $match->id,
        'side' => 'a',
    ]);
    SentenceMeaningMatch::create([
        'entity_sentence_id' => $b->id,
        'meaning_match_id' => $match->id,
        'side' => 'b',
    ]);
}

it('preserves human rows and auto-landmarks, deletes low-confidence rows, and re-aligns the gaps', function () {
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

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    $enSentences = collect(range(1, 6))->map(fn (int $order): EntitySentence => EntitySentence::create([
        'entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => "English {$order}.",
        'order' => $order,
    ]));
    $ruSentences = collect(range(1, 6))->map(fn (int $order): EntitySentence => EntitySentence::create([
        'entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => "Russian {$order}.",
        'order' => $order,
    ]));

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'completed',
        'chunk_size' => 75,
        'max_n' => 2,
        'a_total_sentences' => 6,
        'b_total_sentences' => 6,
        'linked_count' => 4,
        'a_last_sentence_offset' => 6,
        'b_last_sentence_offset' => 6,
    ]);

    $humanRow = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 1.0,
        'alignment_chunk' => -1,
    ]);
    seedMeaningMatchJunction($humanRow, $enSentences[1], $ruSentences[1]);

    $autoLandmark = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 1024,
        'similarity' => 0.95,
        'alignment_chunk' => 0,
    ]);
    seedMeaningMatchJunction($autoLandmark, $enSentences[3], $ruSentences[3]);

    $lowConfidenceA = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 2048,
        'similarity' => 0.5,
        'alignment_chunk' => 0,
    ]);
    seedMeaningMatchJunction($lowConfidenceA, $enSentences[2], $ruSentences[2]);

    $lowConfidenceB = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 3072,
        'similarity' => 0.4,
        'alignment_chunk' => 0,
    ]);
    seedMeaningMatchJunction($lowConfidenceB, $enSentences[4], $ruSentences[4]);

    AlignEntitySentences::begin($entityMatch->id);

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('aligning')
        ->and($entityMatch->a_last_sentence_offset)->toBe(0)
        ->and($entityMatch->b_last_sentence_offset)->toBe(0)
        ->and($entityMatch->linked_count)->toBe(2)
        ->and($entityMatch->started_at)->not->toBeNull()
        ->and($entityMatch->completed_at)->toBeNull();

    expect(MeaningMatch::find($humanRow->id))->not->toBeNull()
        ->and(MeaningMatch::find($autoLandmark->id))->not->toBeNull()
        ->and(MeaningMatch::find($lowConfidenceA->id))->toBeNull()
        ->and(MeaningMatch::find($lowConfidenceB->id))->toBeNull();

    Bus::assertDispatched(AlignEntitySentences::class, 1);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('aligning')
        ->and($entityMatch->a_last_sentence_offset)->toBe(1)
        ->and($entityMatch->b_last_sentence_offset)->toBe(1)
        ->and($entityMatch->completed_at)->toBeNull();

    Bus::assertDispatched(AlignEntitySentences::class, 2);

    $guard = 0;

    while ($entityMatch->status === 'aligning' && $guard < 10) {
        (new AlignEntitySentences($entityMatch->id))->handle();
        $entityMatch->refresh();
        $guard++;
    }

    expect($entityMatch->status)->toBe('completed')
        ->and($entityMatch->a_last_sentence_offset)->toBe(6)
        ->and($entityMatch->b_last_sentence_offset)->toBe(6)
        ->and($entityMatch->completed_at)->not->toBeNull()
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)->count())->toBe(6);

    $humanRow->refresh();
    $autoLandmark->refresh();

    expect($humanRow->alignment_chunk)->toBe(-1)
        ->and($humanRow->similarity)->toBe('1.0000')
        ->and($humanRow->sideSentenceMeaningMatches('a')->pluck('entity_sentence_id')->all())->toEqual([$enSentences[1]->id])
        ->and($humanRow->sideSentenceMeaningMatches('b')->pluck('entity_sentence_id')->all())->toEqual([$ruSentences[1]->id])
        ->and($autoLandmark->alignment_chunk)->toBe(0)
        ->and($autoLandmark->similarity)->toBe('0.9500')
        ->and($autoLandmark->sideSentenceMeaningMatches('a')->pluck('entity_sentence_id')->all())->toEqual([$enSentences[3]->id])
        ->and($autoLandmark->sideSentenceMeaningMatches('b')->pluck('entity_sentence_id')->all())->toEqual([$ruSentences[3]->id]);

    expect(MeaningMatch::where('entity_match_id', $entityMatch->id)
        ->where('alignment_chunk', '>', 0)
        ->orderBy('alignment_chunk')
        ->pluck('alignment_chunk')
        ->unique()
        ->all())->toEqual([1, 2, 3]);

    expect(array_column($calls, 'a'))->toBe([
        ['English 1.'],
        ['English 3.'],
        ['English 5.', 'English 6.'],
    ]);

    foreach ($calls as $call) {
        expect($call['a'])->not->toContain('English 2.')
            ->and($call['a'])->not->toContain('English 4.')
            ->and($call['b'])->not->toContain('Russian 2.')
            ->and($call['b'])->not->toContain('Russian 4.');
    }

    Bus::assertDispatched(AlignEntitySentences::class, 3);
});

it('wipes every row including human-edited ones when starting from scratch', function () {
    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    $enSentence = EntitySentence::create([
        'entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'English.',
        'order' => 1,
    ]);
    $ruSentence = EntitySentence::create([
        'entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'Russian.',
        'order' => 1,
    ]);

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'pending',
        'chunk_size' => 200,
    ]);

    $humanRow = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 1.0,
        'alignment_chunk' => -1,
    ]);
    seedMeaningMatchJunction($humanRow, $enSentence, $ruSentence);

    $machineRow = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 1024,
        'similarity' => 0.5,
        'alignment_chunk' => 0,
    ]);
    seedMeaningMatchJunction($machineRow, $enSentence, $ruSentence);

    AlignEntitySentences::beginFromScratch($entityMatch->id);

    $entityMatch->refresh();

    expect(MeaningMatch::where('entity_match_id', $entityMatch->id)->count())->toBe(0)
        ->and($entityMatch->status)->toBe('aligning')
        ->and($entityMatch->a_total_sentences)->toBe(1)
        ->and($entityMatch->b_total_sentences)->toBe(1)
        ->and($entityMatch->chunk_size)->toBe(1)
        ->and($entityMatch->a_last_sentence_offset)->toBe(0)
        ->and($entityMatch->b_last_sentence_offset)->toBe(0)
        ->and($entityMatch->linked_count)->toBe(0)
        ->and($entityMatch->entity_similarity)->toBe('1.0000');

    Bus::assertDispatched(AlignEntitySentences::class, 1);
});

it('carves pools that never overlap a 1:N human landmark span', function () {
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

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    $enSentences = collect(range(1, 9))->map(fn (int $order): EntitySentence => EntitySentence::create([
        'entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => "English {$order}.",
        'order' => $order,
    ]));
    $ruSentences = collect(range(1, 9))->map(fn (int $order): EntitySentence => EntitySentence::create([
        'entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => "Russian {$order}.",
        'order' => $order,
    ]));

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'completed',
        'chunk_size' => 75,
        'max_n' => 2,
        'a_total_sentences' => 9,
        'b_total_sentences' => 9,
        'linked_count' => 1,
        'a_last_sentence_offset' => 9,
        'b_last_sentence_offset' => 9,
    ]);

    $humanRow = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 1.0,
        'alignment_chunk' => -1,
    ]);
    SentenceMeaningMatch::create([
        'entity_sentence_id' => $enSentences[4]->id,
        'meaning_match_id' => $humanRow->id,
        'side' => 'a',
    ]);
    foreach (range(0, 2) as $i) {
        SentenceMeaningMatch::create([
            'entity_sentence_id' => $ruSentences[4 + $i]->id,
            'meaning_match_id' => $humanRow->id,
            'side' => 'b',
        ]);
    }

    AlignEntitySentences::begin($entityMatch->id);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('aligning')
        ->and($entityMatch->a_last_sentence_offset)->toBe(4)
        ->and($entityMatch->b_last_sentence_offset)->toBe(4)
        ->and($entityMatch->completed_at)->toBeNull();

    $guard = 0;

    while ($entityMatch->status === 'aligning' && $guard < 10) {
        (new AlignEntitySentences($entityMatch->id))->handle();
        $entityMatch->refresh();
        $guard++;
    }

    expect($entityMatch->status)->toBe('completed')
        ->and($entityMatch->a_last_sentence_offset)->toBe(9)
        ->and($entityMatch->b_last_sentence_offset)->toBe(9);

    expect($calls)->toHaveCount(2)
        ->and(array_column($calls, 'a'))->toBe([
            ['English 1.', 'English 2.', 'English 3.', 'English 4.'],
            ['English 6.', 'English 7.', 'English 8.', 'English 9.'],
        ])
        ->and(array_column($calls, 'b'))->toBe([
            ['Russian 1.', 'Russian 2.', 'Russian 3.', 'Russian 4.'],
            ['Russian 8.', 'Russian 9.'],
        ]);

    foreach ($calls as $call) {
        expect($call['a'])->not->toContain('English 5.')
            ->and($call['b'])->not->toContain('Russian 5.')
            ->and($call['b'])->not->toContain('Russian 6.')
            ->and($call['b'])->not->toContain('Russian 7.');
    }

    $humanRow->refresh();

    expect($humanRow->sideSentenceMeaningMatches('a')->pluck('entity_sentence_id')->all())->toEqual([$enSentences[4]->id])
        ->and($humanRow->sideSentenceMeaningMatches('b')->pluck('entity_sentence_id')->all())
        ->toEqual($ruSentences->slice(4, 3)->pluck('id')->all());
});
