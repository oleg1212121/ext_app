<?php

use App\Classes\SentenceAlignmentService;
use App\Jobs\AlignEntitySentences;
use App\Models\EnEntity;
use App\Models\EnEntitySentence;
use App\Models\EnRuEntityMatch;
use App\Models\EnRuMeaningMatch;
use App\Models\EnSentenceMeaningMatch;
use App\Models\RuEntity;
use App\Models\RuEntitySentence;
use App\Models\RuSentenceMeaningMatch;
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
    $enEntity = EnEntity::create([
        'name' => 'English',
        'signature' => json_encode([1.0, 0.0]),
    ]);
    $ruEntity = RuEntity::create([
        'name' => 'Russian',
        'signature' => json_encode([1.0, 0.0]),
    ]);

    foreach (range(1, 80) as $order) {
        EnEntitySentence::create([
            'en_entity_id' => $enEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "English sentence {$order}.",
            'order' => $order,
        ]);
    }

    foreach (range(1, 80) as $order) {
        RuEntitySentence::create([
            'ru_entity_id' => $ruEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "Russian sentence {$order}.",
            'order' => $order,
        ]);
    }

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'pending',
        'chunk_size' => 200,
    ]);

    AlignEntitySentences::beginFromScratch($entityMatch->id);

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('aligning')
        ->and($entityMatch->chunk_size)->toBe(75)
        ->and($entityMatch->max_n)->toBe(6)
        ->and($entityMatch->en_total_sentences)->toBe(80)
        ->and($entityMatch->ru_total_sentences)->toBe(80)
        ->and($entityMatch->last_en_sentence_offset)->toBe(0)
        ->and($entityMatch->last_ru_sentence_offset)->toBe(0)
        ->and($entityMatch->linked_count)->toBe(0)
        ->and($entityMatch->started_at)->not->toBeNull()
        ->and($entityMatch->completed_at)->toBeNull()
        ->and($entityMatch->entity_similarity)->toBe('1.0000');

    Bus::assertDispatched(AlignEntitySentences::class, fn ($job) => true);
});

it('fails a begin run when verify rejects the pair', function () {
    Bus::fake();

    $enEntity = EnEntity::create([
        'name' => 'English',
        'signature' => json_encode([1.0, 0.0]),
    ]);
    $ruEntity = RuEntity::create([
        'name' => 'Russian',
        'signature' => json_encode([0.0, 1.0]),
    ]);

    EnEntitySentence::create([
        'en_entity_id' => $enEntity->id,
        'content' => 'English.',
        'order' => 1,
    ]);
    RuEntitySentence::create([
        'ru_entity_id' => $ruEntity->id,
        'content' => 'Russian.',
        'order' => 1,
    ]);

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'pending',
    ]);

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

    $enEntity = EnEntity::create([
        'name' => 'English',
        'signature' => json_encode([1.0, 0.0]),
    ]);
    $ruEntity = RuEntity::create([
        'name' => 'Russian',
        'signature' => json_encode([1.0, 0.0]),
    ]);

    EnEntitySentence::create([
        'en_entity_id' => $enEntity->id,
        'content' => 'English.',
        'order' => 1,
    ]);

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'pending',
    ]);

    AlignEntitySentences::beginFromScratch($entityMatch->id);

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('completed')
        ->and($entityMatch->error_message)->toBeNull()
        ->and($entityMatch->completed_at)->not->toBeNull()
        ->and(EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)->count())->toBe(1)
        ->and(EnSentenceMeaningMatch::count())->toBe(1)
        ->and(EnEntitySentence::whereDoesntHave('enMeaningMatches')->where('en_entity_id', $enEntity->id)->count())->toBe(0);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

it('runs a small entity as a single chunk regardless of the configured chunk size', function () {
    $calls = [];
    Http::fake(function (Request $request) use (&$calls) {
        $calls[] = count($request->data()['en_sentences'] ?? []);

        return Http::response([
            'matches' => [
                ['en_start' => 0, 'en_end' => 1, 'ru_start' => 0, 'ru_end' => 1, 'score' => 0.9],
                ['en_start' => 1, 'en_end' => 2, 'ru_start' => 1, 'ru_end' => 2, 'score' => 0.9],
                ['en_start' => 2, 'en_end' => 3, 'ru_start' => 2, 'ru_end' => 3, 'score' => 0.9],
            ],
            'unmatched_en' => [],
            'unmatched_ru' => [],
        ]);
    });

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $enEntity = EnEntity::create([
        'name' => 'English',
        'signature' => json_encode([1.0, 0.0]),
    ]);
    $ruEntity = RuEntity::create([
        'name' => 'Russian',
        'signature' => json_encode([1.0, 0.0]),
    ]);

    foreach (range(1, 3) as $order) {
        EnEntitySentence::create([
            'en_entity_id' => $enEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "English {$order}.",
            'order' => $order,
        ]);
        RuEntitySentence::create([
            'ru_entity_id' => $ruEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "Russian {$order}.",
            'order' => $order,
        ]);
    }

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
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
        ->and($entityMatch->last_en_sentence_offset)->toBe(3)
        ->and($entityMatch->last_ru_sentence_offset)->toBe(3)
        ->and(EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)->count())->toBe(3);

    Bus::assertDispatched(AlignEntitySentences::class, 1);
});

