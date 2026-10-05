<?php

use App\Classes\MeaningMatchStore;
use App\Classes\SentenceAlignmentService;
use App\Jobs\AlignEntitySentences;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use Illuminate\Support\Facades\Bus;

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
    $fake = fakePython()->aligning([
        ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.9],
        ['a_start' => 1, 'a_end' => 2, 'b_start' => 1, 'b_end' => 2, 'score' => 0.9],
        ['a_start' => 2, 'a_end' => 3, 'b_start' => 2, 'b_end' => 3, 'score' => 0.9],
    ]);

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
        ->and(collect($fake->alignPayloads)->map(fn ($payload) => count($payload['a_sentences'] ?? []))->all())->toBe([3])
        ->and($entityMatch->status)->toBe('completed')
        ->and($entityMatch->a_last_sentence_offset)->toBe(3)
        ->and($entityMatch->b_last_sentence_offset)->toBe(3)
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)->count())->toBe(3);

    Bus::assertDispatched(AlignEntitySentences::class, 1);
});

it('persists one alignment chunk as meaning matches and junction rows', function () {
    fakePython()->aligning([
        ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.9],
    ]);

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
    fakePython()->aligning([
        ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.9],
    ]);

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
    fakePython()->aligning([
        ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.9],
    ]);

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
    fakePython()->aligning([]);

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
    fakePython()->aligning([]);

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
    fakePython()->aligning([]);

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

    // The advancing a head is stored as a skip row (total completeness covers
    // both sides mid-run too); both unmatched RU originals are junctioned by
    // the completion repair, one row each.
    expect($entityMatch->status)->toBe('completed')
        ->and($entityMatch->error_message)->toBeNull()
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)->count())->toBe(3)
        ->and(SentenceMeaningMatch::where('side', 'a')->count())->toBe(1)
        ->and(SentenceMeaningMatch::where('side', 'b')->count())->toBe(2)
        ->and(EntitySentence::whereDoesntHave('meaningJunctions')->where('entity_id', $ruEntity->id)->count())->toBe(0)
        ->and(EntitySentence::whereDoesntHave('meaningJunctions')->where('entity_id', $enEntity->id)->count())->toBe(0);

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
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)->count())->toBe(4)
        ->and($inserted)->not->toBeNull()
        ->and((float) $inserted->similarity)->toBe(0.0)
        ->and($inserted->sideSentenceMeaningMatches('a')->first()->entity_sentence_id)->toBe($enSentences[1]->id)
        ->and($inserted->sideSentenceMeaningMatches('b')->count())->toBe(0)
        ->and(EntitySentence::whereDoesntHave('meaningJunctions')->where('entity_id', $enEntity->id)->count())->toBe(0)
        // Total completeness (ADR 0048): the junction-less translation-side
        // sentence is repaired too, not only the original side.
        ->and(EntitySentence::whereDoesntHave('meaningJunctions')->where('entity_id', $ruEntity->id)->count())->toBe(0);

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
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)->count())->toBe(4)
        ->and($enSentences[1]->refresh()->meaningJunctions()->count())->toBe(1)
        ->and(EntitySentence::whereDoesntHave('meaningJunctions')->where('entity_id', $enEntity->id)->count())->toBe(0)
        ->and(EntitySentence::whereDoesntHave('meaningJunctions')->where('entity_id', $ruEntity->id)->count())->toBe(0);

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
    fakePython()->aligning([
        ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 5, 'score' => 0.95],
    ]);

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
    $fake = fakePython()->aligning([]);

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

    $payload = $fake->alignPayloads[0] ?? [];

    expect($payload['a_sentences'] ?? [])->toBe([str_repeat('a', 100), str_repeat('b', 100)])
        ->and($payload['b_sentences'] ?? [])->toBe([str_repeat('c', 100)])
        ->and($payload['max_window'] ?? null)->toBe(1);
});

it('uses a sequential slice where the RU window tracks the EN window without overlap', function () {
    $fake = fakePython()->aligning([
        ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.9],
        ['a_start' => 1, 'a_end' => 2, 'b_start' => 1, 'b_end' => 2, 'score' => 0.9],
        ['a_start' => 2, 'a_end' => 3, 'b_start' => 2, 'b_end' => 3, 'score' => 0.9],
    ]);

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

    expect(collect($fake->alignPayloads)->map(fn ($payload) => [
        'a_count' => count($payload['a_sentences'] ?? []),
        'b_count' => count($payload['b_sentences'] ?? []),
    ])->all())->toBe([
        ['a_count' => 3, 'b_count' => 3],
    ]);

    $entityMatch->refresh();

    expect($entityMatch->a_last_sentence_offset)->toBe(3)
        ->and($entityMatch->b_last_sentence_offset)->toBe(3)
        ->and($entityMatch->status)->toBe('aligning');

    Bus::assertDispatched(AlignEntitySentences::class, 1);
});

