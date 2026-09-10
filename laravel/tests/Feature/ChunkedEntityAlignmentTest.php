<?php

use App\Classes\SentenceAlignmentService;
use App\Jobs\AlignEntitySentences;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

it('configures the alignment job to retry with backoff', function () {
    $job = new AlignEntitySentences(1);

    expect($job->timeout)->toBe(600)
        ->and($job->tries)->toBe(5)
        ->and($job->backoff())->toEqual([30, 60, 120, 300]);
});

it('begins a fresh alignment run from a pending entity match', function () {
    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, [
        'name' => 'English',
        'signature' => json_encode([1.0, 0.0]),
    ]);
    $ruEntity = createEntity('ru', $work, [
        'name' => 'Russian',
        'signature' => json_encode([1.0, 0.0]),
    ]);

    foreach (range(1, 80) as $order) {
        EntitySentence::create([
            'entity_id' => $enEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "English sentence {$order}.",
            'order' => $order,
        ]);
    }

    foreach (range(1, 80) as $order) {
        EntitySentence::create([
            'entity_id' => $ruEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "Russian sentence {$order}.",
            'order' => $order,
        ]);
    }

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'pending',
        'chunk_size' => 200,
    ]);

    AlignEntitySentences::beginFromScratch($entityMatch->id);

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('aligning')
        ->and($entityMatch->chunk_size)->toBe(75)
        ->and($entityMatch->max_n)->toBe(6)
        ->and($entityMatch->a_total_sentences)->toBe(80)
        ->and($entityMatch->b_total_sentences)->toBe(80)
        ->and($entityMatch->a_last_sentence_offset)->toBe(0)
        ->and($entityMatch->b_last_sentence_offset)->toBe(0)
        ->and($entityMatch->linked_count)->toBe(0)
        ->and($entityMatch->started_at)->not->toBeNull()
        ->and($entityMatch->completed_at)->toBeNull()
        ->and($entityMatch->entity_similarity)->toBe('1.0000');

    Bus::assertDispatched(AlignEntitySentences::class, fn ($job) => true);
});

it('fails a begin run when verify rejects the pair', function () {
    Bus::fake();

    $work = createWork();
    $enEntity = createEntity('en', $work, [
        'name' => 'English',
        'signature' => json_encode([1.0, 0.0]),
    ]);
    $ruEntity = createEntity('ru', $work, [
        'name' => 'Russian',
        'signature' => json_encode([0.0, 1.0]),
    ]);

    EntitySentence::create([
        'entity_id' => $enEntity->id,
        'content' => 'English.',
        'order' => 1,
    ]);
    EntitySentence::create([
        'entity_id' => $ruEntity->id,
        'content' => 'Russian.',
        'order' => 1,
    ]);

    $entityMatch = createEntityMatch($enEntity, $ruEntity, ['status' => 'pending']);

    AlignEntitySentences::beginFromScratch($entityMatch->id);

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('failed')
        ->and($entityMatch->error_message)->not->toBeNull()
        ->and($entityMatch->started_at)->not->toBeNull()
        ->and($entityMatch->completed_at)->not->toBeNull();

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

it('drains the original side as skip rows when one side has no sentences', function () {
    Bus::fake();

    $work = createWork();
    $enEntity = createEntity('en', $work, [
        'name' => 'English',
        'signature' => json_encode([1.0, 0.0]),
    ]);
    $ruEntity = createEntity('ru', $work, [
        'name' => 'Russian',
        'signature' => json_encode([1.0, 0.0]),
    ]);

    EntitySentence::create([
        'entity_id' => $enEntity->id,
        'content' => 'English.',
        'order' => 1,
    ]);

    $entityMatch = createEntityMatch($enEntity, $ruEntity, ['status' => 'pending']);

    AlignEntitySentences::beginFromScratch($entityMatch->id);

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('completed')
        ->and($entityMatch->error_message)->toBeNull()
        ->and($entityMatch->completed_at)->not->toBeNull()
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)->count())->toBe(1)
        ->and(SentenceMeaningMatch::count())->toBe(1)
        ->and(EntitySentence::whereDoesntHave('meaningJunctions')->where('entity_id', $enEntity->id)->count())->toBe(0);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

it('runs a small entity as a single chunk regardless of the configured chunk size', function () {
    $calls = [];
    Http::fake(function (Request $request) use (&$calls) {
        $calls[] = count($request->data()['a_sentences'] ?? []);

        return Http::response([
            'matches' => [
                ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.9],
                ['a_start' => 1, 'a_end' => 2, 'b_start' => 1, 'b_end' => 2, 'score' => 0.9],
                ['a_start' => 2, 'a_end' => 3, 'b_start' => 2, 'b_end' => 3, 'score' => 0.9],
            ],
            'unmatched_a' => [],
            'unmatched_b' => [],
        ]);
    });

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, [
        'name' => 'English',
        'signature' => json_encode([1.0, 0.0]),
    ]);
    $ruEntity = createEntity('ru', $work, [
        'name' => 'Russian',
        'signature' => json_encode([1.0, 0.0]),
    ]);

    foreach (range(1, 3) as $order) {
        EntitySentence::create([
            'entity_id' => $enEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "English {$order}.",
            'order' => $order,
        ]);
        EntitySentence::create([
            'entity_id' => $ruEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "Russian {$order}.",
            'order' => $order,
        ]);
    }

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'pending',
        'chunk_size' => 1,
    ]);

    AlignEntitySentences::beginFromScratch($entityMatch->id);

    $entityMatch->refresh();
    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    expect($entityMatch->chunk_size)->toBe(3)
        ->and($calls)->toBe([3])
        ->and($entityMatch->status)->toBe('completed')
        ->and($entityMatch->a_last_sentence_offset)->toBe(3)
        ->and($entityMatch->b_last_sentence_offset)->toBe(3)
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)->count())->toBe(3);

    Bus::assertDispatched(AlignEntitySentences::class, 1);
});