it('persists one alignment chunk as meaning matches and junction rows', function () {
    Http::fake(function (Request $request) {
        return Http::response([
            'matches' => [
                ['en_start' => 0, 'en_end' => 1, 'ru_start' => 0, 'ru_end' => 1, 'score' => 0.9],
            ],
            'unmatched_en' => [],
            'unmatched_ru' => [],
        ]);
    });

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $enEntity = EnEntity::create([
        'name' => 'English',
        'signature' => json_encode([1.0, 0.0]),
    ]);
    $ruEntity = RuEntity::create([
        'name' => 'Russian',
        'signature' => json_encode([1.0, 0.0]),
    ]);

    $enSentence = EnEntitySentence::create([
        'en_entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'English sentence.',
        'order' => 1,
    ]);
    $ruSentence = RuEntitySentence::create([
        'ru_entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'Russian sentence.',
        'order' => 1,
    ]);

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'aligning',
        'chunk_size' => 75,
        'max_n' => 1,
        'en_total_sentences' => 1,
        'ru_total_sentences' => 1,
        'last_en_sentence_offset' => 0,
        'last_ru_sentence_offset' => 0,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();
    $meaningMatch = EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)->first();
    $enJunction = EnSentenceMeaningMatch::where('en_ru_meaning_match_id', $meaningMatch->id)->first();
    $ruJunction = RuSentenceMeaningMatch::where('en_ru_meaning_match_id', $meaningMatch->id)->first();

    expect($entityMatch->status)->toBe('completed')
        ->and($entityMatch->linked_count)->toBe(1)
        ->and($entityMatch->last_en_sentence_offset)->toBe(1)
        ->and($entityMatch->last_ru_sentence_offset)->toBe(1)
        ->and($entityMatch->completed_at)->not->toBeNull()
        ->and(EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)->count())->toBe(1)
        ->and($meaningMatch->alignment_chunk)->toBe(0)
        ->and($meaningMatch->order)->toBe(0)
        ->and($enJunction->en_entity_sentence_id)->toBe($enSentence->id)
        ->and($ruJunction->ru_entity_sentence_id)->toBe($ruSentence->id);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

it('preserves cursor and recreates the job when more sentences remain', function () {
    Http::fake(fn (Request $request) => Http::response([
        'matches' => [
            ['en_start' => 0, 'en_end' => 1, 'ru_start' => 0, 'ru_end' => 1, 'score' => 0.9],
        ],
        'unmatched_en' => [],
        'unmatched_ru' => [],
    ]));

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $enEntity = EnEntity::create([
        'name' => 'English',
        'signature' => json_encode([1.0, 0.0]),
    ]);
    $ruEntity = RuEntity::create([
        'name' => 'Russian',
        'signature' => json_encode([1.0, 0.0]),
    ]);

    foreach (range(1, 2) as $order) {
        EnEntitySentence::create([
            'en_entity_id' => $enEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "English {$order}.",
            'order' => $order,
        ]);
        RuEntitySentence::create([
            'ru_entity_id' => $ruEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "Russian {$order}.",
            'order' => $order,
        ]);
    }

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'aligning',
        'chunk_size' => 1,
        'max_n' => 1,
        'en_total_sentences' => 2,
        'ru_total_sentences' => 2,
        'last_en_sentence_offset' => 0,
        'last_ru_sentence_offset' => 0,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('aligning')
        ->and($entityMatch->completed_at)->toBeNull()
        ->and($entityMatch->last_en_sentence_offset)->toBe(1)
        ->and($entityMatch->last_ru_sentence_offset)->toBe(1);

    Bus::assertDispatched(AlignEntitySentences::class, 1);
});