it('trims low-score tail matches and advances the cursor to the last anchor', function () {
    fakePython()->aligning([
        ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.9],
        ['a_start' => 1, 'a_end' => 2, 'b_start' => 1, 'b_end' => 2, 'score' => 0.3],
    ]);

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
    fakePython()->aligning([
        ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.3],
        ['a_start' => 1, 'a_end' => 2, 'b_start' => 1, 'b_end' => 2, 'score' => 0.2],
    ]);

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
    fakePython()->aligning([
        ['a_start' => 0, 'a_end' => 2, 'b_start' => 0, 'b_end' => 2, 'score' => 0.2],
    ]);

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
    fakePython()->aligning([
        ['a_start' => 0, 'a_end' => 2, 'b_start' => 0, 'b_end' => 2, 'score' => 0.9],
    ]);

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
    fakePython()->aligning([
        ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.9],
        ['a_start' => 1, 'a_end' => 2, 'b_start' => 1, 'b_end' => 2, 'score' => 0.9],
        ['a_start' => 2, 'a_end' => 3, 'b_start' => 2, 'b_end' => 3, 'score' => 0.9],
        ['a_start' => 3, 'a_end' => 4, 'b_start' => 3, 'b_end' => 4, 'score' => 0.9],
        ['a_start' => 4, 'a_end' => 5, 'b_start' => 4, 'b_end' => 5, 'score' => 0.9],
    ]);

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
    $fake = fakePython()->aligning([
        ['a_start' => 0, 'a_end' => 2, 'b_start' => 0, 'b_end' => 2, 'score' => 0.9],
    ]);

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

    expect(collect($fake->alignPayloads)->map(fn ($payload) => [
        'a_count' => count($payload['a_sentences'] ?? []),
        'b_count' => count($payload['b_sentences'] ?? []),
    ])->all())->toBe([
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
    fakePython()->aligning([
        ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.9],
        ['a_start' => 1, 'a_end' => 2, 'b_start' => 1, 'b_end' => 2, 'score' => 0.9],
        ['a_start' => 2, 'a_end' => 3, 'b_start' => 2, 'b_end' => 3, 'score' => 0.9],
        ['a_start' => 3, 'a_end' => 4, 'b_start' => 3, 'b_end' => 4, 'score' => 0.9],
    ]);

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
    $fake = fakePython()->aligning([
        ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.9],
        ['a_start' => 1, 'a_end' => 2, 'b_start' => 1, 'b_end' => 2, 'score' => 0.9],
    ]);

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

    expect(collect($fake->alignPayloads)->map(fn ($payload) => [
        'a_count' => count($payload['a_sentences'] ?? []),
        'b_count' => count($payload['b_sentences'] ?? []),
    ])->all())->toBe([
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

it('force-advances the a cursor past a mid-pool stall while the b cursor stays', function () {
    fakePython()->aligning([
        ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.9],
    ]);

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    $enSentences = collect(range(1, 5))->map(fn (int $order): EntitySentence => EntitySentence::create([
        'entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => "English {$order}.",
        'order' => $order,
    ]));
    $ruSentences = collect(range(1, 5))->map(fn (int $order): EntitySentence => EntitySentence::create([
        'entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => "Russian {$order}.",
        'order' => $order,
    ]));

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'aligning',
        'chunk_size' => 2,
        'max_n' => 1,
        'a_total_sentences' => 5,
        'b_total_sentences' => 5,
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
        'entity_sentence_id' => $enSentences[1]->id,
        'meaning_match_id' => $seed->id,
        'side' => 'a',
    ]);
    SentenceMeaningMatch::create([
        'entity_sentence_id' => $ruSentences[1]->id,
        'meaning_match_id' => $seed->id,
        'side' => 'b',
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('aligning')
        ->and($entityMatch->a_last_sentence_offset)->toBe(3)
        ->and($entityMatch->b_last_sentence_offset)->toBe(2)
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)->count())->toBe(1);

    Bus::assertDispatched(AlignEntitySentences::class, 1);
});