it('persists one alignment chunk as meaning matches and junction rows', function () {
    Http::fake(function (Request $request) {
        return Http::response([
            'matches' => [
                ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.9],
            ],
            'unmatched_a' => [],
            'unmatched_b' => [],
        ]);
    });

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, [
        'name' => 'English',
        'signature' => json_encode([1.0, 0.0]),
    ]);
    $ruEntity = createEntity('ru', $work, [
        'name' => 'Russian',
        'signature' => json_encode([1.0, 0.0]),
    ]);

    $enSentence = EntitySentence::create([
        'entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'English sentence.',
        'order' => 1,
    ]);
    $ruSentence = EntitySentence::create([
        'entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'Russian sentence.',
        'order' => 1,
    ]);

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'aligning',
        'chunk_size' => 75,
        'max_n' => 1,
        'a_total_sentences' => 1,
        'b_total_sentences' => 1,
        'a_last_sentence_offset' => 0,
        'b_last_sentence_offset' => 0,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();
    $meaningMatch = MeaningMatch::where('entity_match_id', $entityMatch->id)->first();
    $enJunction = SentenceMeaningMatch::where('meaning_match_id', $meaningMatch->id)->where('side', 'a')->first();
    $ruJunction = SentenceMeaningMatch::where('meaning_match_id', $meaningMatch->id)->where('side', 'b')->first();

    expect($entityMatch->status)->toBe('completed')
        ->and($entityMatch->linked_count)->toBe(1)
        ->and($entityMatch->a_last_sentence_offset)->toBe(1)
        ->and($entityMatch->b_last_sentence_offset)->toBe(1)
        ->and($entityMatch->completed_at)->not->toBeNull()
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)->count())->toBe(1)
        ->and($meaningMatch->alignment_chunk)->toBe(0)
        ->and($meaningMatch->order)->toBe(0)
        ->and($enJunction->entity_sentence_id)->toBe($enSentence->id)
        ->and($ruJunction->entity_sentence_id)->toBe($ruSentence->id);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

it('preserves cursor and recreates the job when more sentences remain', function () {
    Http::fake(fn (Request $request) => Http::response([
        'matches' => [
            ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.9],
        ],
        'unmatched_a' => [],
        'unmatched_b' => [],
    ]));

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, [
        'name' => 'English',
        'signature' => json_encode([1.0, 0.0]),
    ]);
    $ruEntity = createEntity('ru', $work, [
        'name' => 'Russian',
        'signature' => json_encode([1.0, 0.0]),
    ]);

    foreach (range(1, 2) as $order) {
        EntitySentence::create([
            'entity_id' => $enEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "English {$order}.",
            'order' => $order,
        ]);
        EntitySentence::create([
            'entity_id' => $ruEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "Russian {$order}.",
            'order' => $order,
        ]);
    }

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'aligning',
        'chunk_size' => 1,
        'max_n' => 1,
        'a_total_sentences' => 2,
        'b_total_sentences' => 2,
        'a_last_sentence_offset' => 0,
        'b_last_sentence_offset' => 0,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('aligning')
        ->and($entityMatch->completed_at)->toBeNull()
        ->and($entityMatch->a_last_sentence_offset)->toBe(1)
        ->and($entityMatch->b_last_sentence_offset)->toBe(1);

    Bus::assertDispatched(AlignEntitySentences::class, 1);
});

it('completes when reaching the final chunk', function () {
    Http::fake(fn (Request $request) => Http::response([
        'matches' => [
            ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.9],
        ],
        'unmatched_a' => [],
        'unmatched_b' => [],
    ]));

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, [
        'name' => 'English',
        'signature' => json_encode([1.0, 0.0]),
    ]);
    $ruEntity = createEntity('ru', $work, [
        'name' => 'Russian',
        'signature' => json_encode([1.0, 0.0]),
    ]);

    foreach (range(1, 2) as $order) {
        EntitySentence::create([
            'entity_id' => $enEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "English {$order}.",
            'order' => $order,
        ]);
        EntitySentence::create([
            'entity_id' => $ruEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "Russian {$order}.",
            'order' => $order,
        ]);
    }

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'aligning',
        'chunk_size' => 1,
        'max_n' => 1,
        'a_total_sentences' => 2,
        'b_total_sentences' => 2,
        'a_last_sentence_offset' => 1,
        'b_last_sentence_offset' => 1,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('completed')
        ->and($entityMatch->completed_at)->not->toBeNull()
        ->and($entityMatch->a_last_sentence_offset)->toBe(2)
        ->and($entityMatch->b_last_sentence_offset)->toBe(2);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