it('completes when reaching the final chunk', function () {
    Http::fake(fn (Request $request) => Http::response([
        'matches' => [
            ['en_start' => 0, 'en_end' => 1, 'ru_start' => 0, 'ru_end' => 1, 'score' => 0.9],
        ],
        'unmatched_en' => [],
        'unmatched_ru' => [],
    ]));

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $enEntity = EnEntity::create([
        'name' => 'English',
        'signature' => json_encode([1.0, 0.0]),
    ]);
    $ruEntity = RuEntity::create([
        'name' => 'Russian',
        'signature' => json_encode([1.0, 0.0]),
    ]);

    foreach (range(1, 2) as $order) {
        EnEntitySentence::create([
            'en_entity_id' => $enEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "English {$order}.",
            'order' => $order,
        ]);
        RuEntitySentence::create([
            'ru_entity_id' => $ruEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "Russian {$order}.",
            'order' => $order,
        ]);
    }

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'aligning',
        'chunk_size' => 1,
        'max_n' => 1,
        'en_total_sentences' => 2,
        'ru_total_sentences' => 2,
        'last_en_sentence_offset' => 1,
        'last_ru_sentence_offset' => 1,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('completed')
        ->and($entityMatch->completed_at)->not->toBeNull()
        ->and($entityMatch->last_en_sentence_offset)->toBe(2)
        ->and($entityMatch->last_ru_sentence_offset)->toBe(2);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

it('drains remaining original sentences when RU sentences are exhausted before EN', function () {
    Http::fake(fn (Request $request) => Http::response([
        'matches' => [],
        'unmatched_en' => [],
        'unmatched_ru' => [],
    ]));

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $enEntity = EnEntity::create([
        'name' => 'English',
        'signature' => json_encode([1.0, 0.0]),
    ]);
    $ruEntity = RuEntity::create([
        'name' => 'Russian',
        'signature' => json_encode([1.0, 0.0]),
    ]);

    EnEntitySentence::create([
        'en_entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'English 2.',
        'order' => 2,
    ]);

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'aligning',
        'chunk_size' => 1,
        'max_n' => 1,
        'en_total_sentences' => 2,
        'ru_total_sentences' => 1,
        'last_en_sentence_offset' => 1,
        'last_ru_sentence_offset' => 1,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    $skipped = EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)->first();

    expect($entityMatch->status)->toBe('completed')
        ->and($entityMatch->error_message)->toBeNull()
        ->and($entityMatch->last_en_sentence_offset)->toBe(2)
        ->and(EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)->count())->toBe(1)
        ->and($skipped)->not->toBeNull()
        ->and((float) $skipped->similarity)->toBe(0.0)
        ->and($skipped->enSentenceMatches()->count())->toBe(1)
        ->and($skipped->ruSentenceMatches()->count())->toBe(0)
        ->and(EnEntitySentence::whereDoesntHave('enMeaningMatches')->where('en_entity_id', $enEntity->id)->count())->toBe(0);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

it('stores a single-sided skip row when a chunk commits no matches and advances', function () {
    Http::fake(fn (Request $request) => Http::response([
        'matches' => [],
        'unmatched_en' => [],
        'unmatched_ru' => [],
    ]));

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $enEntity = EnEntity::create(['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = RuEntity::create(['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    foreach (range(1, 2) as $order) {
        EnEntitySentence::create([
            'en_entity_id' => $enEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "English {$order}.",
            'order' => $order,
        ]);
    }

    RuEntitySentence::create([
        'ru_entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'Russian.',
        'order' => 1,
    ]);

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'aligning',
        'chunk_size' => 1,
        'max_n' => 1,
        'en_total_sentences' => 2,
        'ru_total_sentences' => 1,
        'last_en_sentence_offset' => 0,
        'last_ru_sentence_offset' => 0,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    $firstEnSentence = EnEntitySentence::where('en_entity_id', $enEntity->id)->orderBy('order')->first();

    expect($entityMatch->status)->toBe('aligning')
        ->and($entityMatch->last_en_sentence_offset)->toBe(1)
        ->and($firstEnSentence->enMeaningMatches()->count())->toBe(1)
        ->and(EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)->first()->ruSentenceMatches()->count())->toBe(0)
        ->and(EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)->count())->toBe(1);

    Bus::assertDispatched(AlignEntitySentences::class, 1);
});

it('drains the RU original tail as skip rows when RU is the original side and longer', function () {
    Http::fake(fn (Request $request) => Http::response([
        'matches' => [],
        'unmatched_en' => [],
        'unmatched_ru' => [],
    ]));

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $enEntity = EnEntity::create(['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = RuEntity::create(['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    EnEntitySentence::create([
        'en_entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'English.',
        'order' => 1,
    ]);

    foreach (range(1, 2) as $order) {
        RuEntitySentence::create([
            'ru_entity_id' => $ruEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "Russian {$order}.",
            'order' => $order,
        ]);
    }

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'aligning',
        'is_original_en' => false,
        'chunk_size' => 2,
        'max_n' => 2,
        'en_total_sentences' => 1,
        'ru_total_sentences' => 2,
        'last_en_sentence_offset' => 0,
        'last_ru_sentence_offset' => 0,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('completed')
        ->and($entityMatch->error_message)->toBeNull()
        ->and(EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)->count())->toBe(2)
        ->and(EnSentenceMeaningMatch::count())->toBe(0)
        ->and(RuSentenceMeaningMatch::count())->toBe(2)
        ->and(RuEntitySentence::whereDoesntHave('ruMeaningMatches')->where('ru_entity_id', $ruEntity->id)->count())->toBe(0);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

it('inserts a position-aware skip row for a mid-text junction-less original sentence on completion', function () {
    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $enEntity = EnEntity::create(['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = RuEntity::create(['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    $enSentences = collect(range(1, 3))->map(fn (int $order): EnEntitySentence => EnEntitySentence::create([
        'en_entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => "English {$order}.",
        'order' => $order,
    ]));
    $ruSentences = collect(range(1, 3))->map(fn (int $order): RuEntitySentence => RuEntitySentence::create([
        'ru_entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => "Russian {$order}.",
        'order' => $order,
    ]));

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'aligning',
        'chunk_size' => 3,
        'max_n' => 3,
        'en_total_sentences' => 3,
        'ru_total_sentences' => 3,
        'last_en_sentence_offset' => 3,
        'last_ru_sentence_offset' => 3,
    ]);

    foreach ([0 => 0, 2 => 2048] as $index => $order) {
        $meaningMatch = EnRuMeaningMatch::create([
            'en_ru_entity_match_id' => $entityMatch->id,
            'order' => $order,
            'similarity' => 0.95,
            'alignment_chunk' => -1,
        ]);

        EnSentenceMeaningMatch::create([
            'en_entity_sentence_id' => $enSentences[$index]->id,
            'en_ru_meaning_match_id' => $meaningMatch->id,
        ]);
        RuSentenceMeaningMatch::create([
            'ru_entity_sentence_id' => $ruSentences[$index]->id,
            'en_ru_meaning_match_id' => $meaningMatch->id,
        ]);
    }

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    $inserted = EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)
        ->where('order', '>', 0)
        ->where('order', '<', 2048)
        ->first();

    expect($entityMatch->status)->toBe('completed')
        ->and(EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)->count())->toBe(3)
        ->and($inserted)->not->toBeNull()
        ->and((float) $inserted->similarity)->toBe(0.0)
        ->and($inserted->enSentenceMatches()->first()->en_entity_sentence_id)->toBe($enSentences[1]->id)
        ->and($inserted->ruSentenceMatches()->count())->toBe(0)
        ->and(EnEntitySentence::whereDoesntHave('enMeaningMatches')->where('en_entity_id', $enEntity->id)->count())->toBe(0);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

it('completes without failing when a human-unlinked original sentence is still junction-less', function () {
    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $enEntity = EnEntity::create(['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = RuEntity::create(['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    $enSentences = collect(range(1, 2))->map(fn (int $order): EnEntitySentence => EnEntitySentence::create([
        'en_entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => "English {$order}.",
        'order' => $order,
    ]));
    $ruSentences = collect(range(1, 2))->map(fn (int $order): RuEntitySentence => RuEntitySentence::create([
        'ru_entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => "Russian {$order}.",
        'order' => $order,
    ]));

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'aligning',
        'chunk_size' => 2,
        'max_n' => 2,
        'en_total_sentences' => 2,
        'ru_total_sentences' => 2,
        'last_en_sentence_offset' => 2,
        'last_ru_sentence_offset' => 2,
    ]);

    $landmark = EnRuMeaningMatch::create([
        'en_ru_entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 0.95,
        'alignment_chunk' => -1,
    ]);

    EnSentenceMeaningMatch::create([
        'en_entity_sentence_id' => $enSentences[0]->id,
        'en_ru_meaning_match_id' => $landmark->id,
    ]);
    RuSentenceMeaningMatch::create([
        'ru_entity_sentence_id' => $ruSentences[0]->id,
        'en_ru_meaning_match_id' => $landmark->id,
    ]);

    EnRuMeaningMatch::create([
        'en_ru_entity_match_id' => $entityMatch->id,
        'order' => 512,
        'similarity' => 0.0,
        'alignment_chunk' => -1,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('completed')
        ->and($entityMatch->error_message)->toBeNull()
        ->and(EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)->count())->toBe(3)
        ->and($enSentences[1]->refresh()->enMeaningMatches()->count())->toBe(1)
        ->and(EnEntitySentence::whereDoesntHave('enMeaningMatches')->where('en_entity_id', $enEntity->id)->count())->toBe(0);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

it('drains the original side as skip rows when the translation side has no sentences', function () {
    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $enEntity = EnEntity::create(['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = RuEntity::create(['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    foreach (range(1, 2) as $order) {
        EnEntitySentence::create([
            'en_entity_id' => $enEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "English {$order}.",
            'order' => $order,
        ]);
    }

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'pending',
        'chunk_size' => 2,
        'max_n' => 2,
    ]);

    AlignEntitySentences::beginFromScratch($entityMatch->id);

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('completed')
        ->and($entityMatch->error_message)->toBeNull()
        ->and($entityMatch->en_total_sentences)->toBe(2)
        ->and($entityMatch->ru_total_sentences)->toBe(0)
        ->and(EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)->count())->toBe(2)
        ->and(EnSentenceMeaningMatch::count())->toBe(2)
        ->and(EnEntitySentence::whereDoesntHave('enMeaningMatches')->where('en_entity_id', $enEntity->id)->count())->toBe(0);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

it('skips stale alignment jobs when the entity match has been deleted', function () {
    (new AlignEntitySentences(999))->handle();

    expect(EnRuMeaningMatch::count())->toBe(0)
        ->and(EnSentenceMeaningMatch::count())->toBe(0)
        ->and(RuSentenceMeaningMatch::count())->toBe(0);
});

it('persists one english sentence linked to five russian sentences in a single chunk', function () {
    Http::fake(fn (Request $request) => Http::response([
        'matches' => [
            ['en_start' => 0, 'en_end' => 1, 'ru_start' => 0, 'ru_end' => 5, 'score' => 0.95],
        ],
        'unmatched_en' => [],
        'unmatched_ru' => [],
    ]));

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $enEntity = EnEntity::create(['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = RuEntity::create(['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    $enSentence = EnEntitySentence::create([
        'en_entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'English meaning.',
        'order' => 1,
    ]);

    $ruSentences = collect(range(1, 5))->map(fn (int $order): RuEntitySentence => RuEntitySentence::create([
        'ru_entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => "Russian part {$order}.",
        'order' => $order,
    ]));

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'aligning',
        'chunk_size' => 75,
        'max_n' => 5,
        'en_total_sentences' => 1,
        'ru_total_sentences' => 5,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();
    $meaningMatch = EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)->first();
    $enJunctions = EnSentenceMeaningMatch::where('en_ru_meaning_match_id', $meaningMatch->id)->get();
    $ruJunctions = RuSentenceMeaningMatch::where('en_ru_meaning_match_id', $meaningMatch->id)
        ->get()
        ->sortBy(fn ($match) => $match->ruEntitySentence?->order ?? 0)
        ->values();

    expect($entityMatch->status)->toBe('completed')
        ->and($entityMatch->linked_count)->toBe(1)
        ->and($enJunctions)->toHaveCount(1)
        ->and($enJunctions->pluck('en_entity_sentence_id')->all())->toEqual([$enSentence->id])
        ->and($ruJunctions->pluck('ru_entity_sentence_id')->all())->toEqual($ruSentences->pluck('id')->all())
        ->and(EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)->count())->toBe(1);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

it('keeps the cursor untouched on failure so a re-run can resume', function () {
    $enEntity = EnEntity::create([
        'name' => 'English',
        'signature' => json_encode([1.0, 0.0]),
    ]);
    $ruEntity = RuEntity::create([
        'name' => 'Russian',
        'signature' => json_encode([1.0, 0.0]),
    ]);

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'aligning',
        'chunk_size' => 75,
        'max_n' => 1,
        'en_total_sentences' => 2,
        'ru_total_sentences' => 2,
        'last_en_sentence_offset' => 1,
        'last_ru_sentence_offset' => 1,
    ]);

    (new AlignEntitySentences($entityMatch->id))->failed(new RuntimeException('python exploded'));

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('failed')
        ->and($entityMatch->error_message)->toBe('python exploded')
        ->and($entityMatch->completed_at)->not->toBeNull()
        ->and($entityMatch->last_en_sentence_offset)->toBe(1)
        ->and($entityMatch->last_ru_sentence_offset)->toBe(1);
});

it('sends full sentence contents to the alignment endpoint without php-side sampling', function () {
    Http::fake(function (Request $request) {
        return Http::response([
            'matches' => [],
            'unmatched_en' => [0, 1],
            'unmatched_ru' => [0],
        ]);
    });

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $enEntity = EnEntity::create([
        'name' => 'English',
        'signature' => json_encode([1.0, 0.0]),
    ]);
    $ruEntity = RuEntity::create([
        'name' => 'Russian',
        'signature' => json_encode([1.0, 0.0]),
    ]);

    EnEntitySentence::create([
        'en_entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => str_repeat('a', 100),
        'order' => 1,
    ]);
    EnEntitySentence::create([
        'en_entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => str_repeat('b', 100),
        'order' => 2,
    ]);
    RuEntitySentence::create([
        'ru_entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => str_repeat('c', 100),
        'order' => 1,
    ]);

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'aligning',
        'chunk_size' => 75,
        'max_n' => 1,
        'en_total_sentences' => 2,
        'ru_total_sentences' => 1,
        'last_en_sentence_offset' => 0,
        'last_ru_sentence_offset' => 0,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    Http::assertSent(function (Request $request): bool {
        $enTexts = $request->data()['en_sentences'] ?? [];
        $ruTexts = $request->data()['ru_sentences'] ?? [];

        return str_ends_with($request->url(), '/align')
            && $enTexts === [str_repeat('a', 100), str_repeat('b', 100)]
            && $ruTexts === [str_repeat('c', 100)]
            && $request->data()['max_window'] === 1;
    });
});

it('uses a sequential slice where the RU window tracks the EN window without overlap', function () {
    $capturedOffsets = [];
    Http::fake(function (Request $request) use (&$capturedOffsets) {
        $capturedOffsets[] = [
            'en_count' => count($request->data()['en_sentences'] ?? []),
            'ru_count' => count($request->data()['ru_sentences'] ?? []),
        ];

        return Http::response([
            'matches' => [
                ['en_start' => 0, 'en_end' => 1, 'ru_start' => 0, 'ru_end' => 1, 'score' => 0.9],
                ['en_start' => 1, 'en_end' => 2, 'ru_start' => 1, 'ru_end' => 2, 'score' => 0.9],
                ['en_start' => 2, 'en_end' => 3, 'ru_start' => 2, 'ru_end' => 3, 'score' => 0.9],
            ],
            'unmatched_en' => [],
            'unmatched_ru' => [],
        ]);
    });

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $enEntity = EnEntity::create([
        'name' => 'English',
        'signature' => json_encode([1.0, 0.0]),
    ]);
    $ruEntity = RuEntity::create([
        'name' => 'Russian',
        'signature' => json_encode([1.0, 0.0]),
    ]);

    foreach (range(1, 5) as $order) {
        EnEntitySentence::create([
            'en_entity_id' => $enEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "EN {$order}.",
            'order' => $order,
        ]);
        RuEntitySentence::create([
            'ru_entity_id' => $ruEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "RU {$order}.",
            'order' => $order,
        ]);
    }

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'aligning',
        'chunk_size' => 3,
        'max_n' => 2,
        'en_total_sentences' => 5,
        'ru_total_sentences' => 5,
        'last_en_sentence_offset' => 0,
        'last_ru_sentence_offset' => 0,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    expect($capturedOffsets)->toBe([
        ['en_count' => 3, 'ru_count' => 3],
    ]);

    $entityMatch->refresh();

    expect($entityMatch->last_en_sentence_offset)->toBe(3)
        ->and($entityMatch->last_ru_sentence_offset)->toBe(3)
        ->and($entityMatch->status)->toBe('aligning');

    Bus::assertDispatched(AlignEntitySentences::class, 1);
});

it('trims low-score tail matches and advances the cursor to the last anchor', function () {
    Http::fake(fn (Request $request) => Http::response([
        'matches' => [
            ['en_start' => 0, 'en_end' => 1, 'ru_start' => 0, 'ru_end' => 1, 'score' => 0.9],
            ['en_start' => 1, 'en_end' => 2, 'ru_start' => 1, 'ru_end' => 2, 'score' => 0.3],
        ],
        'unmatched_en' => [],
        'unmatched_ru' => [],
    ]));

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $enEntity = EnEntity::create(['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = RuEntity::create(['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    foreach (range(1, 3) as $order) {
        EnEntitySentence::create([
            'en_entity_id' => $enEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "English {$order}.",
            'order' => $order,
        ]);
        RuEntitySentence::create([
            'ru_entity_id' => $ruEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "Russian {$order}.",
            'order' => $order,
        ]);
    }

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'aligning',
        'chunk_size' => 2,
        'max_n' => 1,
        'en_total_sentences' => 3,
        'ru_total_sentences' => 3,
        'last_en_sentence_offset' => 0,
        'last_ru_sentence_offset' => 0,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('aligning')
        ->and($entityMatch->last_en_sentence_offset)->toBe(1)
        ->and($entityMatch->last_ru_sentence_offset)->toBe(1)
        ->and(EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)->count())->toBe(1);

    Bus::assertDispatched(AlignEntitySentences::class, 1);
});

it('falls back to committing all matches when no match reaches the anchor threshold', function () {
    Http::fake(fn (Request $request) => Http::response([
        'matches' => [
            ['en_start' => 0, 'en_end' => 1, 'ru_start' => 0, 'ru_end' => 1, 'score' => 0.3],
            ['en_start' => 1, 'en_end' => 2, 'ru_start' => 1, 'ru_end' => 2, 'score' => 0.2],
        ],
        'unmatched_en' => [],
        'unmatched_ru' => [],
    ]));

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $enEntity = EnEntity::create(['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = RuEntity::create(['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    foreach (range(1, 3) as $order) {
        EnEntitySentence::create([
            'en_entity_id' => $enEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "English {$order}.",
            'order' => $order,
        ]);
        RuEntitySentence::create([
            'ru_entity_id' => $ruEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "Russian {$order}.",
            'order' => $order,
        ]);
    }

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'aligning',
        'chunk_size' => 2,
        'max_n' => 1,
        'en_total_sentences' => 3,
        'ru_total_sentences' => 3,
        'last_en_sentence_offset' => 0,
        'last_ru_sentence_offset' => 0,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('aligning')
        ->and($entityMatch->last_en_sentence_offset)->toBe(2)
        ->and($entityMatch->last_ru_sentence_offset)->toBe(2)
        ->and(EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)->count())->toBe(2);

    Bus::assertDispatched(AlignEntitySentences::class, 1);
});

it('commits every match on the final chunk regardless of score', function () {
    Http::fake(fn (Request $request) => Http::response([
        'matches' => [
            ['en_start' => 0, 'en_end' => 2, 'ru_start' => 0, 'ru_end' => 2, 'score' => 0.2],
        ],
        'unmatched_en' => [],
        'unmatched_ru' => [],
    ]));

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $enEntity = EnEntity::create(['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = RuEntity::create(['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    foreach (range(1, 2) as $order) {
        EnEntitySentence::create([
            'en_entity_id' => $enEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "English {$order}.",
            'order' => $order,
        ]);
        RuEntitySentence::create([
            'ru_entity_id' => $ruEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "Russian {$order}.",
            'order' => $order,
        ]);
    }

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'aligning',
        'chunk_size' => 2,
        'max_n' => 1,
        'en_total_sentences' => 2,
        'ru_total_sentences' => 2,
        'last_en_sentence_offset' => 0,
        'last_ru_sentence_offset' => 0,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('completed')
        ->and($entityMatch->completed_at)->not->toBeNull()
        ->and($entityMatch->last_en_sentence_offset)->toBe(2)
        ->and($entityMatch->last_ru_sentence_offset)->toBe(2)
        ->and(EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)->count())->toBe(1);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

it('assigns monotonic alignment chunk ids across trimmed chunks', function () {
    Http::fake(fn (Request $request) => Http::response([
        'matches' => [
            ['en_start' => 0, 'en_end' => 2, 'ru_start' => 0, 'ru_end' => 2, 'score' => 0.9],
        ],
        'unmatched_en' => [],
        'unmatched_ru' => [],
    ]));

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $enEntity = EnEntity::create(['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = RuEntity::create(['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    foreach (range(1, 4) as $order) {
        EnEntitySentence::create([
            'en_entity_id' => $enEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "English {$order}.",
            'order' => $order,
        ]);
        RuEntitySentence::create([
            'ru_entity_id' => $ruEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "Russian {$order}.",
            'order' => $order,
        ]);
    }

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'aligning',
        'chunk_size' => 2,
        'max_n' => 1,
        'en_total_sentences' => 4,
        'ru_total_sentences' => 4,
        'last_en_sentence_offset' => 2,
        'last_ru_sentence_offset' => 2,
    ]);

    EnRuMeaningMatch::create([
        'en_ru_entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 0.9,
        'alignment_chunk' => 0,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    expect(EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)
        ->where('alignment_chunk', 0)
        ->count())->toBe(1)
        ->and(EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)
            ->where('alignment_chunk', 1)
            ->count())->toBe(1)
        ->and($entityMatch->status)->toBe('completed');

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

it('rolls back the last two meaning matches and re-aligns them with backward context', function () {
    Http::fake(fn (Request $request) => Http::response([
        'matches' => [
            ['en_start' => 0, 'en_end' => 1, 'ru_start' => 0, 'ru_end' => 1, 'score' => 0.9],
            ['en_start' => 1, 'en_end' => 2, 'ru_start' => 1, 'ru_end' => 2, 'score' => 0.9],
            ['en_start' => 2, 'en_end' => 3, 'ru_start' => 2, 'ru_end' => 3, 'score' => 0.9],
            ['en_start' => 3, 'en_end' => 4, 'ru_start' => 3, 'ru_end' => 4, 'score' => 0.9],
            ['en_start' => 4, 'en_end' => 5, 'ru_start' => 4, 'ru_end' => 5, 'score' => 0.9],
        ],
        'unmatched_en' => [],
        'unmatched_ru' => [],
    ]));

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $enEntity = EnEntity::create(['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = RuEntity::create(['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    $enSentences = collect(range(1, 6))->map(fn (int $order): EnEntitySentence => EnEntitySentence::create([
        'en_entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => "English {$order}.",
        'order' => $order,
    ]));
    $ruSentences = collect(range(1, 6))->map(fn (int $order): RuEntitySentence => RuEntitySentence::create([
        'ru_entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => "Russian {$order}.",
        'order' => $order,
    ]));

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'aligning',
        'chunk_size' => 3,
        'max_n' => 1,
        'en_total_sentences' => 6,
        'ru_total_sentences' => 6,
        'last_en_sentence_offset' => 3,
        'last_ru_sentence_offset' => 3,
    ]);

    foreach ([0, 1, 2] as $index) {
        $match = EnRuMeaningMatch::create([
            'en_ru_entity_match_id' => $entityMatch->id,
            'order' => $index,
            'similarity' => 0.8,
            'alignment_chunk' => 0,
        ]);
        EnSentenceMeaningMatch::create([
            'en_entity_sentence_id' => $enSentences[$index]->id,
            'en_ru_meaning_match_id' => $match->id,
        ]);
        RuSentenceMeaningMatch::create([
            'ru_entity_sentence_id' => $ruSentences[$index]->id,
            'en_ru_meaning_match_id' => $match->id,
        ]);
    }

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('completed')
        ->and($entityMatch->last_en_sentence_offset)->toBe(6)
        ->and($entityMatch->last_ru_sentence_offset)->toBe(6)
        ->and(EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)
            ->where('alignment_chunk', 0)
            ->count())->toBe(1)
        ->and(EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)
            ->where('alignment_chunk', 1)
            ->count())->toBe(5);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

it('skips rollback on the first chunk when no prior matches exist', function () {
    $capturedOffsets = [];
    Http::fake(function (Request $request) use (&$capturedOffsets) {
        $capturedOffsets[] = [
            'en_count' => count($request->data()['en_sentences'] ?? []),
            'ru_count' => count($request->data()['ru_sentences'] ?? []),
        ];

        return Http::response([
            'matches' => [
                ['en_start' => 0, 'en_end' => 2, 'ru_start' => 0, 'ru_end' => 2, 'score' => 0.9],
            ],
            'unmatched_en' => [],
            'unmatched_ru' => [],
        ]);
    });

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $enEntity = EnEntity::create(['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = RuEntity::create(['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    foreach (range(1, 4) as $order) {
        EnEntitySentence::create([
            'en_entity_id' => $enEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "English {$order}.",
            'order' => $order,
        ]);
        RuEntitySentence::create([
            'ru_entity_id' => $ruEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "Russian {$order}.",
            'order' => $order,
        ]);
    }

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'aligning',
        'chunk_size' => 3,
        'max_n' => 1,
        'en_total_sentences' => 4,
        'ru_total_sentences' => 4,
        'last_en_sentence_offset' => 0,
        'last_ru_sentence_offset' => 0,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    expect($capturedOffsets)->toBe([
        ['en_count' => 3, 'ru_count' => 3],
    ]);

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('aligning')
        ->and($entityMatch->last_en_sentence_offset)->toBe(2)
        ->and($entityMatch->last_ru_sentence_offset)->toBe(2)
        ->and(EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)->count())->toBe(1);

    Bus::assertDispatched(AlignEntitySentences::class, 1);
});

it('rolls back a single prior match when the previous chunk committed just one', function () {
    Http::fake(fn (Request $request) => Http::response([
        'matches' => [
            ['en_start' => 0, 'en_end' => 1, 'ru_start' => 0, 'ru_end' => 1, 'score' => 0.9],
            ['en_start' => 1, 'en_end' => 2, 'ru_start' => 1, 'ru_end' => 2, 'score' => 0.9],
            ['en_start' => 2, 'en_end' => 3, 'ru_start' => 2, 'ru_end' => 3, 'score' => 0.9],
            ['en_start' => 3, 'en_end' => 4, 'ru_start' => 3, 'ru_end' => 4, 'score' => 0.9],
        ],
        'unmatched_en' => [],
        'unmatched_ru' => [],
    ]));

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $enEntity = EnEntity::create(['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = RuEntity::create(['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    $enSentences = collect(range(1, 4))->map(fn (int $order): EnEntitySentence => EnEntitySentence::create([
        'en_entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => "English {$order}.",
        'order' => $order,
    ]));
    $ruSentences = collect(range(1, 4))->map(fn (int $order): RuEntitySentence => RuEntitySentence::create([
        'ru_entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => "Russian {$order}.",
        'order' => $order,
    ]));

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'aligning',
        'chunk_size' => 2,
        'max_n' => 1,
        'en_total_sentences' => 4,
        'ru_total_sentences' => 4,
        'last_en_sentence_offset' => 2,
        'last_ru_sentence_offset' => 2,
    ]);

    $seed = EnRuMeaningMatch::create([
        'en_ru_entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 0.8,
        'alignment_chunk' => 0,
    ]);
    EnSentenceMeaningMatch::create([
        'en_entity_sentence_id' => $enSentences[0]->id,
        'en_ru_meaning_match_id' => $seed->id,
    ]);
    RuSentenceMeaningMatch::create([
        'ru_entity_sentence_id' => $ruSentences[0]->id,
        'en_ru_meaning_match_id' => $seed->id,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('completed')
        ->and($entityMatch->last_en_sentence_offset)->toBe(4)
        ->and($entityMatch->last_ru_sentence_offset)->toBe(4)
        ->and(EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)->count())->toBe(4);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

it('does not roll back human-edit sentinel matches', function () {
    $capturedOffsets = [];
    Http::fake(function (Request $request) use (&$capturedOffsets) {
        $capturedOffsets[] = [
            'en_count' => count($request->data()['en_sentences'] ?? []),
            'ru_count' => count($request->data()['ru_sentences'] ?? []),
        ];

        return Http::response([
            'matches' => [
                ['en_start' => 0, 'en_end' => 1, 'ru_start' => 0, 'ru_end' => 1, 'score' => 0.9],
                ['en_start' => 1, 'en_end' => 2, 'ru_start' => 1, 'ru_end' => 2, 'score' => 0.9],
            ],
            'unmatched_en' => [],
            'unmatched_ru' => [],
        ]);
    });

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $enEntity = EnEntity::create(['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = RuEntity::create(['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    $enSentences = collect(range(1, 4))->map(fn (int $order): EnEntitySentence => EnEntitySentence::create([
        'en_entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => "English {$order}.",
        'order' => $order,
    ]));
    $ruSentences = collect(range(1, 4))->map(fn (int $order): RuEntitySentence => RuEntitySentence::create([
        'ru_entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => "Russian {$order}.",
        'order' => $order,
    ]));

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'aligning',
        'chunk_size' => 2,
        'max_n' => 1,
        'en_total_sentences' => 4,
        'ru_total_sentences' => 4,
        'last_en_sentence_offset' => 2,
        'last_ru_sentence_offset' => 2,
    ]);

    $humanEdit = EnRuMeaningMatch::create([
        'en_ru_entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 0.9,
        'alignment_chunk' => -1,
    ]);
    EnSentenceMeaningMatch::create([
        'en_entity_sentence_id' => $enSentences[0]->id,
        'en_ru_meaning_match_id' => $humanEdit->id,
    ]);
    RuSentenceMeaningMatch::create([
        'ru_entity_sentence_id' => $ruSentences[0]->id,
        'en_ru_meaning_match_id' => $humanEdit->id,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    expect($capturedOffsets)->toBe([
        ['en_count' => 2, 'ru_count' => 2],
    ]);

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('completed')
        ->and($entityMatch->last_en_sentence_offset)->toBe(4)
        ->and($entityMatch->last_ru_sentence_offset)->toBe(4)
        ->and(EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)
            ->where('alignment_chunk', -1)
            ->count())->toBe(1)
        ->and(EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)
            ->where('alignment_chunk', 0)
            ->count())->toBe(2);

    Bus::assertNotDispatched(AlignEntitySentences::class);
});

it('force-advances the cursor when a rolled-back commit cannot reach the stored offset', function () {
    Http::fake(fn (Request $request) => Http::response([
        'matches' => [
            ['en_start' => 0, 'en_end' => 1, 'ru_start' => 0, 'ru_end' => 1, 'score' => 0.9],
        ],
        'unmatched_en' => [],
        'unmatched_ru' => [],
    ]));

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $enEntity = EnEntity::create(['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = RuEntity::create(['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    $enSentences = collect(range(1, 4))->map(fn (int $order): EnEntitySentence => EnEntitySentence::create([
        'en_entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => "English {$order}.",
        'order' => $order,
    ]));
    $ruSentences = collect(range(1, 4))->map(fn (int $order): RuEntitySentence => RuEntitySentence::create([
        'ru_entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => "Russian {$order}.",
        'order' => $order,
    ]));

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'aligning',
        'chunk_size' => 2,
        'max_n' => 1,
        'en_total_sentences' => 4,
        'ru_total_sentences' => 4,
        'last_en_sentence_offset' => 2,
        'last_ru_sentence_offset' => 2,
    ]);

    foreach ([0, 1] as $index) {
        $seed = EnRuMeaningMatch::create([
            'en_ru_entity_match_id' => $entityMatch->id,
            'order' => $index,
            'similarity' => 0.8,
            'alignment_chunk' => 0,
        ]);
        EnSentenceMeaningMatch::create([
            'en_entity_sentence_id' => $enSentences[$index]->id,
            'en_ru_meaning_match_id' => $seed->id,
        ]);
        RuSentenceMeaningMatch::create([
            'ru_entity_sentence_id' => $ruSentences[$index]->id,
            'en_ru_meaning_match_id' => $seed->id,
        ]);
    }

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('aligning')
        ->and($entityMatch->last_en_sentence_offset)->toBe(3)
        ->and($entityMatch->last_ru_sentence_offset)->toBe(2)
        ->and(EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)->count())->toBe(7);

    Bus::assertDispatched(AlignEntitySentences::class, 1);
});

it('passes landmarks and high confidence to the alignment endpoint', function () {
    Http::fake(fn (Request $request) => Http::response([
        'matches' => [],
        'unmatched_en' => [],
        'unmatched_ru' => [],
    ]));

    $enSentence = new EnEntitySentence(['content' => 'English.', 'order' => 1]);
    $enSentence->id = 1;
    $ruSentence = new RuEntitySentence(['content' => 'Russian.', 'order' => 1]);
    $ruSentence->id = 1;

    $service = new SentenceAlignmentService('http://ext_python:8000', 30, 300);

    $service->alignChunkRemote(
        collect([$enSentence]),
        collect([$ruSentence]),
        5,
        landmarks: [
            ['en_start' => 0, 'en_end' => 1, 'ru_start' => 0, 'ru_end' => 1],
        ],
        highConfidence: 0.9,
    );

    Http::assertSent(function (Request $request): bool {
        return str_ends_with($request->url(), '/align')
            && $request->data()['landmarks'] === [
                ['en_start' => 0, 'en_end' => 1, 'ru_start' => 0, 'ru_end' => 1],
            ]
            && $request->data()['high_confidence'] === 0.9;
    });
});

it('omits landmark and high confidence keys from the payload when not given', function () {
    Http::fake(fn (Request $request) => Http::response([
        'matches' => [],
        'unmatched_en' => [],
        'unmatched_ru' => [],
    ]));

    $enSentence = new EnEntitySentence(['content' => 'English.', 'order' => 1]);
    $enSentence->id = 1;
    $ruSentence = new RuEntitySentence(['content' => 'Russian.', 'order' => 1]);
    $ruSentence->id = 1;

    $service = new SentenceAlignmentService('http://ext_python:8000', 30, 300);

    $service->alignChunkRemote(
        collect([$enSentence]),
        collect([$ruSentence]),
        5,
    );

    Http::assertSent(function (Request $request): bool {
        return str_ends_with($request->url(), '/align')
            && ! array_key_exists('landmarks', $request->data())
            && ! array_key_exists('high_confidence', $request->data());
    });
});