it('advances the cursors to the window end when the last chunk stores trailing skips', function () {
    fakePython()->aligning([
        ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.9],
    ]);

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

    // The rolled-back prior match drags the window back to offset 1, so the
    // last chunk's window is [1, 4): the committed pair covers sentence 1
    // and trailing skips cover sentences 2 and 3 on both sides.
    $seed = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 0.8,
        'alignment_chunk' => 0,
    ]);
    SentenceMeaningMatch::create([
        'entity_sentence_id' => $enSentences[1]->id,
        'meaning_match_id' => $seed->id,
        'side' => 'a',
    ]);
    SentenceMeaningMatch::create([
        'entity_sentence_id' => $ruSentences[1]->id,
        'meaning_match_id' => $seed->id,
        'side' => 'b',
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('completed')
        ->and($entityMatch->a_last_sentence_offset)->toBe(4)
        ->and($entityMatch->b_last_sentence_offset)->toBe(4)
        // 1 re-aligned pair + trailing skips for sentences 2 and 3 on both
        // sides + the completion repair rows for sentence 0 on both sides,
        // which the rollback dragged out of the window and no chunk
        // re-covered.
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)->count())->toBe(7)
        ->and($enSentences[3]->refresh()->meaningJunctions()->count())->toBe(1)
        ->and($ruSentences[3]->refresh()->meaningJunctions()->count())->toBe(1);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

it('does not junction the parked original-side head into a premature skip row', function () {
    fakePython()->aligning([]);

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $languages = createLanguages();
    $work = createWork(['original_language_id' => $languages['ru']->id]);
    $enEntity = createEntity('en', $work, ['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    foreach (range(1, 3) as $order) {
        EntitySentence::create([
            'entity_id' => $enEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "English {$order}.",
            'order' => $order,
        ]);
    }

    $ruSentence = EntitySentence::create([
        'entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'Russian.',
        'order' => 1,
    ]);

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'aligning',
        'chunk_size' => 1,
        'max_n' => 1,
        'a_total_sentences' => 3,
        'b_total_sentences' => 1,
        'a_last_sentence_offset' => 0,
        'b_last_sentence_offset' => 0,
    ]);

    // RU is the original side and its head stays parked while the a cursor
    // drains: no run may junction it into a skip row here — the parked head
    // is re-fed into every window and finalize junctions it if unmatched. The
    // advancing (translation) side's heads DO get skip rows now — total
    // completeness covers them mid-run too.
    (new AlignEntitySentences($entityMatch->id))->handle();
    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    expect($entityMatch->a_last_sentence_offset)->toBe(2)
        ->and($entityMatch->b_last_sentence_offset)->toBe(0)
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)->count())->toBe(2)
        ->and(SentenceMeaningMatch::where('side', 'a')->count())->toBe(2)
        ->and(SentenceMeaningMatch::where('side', 'b')->count())->toBe(0)
        ->and($ruSentence->refresh()->meaningJunctions()->count())->toBe(0);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('completed')
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)->count())->toBe(4)
        ->and(SentenceMeaningMatch::where('side', 'a')->count())->toBe(3)
        ->and(SentenceMeaningMatch::where('side', 'b')->count())->toBe(1)
        ->and($ruSentence->refresh()->meaningJunctions()->count())->toBe(1);

    Bus::assertDispatched(AlignEntitySentences::class, 2);
});

it('keeps meaning match order in document position while re-aligning around a landmark', function () {
    fakePython()->aligning([
        ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.5],
    ]);

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
        'chunk_size' => 75,
        'max_n' => 1,
        'a_total_sentences' => 3,
        'b_total_sentences' => 3,
        'a_last_sentence_offset' => 0,
        'b_last_sentence_offset' => 0,
    ]);

    // A landmark pins the second pair; persistSegment writes the re-aligned
    // pool AFTER it — mid-run rows land append-after-max and only the
    // completion pass puts the sequence back into document order (ADR 0043).
    $landmark = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 1024,
        'similarity' => 0.95,
        'alignment_chunk' => -1,
    ]);
    SentenceMeaningMatch::create([
        'entity_sentence_id' => $enSentences[1]->id,
        'meaning_match_id' => $landmark->id,
        'side' => 'a',
    ]);
    SentenceMeaningMatch::create([
        'entity_sentence_id' => $ruSentences[1]->id,
        'meaning_match_id' => $landmark->id,
        'side' => 'b',
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('aligning')
        ->and($entityMatch->a_last_sentence_offset)->toBe(1)
        ->and($entityMatch->b_last_sentence_offset)->toBe(1);

    Bus::assertDispatched(AlignEntitySentences::class, 1);

    // The completion resequence renumbers by document position: the pool
    // before the landmark must end up sorting before it.
    MeaningMatchStore::create()->resequenceMatchesByDocumentPosition($entityMatch);

    $rows = MeaningMatch::query()
        ->where('entity_match_id', $entityMatch->id)
        ->orderBy('order')
        ->get();

    $firstSideA = EntitySentence::find(
        $rows[0]->sentenceMeaningMatches()->where('side', 'a')->first()->entity_sentence_id
    );

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->order)->toBe(0)
        ->and($firstSideA->content)->toBe('English 1.', 'the pool before the landmark must sort before it after resequencing');
});