it('drains remaining original sentences when RU sentences are exhausted before EN', function () {
    Http::fake(fn (Request $request) => Http::response([
        'matches' => [],
        'unmatched_a' => [],
        'unmatched_b' => [],
    ]));

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, [
        'name' => 'English',
        'signature' => json_encode([1.0, 0.0]),
    ]);
    $ruEntity = createEntity('ru', $work, [
        'name' => 'Russian',
        'signature' => json_encode([1.0, 0.0]),
    ]);

    EntitySentence::create([
        'entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'English 2.',
        'order' => 2,
    ]);

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'aligning',
        'chunk_size' => 1,
        'max_n' => 1,
        'a_total_sentences' => 2,
        'b_total_sentences' => 1,
        'a_last_sentence_offset' => 1,
        'b_last_sentence_offset' => 1,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    $skipped = MeaningMatch::where('entity_match_id', $entityMatch->id)->first();

    expect($entityMatch->status)->toBe('completed')
        ->and($entityMatch->error_message)->toBeNull()
        ->and($entityMatch->a_last_sentence_offset)->toBe(2)
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)->count())->toBe(1)
        ->and($skipped)->not->toBeNull()
        ->and((float) $skipped->similarity)->toBe(0.0)
        ->and($skipped->sideSentenceMeaningMatches('a')->count())->toBe(1)
        ->and($skipped->sideSentenceMeaningMatches('b')->count())->toBe(0)
        ->and(EntitySentence::whereDoesntHave('meaningJunctions')->where('entity_id', $enEntity->id)->count())->toBe(0);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

it('stores a single-sided skip row when a chunk commits no matches and advances', function () {
    Http::fake(fn (Request $request) => Http::response([
        'matches' => [],
        'unmatched_a' => [],
        'unmatched_b' => [],
    ]));

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    foreach (range(1, 2) as $order) {
        EntitySentence::create([
            'entity_id' => $enEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "English {$order}.",
            'order' => $order,
        ]);
    }

    EntitySentence::create([
        'entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'Russian.',
        'order' => 1,
    ]);

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'aligning',
        'chunk_size' => 1,
        'max_n' => 1,
        'a_total_sentences' => 2,
        'b_total_sentences' => 1,
        'a_last_sentence_offset' => 0,
        'b_last_sentence_offset' => 0,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    $firstEnSentence = EntitySentence::where('entity_id', $enEntity->id)->orderBy('order')->first();

    expect($entityMatch->status)->toBe('aligning')
        ->and($entityMatch->a_last_sentence_offset)->toBe(1)
        ->and($firstEnSentence->meaningJunctions()->count())->toBe(1)
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)->first()->sideSentenceMeaningMatches('b')->count())->toBe(0)
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)->count())->toBe(1);

    Bus::assertDispatched(AlignEntitySentences::class, 1);
});

it('drains the RU original tail as skip rows when RU is the original side and longer', function () {
    Http::fake(fn (Request $request) => Http::response([
        'matches' => [],
        'unmatched_a' => [],
        'unmatched_b' => [],
    ]));

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $languages = createLanguages();
    $work = createWork(['original_language_id' => $languages['ru']->id]);
    $enEntity = createEntity('en', $work, ['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    EntitySentence::create([
        'entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'English.',
        'order' => 1,
    ]);

    foreach (range(1, 2) as $order) {
        EntitySentence::create([
            'entity_id' => $ruEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "Russian {$order}.",
            'order' => $order,
        ]);
    }

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'aligning',
        'chunk_size' => 2,
        'max_n' => 2,
        'a_total_sentences' => 1,
        'b_total_sentences' => 2,
        'a_last_sentence_offset' => 0,
        'b_last_sentence_offset' => 0,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('completed')
        ->and($entityMatch->error_message)->toBeNull()
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)->count())->toBe(2)
        ->and(SentenceMeaningMatch::where('side', 'a')->count())->toBe(0)
        ->and(SentenceMeaningMatch::where('side', 'b')->count())->toBe(2)
        ->and(EntitySentence::whereDoesntHave('meaningJunctions')->where('entity_id', $ruEntity->id)->count())->toBe(0);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

it('inserts a position-aware skip row for a mid-text junction-less original sentence on completion', function () {
    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    $enSentences = collect(range(1, 3))->map(fn (int $order): EntitySentence => EntitySentence::create([
        'entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => "English {$order}.",
        'order' => $order,
    ]));
    $ruSentences = collect(range(1, 3))->map(fn (int $order): EntitySentence => EntitySentence::create([
        'entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => "Russian {$order}.",
        'order' => $order,
    ]));

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'aligning',
        'chunk_size' => 3,
        'max_n' => 3,
        'a_total_sentences' => 3,
        'b_total_sentences' => 3,
        'a_last_sentence_offset' => 3,
        'b_last_sentence_offset' => 3,
    ]);

    foreach ([0 => 0, 2 => 2048] as $index => $order) {
        $meaningMatch = MeaningMatch::create([
            'entity_match_id' => $entityMatch->id,
            'order' => $order,
            'similarity' => 0.95,
            'alignment_chunk' => -1,
        ]);

        SentenceMeaningMatch::create([
            'entity_sentence_id' => $enSentences[$index]->id,
            'meaning_match_id' => $meaningMatch->id,
            'side' => 'a',
        ]);
        SentenceMeaningMatch::create([
            'entity_sentence_id' => $ruSentences[$index]->id,
            'meaning_match_id' => $meaningMatch->id,
            'side' => 'b',
        ]);
    }

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    $inserted = MeaningMatch::where('entity_match_id', $entityMatch->id)
        ->where('order', '>', 0)
        ->where('order', '<', 2048)
        ->first();

    expect($entityMatch->status)->toBe('completed')
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)->count())->toBe(3)
        ->and($inserted)->not->toBeNull()
        ->and((float) $inserted->similarity)->toBe(0.0)
        ->and($inserted->sideSentenceMeaningMatches('a')->first()->entity_sentence_id)->toBe($enSentences[1]->id)
        ->and($inserted->sideSentenceMeaningMatches('b')->count())->toBe(0)
        ->and(EntitySentence::whereDoesntHave('meaningJunctions')->where('entity_id', $enEntity->id)->count())->toBe(0);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

it('completes without failing when a human-unlinked original sentence is still junction-less', function () {
    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    $enSentences = collect(range(1, 2))->map(fn (int $order): EntitySentence => EntitySentence::create([
        'entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => "English {$order}.",
        'order' => $order,
    ]));
    $ruSentences = collect(range(1, 2))->map(fn (int $order): EntitySentence => EntitySentence::create([
        'entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => "Russian {$order}.",
        'order' => $order,
    ]));

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'aligning',
        'chunk_size' => 2,
        'max_n' => 2,
        'a_total_sentences' => 2,
        'b_total_sentences' => 2,
        'a_last_sentence_offset' => 2,
        'b_last_sentence_offset' => 2,
    ]);

    $landmark = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 0.95,
        'alignment_chunk' => -1,
    ]);

    SentenceMeaningMatch::create([
        'entity_sentence_id' => $enSentences[0]->id,
        'meaning_match_id' => $landmark->id,
        'side' => 'a',
    ]);
    SentenceMeaningMatch::create([
        'entity_sentence_id' => $ruSentences[0]->id,
        'meaning_match_id' => $landmark->id,
        'side' => 'b',
    ]);

    MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 512,
        'similarity' => 0.0,
        'alignment_chunk' => -1,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('completed')
        ->and($entityMatch->error_message)->toBeNull()
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)->count())->toBe(3)
        ->and($enSentences[1]->refresh()->meaningJunctions()->count())->toBe(1)
        ->and(EntitySentence::whereDoesntHave('meaningJunctions')->where('entity_id', $enEntity->id)->count())->toBe(0);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

it('drains the original side as skip rows when the translation side has no sentences', function () {
    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    foreach (range(1, 2) as $order) {
        EntitySentence::create([
            'entity_id' => $enEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "English {$order}.",
            'order' => $order,
        ]);
    }

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'pending',
        'chunk_size' => 2,
        'max_n' => 2,
    ]);

    AlignEntitySentences::beginFromScratch($entityMatch->id);

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('completed')
        ->and($entityMatch->error_message)->toBeNull()
        ->and($entityMatch->a_total_sentences)->toBe(2)
        ->and($entityMatch->b_total_sentences)->toBe(0)
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)->count())->toBe(2)
        ->and(SentenceMeaningMatch::count())->toBe(2)
        ->and(EntitySentence::whereDoesntHave('meaningJunctions')->where('entity_id', $enEntity->id)->count())->toBe(0);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

it('skips stale alignment jobs when the entity match has been deleted', function () {
    (new AlignEntitySentences(999))->handle();

    expect(MeaningMatch::count())->toBe(0)
        ->and(SentenceMeaningMatch::count())->toBe(0);
});

it('persists one english sentence linked to five russian sentences in a single chunk', function () {
    Http::fake(fn (Request $request) => Http::response([
        'matches' => [
            ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 5, 'score' => 0.95],
        ],
        'unmatched_a' => [],
        'unmatched_b' => [],
    ]));

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    $enSentence = EntitySentence::create([
        'entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'English meaning.',
        'order' => 1,
    ]);

    $ruSentences = collect(range(1, 5))->map(fn (int $order): EntitySentence => EntitySentence::create([
        'entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => "Russian part {$order}.",
        'order' => $order,
    ]));

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'aligning',
        'chunk_size' => 75,
        'max_n' => 5,
        'a_total_sentences' => 1,
        'b_total_sentences' => 5,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();
    $meaningMatch = MeaningMatch::where('entity_match_id', $entityMatch->id)->first();
    $enJunctions = SentenceMeaningMatch::where('meaning_match_id', $meaningMatch->id)->where('side', 'a')->get();
    $ruJunctions = SentenceMeaningMatch::where('meaning_match_id', $meaningMatch->id)->where('side', 'b')
        ->get()
        ->sortBy(fn ($match) => $match->entitySentence?->order ?? 0)
        ->values();

    expect($entityMatch->status)->toBe('completed')
        ->and($entityMatch->linked_count)->toBe(1)
        ->and($enJunctions)->toHaveCount(1)
        ->and($enJunctions->pluck('entity_sentence_id')->all())->toEqual([$enSentence->id])
        ->and($ruJunctions->pluck('entity_sentence_id')->all())->toEqual($ruSentences->pluck('id')->all())
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)->count())->toBe(1);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