it('replaces stale machine rows covering the sentences of a re-stored window', function () {
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

    $entityMatch = createEntityMatch($enEntity, $ruEntity, ['status' => 'aligning']);

    // A re-fed window's stale row from an earlier chunk claims the same pair.
    $stale = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 0.3,
        'alignment_chunk' => 5,
    ]);
    SentenceMeaningMatch::create([
        'entity_sentence_id' => $enSentence->id,
        'meaning_match_id' => $stale->id,
        'side' => 'a',
    ]);
    SentenceMeaningMatch::create([
        'entity_sentence_id' => $ruSentence->id,
        'meaning_match_id' => $stale->id,
        'side' => 'b',
    ]);

    MeaningMatchStore::create()->storeAlignmentSegmentFromMatches(
        $entityMatch,
        6,
        [['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.5]],
        collect([$enSentence]),
        collect([$ruSentence]),
        true,
    );

    expect(MeaningMatch::query()->whereKey($stale->id)->exists())->toBeFalse()
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)->count())->toBe(1)
        ->and($enSentence->refresh()->meaningJunctions()->count())->toBe(1)
        ->and($ruSentence->refresh()->meaningJunctions()->count())->toBe(1);
});

it('does not junction landmark sentences from a re-fed machine window', function () {
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

    $entityMatch = createEntityMatch($enEntity, $ruEntity, ['status' => 'aligning']);

    $landmark = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 1.0,
        'alignment_chunk' => -1,
    ]);
    SentenceMeaningMatch::create([
        'entity_sentence_id' => $enSentence->id,
        'meaning_match_id' => $landmark->id,
        'side' => 'a',
    ]);
    SentenceMeaningMatch::create([
        'entity_sentence_id' => $ruSentence->id,
        'meaning_match_id' => $landmark->id,
        'side' => 'b',
    ]);

    // A re-fed window (duplicated job, retry) proposes the same pair the
    // human row already pins. Landmark sentences are reserved at write time:
    // the machine row junctioning only them is never stored, so no duplicate
    // junction exists even transiently.
    MeaningMatchStore::create()->storeAlignmentSegmentFromMatches(
        $entityMatch,
        6,
        [['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.5]],
        collect([$enSentence]),
        collect([$ruSentence]),
        true,
    );

    expect(MeaningMatch::query()->whereKey($landmark->id)->exists())->toBeTrue()
        ->and(MeaningMatch::where('entity_match_id', $entityMatch->id)->count())->toBe(1)
        ->and($enSentence->refresh()->meaningJunctions()->count())->toBe(1)
        ->and($ruSentence->refresh()->meaningJunctions()->count())->toBe(1);
});

it('passes landmarks and high confidence to the alignment endpoint', function () {
    $fake = fakePython()->aligning([]);

    $aSentence = new EntitySentence(['content' => 'English.', 'order' => 1]);
    $aSentence->id = 1;
    $bSentence = new EntitySentence(['content' => 'Russian.', 'order' => 1]);
    $bSentence->id = 1;

    app(SentenceAlignmentService::class)->alignChunkRemote(
        collect([$aSentence]),
        collect([$bSentence]),
        5,
        landmarks: [
            ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1],
        ],
        highConfidence: 0.9,
    );

    expect($fake->alignPayloads[0]['landmarks'] ?? null)->toBe([
        ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1],
    ])
        ->and($fake->alignPayloads[0]['high_confidence'] ?? null)->toBe(0.9);
});

it('omits landmark and high confidence keys from the payload when not given', function () {
    $fake = fakePython()->aligning([]);

    $aSentence = new EntitySentence(['content' => 'English.', 'order' => 1]);
    $aSentence->id = 1;
    $bSentence = new EntitySentence(['content' => 'Russian.', 'order' => 1]);
    $bSentence->id = 1;

    app(SentenceAlignmentService::class)->alignChunkRemote(
        collect([$aSentence]),
        collect([$bSentence]),
        5,
    );

    expect($fake->alignPayloads)->toHaveCount(1)
        ->and(array_key_exists('landmarks', $fake->alignPayloads[0]))->toBeFalse()
        ->and(array_key_exists('high_confidence', $fake->alignPayloads[0]))->toBeFalse();
});