it('keeps the cursor untouched on failure so a re-run can resume', function () {
    $work = createWork();
    $enEntity = createEntity('en', $work, [
        'name' => 'English',
        'signature' => json_encode([1.0, 0.0]),
    ]);
    $ruEntity = createEntity('ru', $work, [
        'name' => 'Russian',
        'signature' => json_encode([1.0, 0.0]),
    ]);

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'aligning',
        'chunk_size' => 75,
        'max_n' => 1,
        'a_total_sentences' => 2,
        'b_total_sentences' => 2,
        'a_last_sentence_offset' => 1,
        'b_last_sentence_offset' => 1,
    ]);

    (new AlignEntitySentences($entityMatch->id))->failed(new RuntimeException('python exploded'));

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('failed')
        ->and($entityMatch->error_message)->toBe('python exploded')
        ->and($entityMatch->completed_at)->not->toBeNull()
        ->and($entityMatch->a_last_sentence_offset)->toBe(1)
        ->and($entityMatch->b_last_sentence_offset)->toBe(1);
});

it('sends full sentence contents to the alignment endpoint without php-side sampling', function () {
    Http::fake(function (Request $request) {
        return Http::response([
            'matches' => [],
            'unmatched_a' => [0, 1],
            'unmatched_b' => [0],
        ]);
    });

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, [
        'name' => 'English',
        'signature' => json_encode([1.0, 0.0]),
    ]);
    $ruEntity = createEntity('ru', $work, [
        'name' => 'Russian',
        'signature' => json_encode([1.0, 0.0]),
    ]);

    EntitySentence::create([
        'entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => str_repeat('a', 100),
        'order' => 1,
    ]);
    EntitySentence::create([
        'entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => str_repeat('b', 100),
        'order' => 2,
    ]);
    EntitySentence::create([
        'entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => str_repeat('c', 100),
        'order' => 1,
    ]);

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'aligning',
        'chunk_size' => 75,
        'max_n' => 1,
        'a_total_sentences' => 2,
        'b_total_sentences' => 1,
        'a_last_sentence_offset' => 0,
        'b_last_sentence_offset' => 0,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    Http::assertSent(function (Request $request): bool {
        $aTexts = $request->data()['a_sentences'] ?? [];
        $bTexts = $request->data()['b_sentences'] ?? [];

        return str_ends_with($request->url(), '/align')
            && $aTexts === [str_repeat('a', 100), str_repeat('b', 100)]
            && $bTexts === [str_repeat('c', 100)]
            && $request->data()['max_window'] === 1;
    });
});

it('uses a sequential slice where the RU window tracks the EN window without overlap', function () {
    $capturedOffsets = [];
    Http::fake(function (Request $request) use (&$capturedOffsets) {
        $capturedOffsets[] = [
            'a_count' => count($request->data()['a_sentences'] ?? []),
            'b_count' => count($request->data()['b_sentences'] ?? []),
        ];

        return Http::response([
            'matches' => [
                ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.9],
                ['a_start' => 1, 'a_end' => 2, 'b_start' => 1, 'b_end' => 2, 'score' => 0.9],
                ['a_start' => 2, 'a_end' => 3, 'b_start' => 2, 'b_end' => 3, 'score' => 0.9],
            ],
            'unmatched_a' => [],
            'unmatched_b' => [],
        ]);
    });

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, [
        'name' => 'English',
        'signature' => json_encode([1.0, 0.0]),
    ]);
    $ruEntity = createEntity('ru', $work, [
        'name' => 'Russian',
        'signature' => json_encode([1.0, 0.0]),
    ]);

    foreach (range(1, 5) as $order) {
        EntitySentence::create([
            'entity_id' => $enEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "EN {$order}.",
            'order' => $order,
        ]);
        EntitySentence::create([
            'entity_id' => $ruEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "RU {$order}.",
            'order' => $order,
        ]);
    }

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'aligning',
        'chunk_size' => 3,
        'max_n' => 2,
        'a_total_sentences' => 5,
        'b_total_sentences' => 5,
        'a_last_sentence_offset' => 0,
        'b_last_sentence_offset' => 0,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    expect($capturedOffsets)->toBe([
        ['a_count' => 3, 'b_count' => 3],
    ]);

    $entityMatch->refresh();

    expect($entityMatch->a_last_sentence_offset)->toBe(3)
        ->and($entityMatch->b_last_sentence_offset)->toBe(3)
        ->and($entityMatch->status)->toBe('aligning');

    Bus::assertDispatched(AlignEntitySentences::class, 1);
});

it('trims low-score tail matches and advances the cursor to the last anchor', function () {
    Http::fake(fn (Request $request) => Http::response([
        'matches' => [
            ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.9],
            ['a_start' => 1, 'a_end' => 2, 'b_start' => 1, 'b_end' => 2, 'score' => 0.3],
        ],
        'unmatched_a' => [],
        'unmatched_b' => [],
    ]));

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    foreach (range(1, 3) as $order) {
        EntitySentence::create([
            'entity_id' => $enEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "English {$order}.",
            'order' => $order,
        ]);
        EntitySentence::create([
            'entity_id' => $ruEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "Russian {$order}.",
            'order' => $order,
        ]);
    }

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'aligning',
        'chunk_size' => 2,
        'max_n' => 1,
        'a_total_sentences' => 3,
        'b_total_sentences' => 3,
        'a_last_sentence_offset' => 0,
        'b_last_sentence_offset' => 0,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('aligning')
        ->and($entityMatch->a_last_sentence_offset)->toBe(1)
        ->and($entityMatch->b_last_sentence_offset)->toBe(1)
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)->count())->toBe(1);

    Bus::assertDispatched(AlignEntitySentences::class, 1);
});

it('falls back to committing all matches when no match reaches the anchor threshold', function () {
    Http::fake(fn (Request $request) => Http::response([
        'matches' => [
            ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.3],
            ['a_start' => 1, 'a_end' => 2, 'b_start' => 1, 'b_end' => 2, 'score' => 0.2],
        ],
        'unmatched_a' => [],
        'unmatched_b' => [],
    ]));

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    foreach (range(1, 3) as $order) {
        EntitySentence::create([
            'entity_id' => $enEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "English {$order}.",
            'order' => $order,
        ]);
        EntitySentence::create([
            'entity_id' => $ruEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "Russian {$order}.",
            'order' => $order,
        ]);
    }

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'aligning',
        'chunk_size' => 2,
        'max_n' => 1,
        'a_total_sentences' => 3,
        'b_total_sentences' => 3,
        'a_last_sentence_offset' => 0,
        'b_last_sentence_offset' => 0,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('aligning')
        ->and($entityMatch->a_last_sentence_offset)->toBe(2)
        ->and($entityMatch->b_last_sentence_offset)->toBe(2)
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)->count())->toBe(2);

    Bus::assertDispatched(AlignEntitySentences::class, 1);
});

it('commits every match on the final chunk regardless of score', function () {
    Http::fake(fn (Request $request) => Http::response([
        'matches' => [
            ['a_start' => 0, 'a_end' => 2, 'b_start' => 0, 'b_end' => 2, 'score' => 0.2],
        ],
        'unmatched_a' => [],
        'unmatched_b' => [],
    ]));

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    foreach (range(1, 2) as $order) {
        EntitySentence::create([
            'entity_id' => $enEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "English {$order}.",
            'order' => $order,
        ]);
        EntitySentence::create([
            'entity_id' => $ruEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "Russian {$order}.",
            'order' => $order,
        ]);
    }

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'aligning',
        'chunk_size' => 2,
        'max_n' => 1,
        'a_total_sentences' => 2,
        'b_total_sentences' => 2,
        'a_last_sentence_offset' => 0,
        'b_last_sentence_offset' => 0,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('completed')
        ->and($entityMatch->completed_at)->not->toBeNull()
        ->and($entityMatch->a_last_sentence_offset)->toBe(2)
        ->and($entityMatch->b_last_sentence_offset)->toBe(2)
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)->count())->toBe(1);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

it('assigns monotonic alignment chunk ids across trimmed chunks', function () {
    Http::fake(fn (Request $request) => Http::response([
        'matches' => [
            ['a_start' => 0, 'a_end' => 2, 'b_start' => 0, 'b_end' => 2, 'score' => 0.9],
        ],
        'unmatched_a' => [],
        'unmatched_b' => [],
    ]));

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    foreach (range(1, 4) as $order) {
        EntitySentence::create([
            'entity_id' => $enEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "English {$order}.",
            'order' => $order,
        ]);
        EntitySentence::create([
            'entity_id' => $ruEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "Russian {$order}.",
            'order' => $order,
        ]);
    }

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'aligning',
        'chunk_size' => 2,
        'max_n' => 1,
        'a_total_sentences' => 4,
        'b_total_sentences' => 4,
        'a_last_sentence_offset' => 2,
        'b_last_sentence_offset' => 2,
    ]);

    MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 0.9,
        'alignment_chunk' => 0,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    expect(MeaningMatch::where('entity_match_id', $entityMatch->id)
        ->where('alignment_chunk', 0)
        ->count())->toBe(1)
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)
            ->where('alignment_chunk', 1)
            ->count())->toBe(1)
        ->and($entityMatch->status)->toBe('completed');

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

it('rolls back the last two meaning matches and re-aligns them with backward context', function () {
    Http::fake(fn (Request $request) => Http::response([
        'matches' => [
            ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.9],
            ['a_start' => 1, 'a_end' => 2, 'b_start' => 1, 'b_end' => 2, 'score' => 0.9],
            ['a_start' => 2, 'a_end' => 3, 'b_start' => 2, 'b_end' => 3, 'score' => 0.9],
            ['a_start' => 3, 'a_end' => 4, 'b_start' => 3, 'b_end' => 4, 'score' => 0.9],
            ['a_start' => 4, 'a_end' => 5, 'b_start' => 4, 'b_end' => 5, 'score' => 0.9],
        ],
        'unmatched_a' => [],
        'unmatched_b' => [],
    ]));

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
        'status' => 'aligning',
        'chunk_size' => 3,
        'max_n' => 1,
        'a_total_sentences' => 6,
        'b_total_sentences' => 6,
        'a_last_sentence_offset' => 3,
        'b_last_sentence_offset' => 3,
    ]);

    foreach ([0, 1, 2] as $index) {
        $match = MeaningMatch::create([
            'entity_match_id' => $entityMatch->id,
            'order' => $index,
            'similarity' => 0.8,
            'alignment_chunk' => 0,
        ]);
        SentenceMeaningMatch::create([
            'entity_sentence_id' => $enSentences[$index]->id,
            'meaning_match_id' => $match->id,
            'side' => 'a',
        ]);
        SentenceMeaningMatch::create([
            'entity_sentence_id' => $ruSentences[$index]->id,
            'meaning_match_id' => $match->id,
            'side' => 'b',
        ]);
    }

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('completed')
        ->and($entityMatch->a_last_sentence_offset)->toBe(6)
        ->and($entityMatch->b_last_sentence_offset)->toBe(6)
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)
            ->where('alignment_chunk', 0)
            ->count())->toBe(1)
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)
            ->where('alignment_chunk', 1)
            ->count())->toBe(5);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

it('skips rollback on the first chunk when no prior matches exist', function () {
    $capturedOffsets = [];
    Http::fake(function (Request $request) use (&$capturedOffsets) {
        $capturedOffsets[] = [
            'a_count' => count($request->data()['a_sentences'] ?? []),
            'b_count' => count($request->data()['b_sentences'] ?? []),
        ];

        return Http::response([
            'matches' => [
                ['a_start' => 0, 'a_end' => 2, 'b_start' => 0, 'b_end' => 2, 'score' => 0.9],
            ],
            'unmatched_a' => [],
            'unmatched_b' => [],
        ]);
    });

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    foreach (range(1, 4) as $order) {
        EntitySentence::create([
            'entity_id' => $enEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "English {$order}.",
            'order' => $order,
        ]);
        EntitySentence::create([
            'entity_id' => $ruEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "Russian {$order}.",
            'order' => $order,
        ]);
    }

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'aligning',
        'chunk_size' => 3,
        'max_n' => 1,
        'a_total_sentences' => 4,
        'b_total_sentences' => 4,
        'a_last_sentence_offset' => 0,
        'b_last_sentence_offset' => 0,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    expect($capturedOffsets)->toBe([
        ['a_count' => 3, 'b_count' => 3],
    ]);

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('aligning')
        ->and($entityMatch->a_last_sentence_offset)->toBe(2)
        ->and($entityMatch->b_last_sentence_offset)->toBe(2)
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)->count())->toBe(1);

    Bus::assertDispatched(AlignEntitySentences::class, 1);
});

it('rolls back a single prior match when the previous chunk committed just one', function () {
    Http::fake(fn (Request $request) => Http::response([
        'matches' => [
            ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.9],
            ['a_start' => 1, 'a_end' => 2, 'b_start' => 1, 'b_end' => 2, 'score' => 0.9],
            ['a_start' => 2, 'a_end' => 3, 'b_start' => 2, 'b_end' => 3, 'score' => 0.9],
            ['a_start' => 3, 'a_end' => 4, 'b_start' => 3, 'b_end' => 4, 'score' => 0.9],
        ],
        'unmatched_a' => [],
        'unmatched_b' => [],
    ]));

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    $enSentences = collect(range(1, 4))->map(fn (int $order): EntitySentence => EntitySentence::create([
        'entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => "English {$order}.",
        'order' => $order,
    ]));
    $ruSentences = collect(range(1, 4))->map(fn (int $order): EntitySentence => EntitySentence::create([
        'entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => "Russian {$order}.",
        'order' => $order,
    ]));

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'aligning',
        'chunk_size' => 2,
        'max_n' => 1,
        'a_total_sentences' => 4,
        'b_total_sentences' => 4,
        'a_last_sentence_offset' => 2,
        'b_last_sentence_offset' => 2,
    ]);

    $seed = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 0.8,
        'alignment_chunk' => 0,
    ]);
    SentenceMeaningMatch::create([
        'entity_sentence_id' => $enSentences[0]->id,
        'meaning_match_id' => $seed->id,
        'side' => 'a',
    ]);
    SentenceMeaningMatch::create([
        'entity_sentence_id' => $ruSentences[0]->id,
        'meaning_match_id' => $seed->id,
        'side' => 'b',
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('completed')
        ->and($entityMatch->a_last_sentence_offset)->toBe(4)
        ->and($entityMatch->b_last_sentence_offset)->toBe(4)
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)->count())->toBe(4);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

it('does not roll back human-edit sentinel matches', function () {
    $capturedOffsets = [];
    Http::fake(function (Request $request) use (&$capturedOffsets) {
        $capturedOffsets[] = [
            'a_count' => count($request->data()['a_sentences'] ?? []),
            'b_count' => count($request->data()['b_sentences'] ?? []),
        ];

        return Http::response([
            'matches' => [
                ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.9],
                ['a_start' => 1, 'a_end' => 2, 'b_start' => 1, 'b_end' => 2, 'score' => 0.9],
            ],
            'unmatched_a' => [],
            'unmatched_b' => [],
        ]);
    });

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    $enSentences = collect(range(1, 4))->map(fn (int $order): EntitySentence => EntitySentence::create([
        'entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => "English {$order}.",
        'order' => $order,
    ]));
    $ruSentences = collect(range(1, 4))->map(fn (int $order): EntitySentence => EntitySentence::create([
        'entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => "Russian {$order}.",
        'order' => $order,
    ]));

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'aligning',
        'chunk_size' => 2,
        'max_n' => 1,
        'a_total_sentences' => 4,
        'b_total_sentences' => 4,
        'a_last_sentence_offset' => 2,
        'b_last_sentence_offset' => 2,
    ]);

    $humanEdit = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 0.9,
        'alignment_chunk' => -1,
    ]);
    SentenceMeaningMatch::create([
        'entity_sentence_id' => $enSentences[0]->id,
        'meaning_match_id' => $humanEdit->id,
        'side' => 'a',
    ]);
    SentenceMeaningMatch::create([
        'entity_sentence_id' => $ruSentences[0]->id,
        'meaning_match_id' => $humanEdit->id,
        'side' => 'b',
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    expect($capturedOffsets)->toBe([
        ['a_count' => 2, 'b_count' => 2],
    ]);

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('completed')
        ->and($entityMatch->a_last_sentence_offset)->toBe(4)
        ->and($entityMatch->b_last_sentence_offset)->toBe(4)
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)
            ->where('alignment_chunk', -1)
            ->count())->toBe(1)
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)
            ->where('alignment_chunk', 0)
            ->count())->toBe(2);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

it('force-advances the cursor when a rolled-back commit cannot reach the stored offset', function () {
    Http::fake(fn (Request $request) => Http::response([
        'matches' => [
            ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.9],
        ],
        'unmatched_a' => [],
        'unmatched_b' => [],
    ]));

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    $enSentences = collect(range(1, 4))->map(fn (int $order): EntitySentence => EntitySentence::create([
        'entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => "English {$order}.",
        'order' => $order,
    ]));
    $ruSentences = collect(range(1, 4))->map(fn (int $order): EntitySentence => EntitySentence::create([
        'entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => "Russian {$order}.",
        'order' => $order,
    ]));

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'aligning',
        'chunk_size' => 2,
        'max_n' => 1,
        'a_total_sentences' => 4,
        'b_total_sentences' => 4,
        'a_last_sentence_offset' => 2,
        'b_last_sentence_offset' => 2,
    ]);

    foreach ([0, 1] as $index) {
        $seed = MeaningMatch::create([
            'entity_match_id' => $entityMatch->id,
            'order' => $index,
            'similarity' => 0.8,
            'alignment_chunk' => 0,
        ]);
        SentenceMeaningMatch::create([
            'entity_sentence_id' => $enSentences[$index]->id,
            'meaning_match_id' => $seed->id,
            'side' => 'a',
        ]);
        SentenceMeaningMatch::create([
            'entity_sentence_id' => $ruSentences[$index]->id,
            'meaning_match_id' => $seed->id,
            'side' => 'b',
        ]);
    }

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('aligning')
        ->and($entityMatch->a_last_sentence_offset)->toBe(3)
        ->and($entityMatch->b_last_sentence_offset)->toBe(2)
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)->count())->toBe(7);

    Bus::assertDispatched(AlignEntitySentences::class, 1);
});

it('passes landmarks and high confidence to the alignment endpoint', function () {
    Http::fake(fn (Request $request) => Http::response([
        'matches' => [],
        'unmatched_a' => [],
        'unmatched_b' => [],
    ]));

    $aSentence = new EntitySentence(['content' => 'English.', 'order' => 1]);
    $aSentence->id = 1;
    $bSentence = new EntitySentence(['content' => 'Russian.', 'order' => 1]);
    $bSentence->id = 1;

    $service = new SentenceAlignmentService('http://ext_python:8000', 30, 300);

    $service->alignChunkRemote(
        collect([$aSentence]),
        collect([$bSentence]),
        5,
        landmarks: [
            ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1],
        ],
        highConfidence: 0.9,
    );

    Http::assertSent(function (Request $request): bool {
        return str_ends_with($request->url(), '/align')
            && $request->data()['landmarks'] === [
                ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1],
            ]
            && $request->data()['high_confidence'] === 0.9;
    });
});

it('omits landmark and high confidence keys from the payload when not given', function () {
    Http::fake(fn (Request $request) => Http::response([
        'matches' => [],
        'unmatched_a' => [],
        'unmatched_b' => [],
    ]));

    $aSentence = new EntitySentence(['content' => 'English.', 'order' => 1]);
    $aSentence->id = 1;
    $bSentence = new EntitySentence(['content' => 'Russian.', 'order' => 1]);
    $bSentence->id = 1;

    $service = new SentenceAlignmentService('http://ext_python:8000', 30, 300);

    $service->alignChunkRemote(
        collect([$aSentence]),
        collect([$bSentence]),
        5,
    );

    Http::assertSent(function (Request $request): bool {
        return str_ends_with($request->url(), '/align')
            && ! array_key_exists('landmarks', $request->data())
            && ! array_key_exists('high_confidence', $request->data());
    });
});
