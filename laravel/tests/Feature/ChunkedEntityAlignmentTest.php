<?php

use App\Jobs\AlignEntitySentenceChunk;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('dispatches alignment chunks instead of embedding all sentences in the coordinator', function () {
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

    RuEntitySentence::create([
        'ru_entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'Russian sentence 1.',
        'order' => 1,
    ]);

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'pending',
        'chunk_size' => 200,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    Bus::assertChained([
        AlignEntitySentenceChunk::class,
        AlignEntitySentenceChunk::class,
    ]);

    expect($entityMatch->refresh()->status)->toBe('aligning')
        ->and($entityMatch->chunk_size)->toBe(75)
        ->and($entityMatch->en_total_sentences)->toBe(80)
        ->and($entityMatch->ru_total_sentences)->toBe(1);
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
    ]);

    (new AlignEntitySentenceChunk($entityMatch->id, 0, 0, 0, 75, true))->handle();

    $meaningMatch = EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)->first();
    $enJunction = EnSentenceMeaningMatch::where('en_ru_meaning_match_id', $meaningMatch->id)->first();
    $ruJunction = RuSentenceMeaningMatch::where('en_ru_meaning_match_id', $meaningMatch->id)->first();

    expect($entityMatch->refresh()->status)->toBe('completed')
        ->and($entityMatch->linked_count)->toBe(1)
        ->and(EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)->count())->toBe(1)
        ->and($meaningMatch->alignment_chunk)->toBe(0)
        ->and($meaningMatch->order)->toBe(0)
        ->and($enJunction->en_entity_sentence_id)->toBe($enSentence->id)
        ->and($ruJunction->ru_entity_sentence_id)->toBe($ruSentence->id);
});

it('skips stale alignment chunk jobs when the entity match has been deleted', function () {
    (new AlignEntitySentenceChunk(999, 0, 0, 0, 75, true))->handle();

    expect(EnRuMeaningMatch::count())->toBe(0)
        ->and(EnSentenceMeaningMatch::count())->toBe(0)
        ->and(RuSentenceMeaningMatch::count())->toBe(0);
});

it('persists one english sentence linked to five russian sentences', function () {
    Http::fake(function (Request $request) {
        return Http::response([
            'matches' => [
                ['en_start' => 0, 'en_end' => 1, 'ru_start' => 0, 'ru_end' => 5, 'score' => 0.95],
            ],
            'unmatched_en' => [],
            'unmatched_ru' => [],
        ]);
    });

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
    ]);

    (new AlignEntitySentenceChunk($entityMatch->id, 0, 0, 0, 75, true))->handle();

    $meaningMatch = EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)->first();
    $enJunctions = EnSentenceMeaningMatch::where('en_ru_meaning_match_id', $meaningMatch->id)->get();
    $ruJunctions = RuSentenceMeaningMatch::where('en_ru_meaning_match_id', $meaningMatch->id)->orderBy('order')->get();

    expect($entityMatch->refresh()->status)->toBe('completed')
        ->and($entityMatch->linked_count)->toBe(5)
        ->and($enJunctions)->toHaveCount(1)
        ->and($enJunctions->pluck('en_entity_sentence_id')->all())->toEqual([$enSentence->id])
        ->and($ruJunctions->pluck('ru_entity_sentence_id')->all())->toEqual($ruSentences->pluck('id')->all())
        ->and(EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)->count())->toBe(1);
});

it('dispatches drift-aware russian search windows for english chunks', function () {
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

    foreach (range(1, 150) as $order) {
        EnEntitySentence::create([
            'en_entity_id' => $enEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "English sentence {$order}.",
            'order' => $order,
        ]);
    }

    foreach (range(1, 210) as $order) {
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
        'chunk_size' => 75,
        'max_n' => 6,
    ]);

    (new AlignEntitySentences($entityMatch->id))->handle();

    Bus::assertChained([
        new AlignEntitySentenceChunk($entityMatch->id, 0, 0, 0, 75, false, 130),
        new AlignEntitySentenceChunk($entityMatch->id, 1, 75, 80, 75, true, 130),
    ]);
});

it('sends full sentence contents to the alignment endpoint without php-side sampling', function () {
    Http::fake(function (Request $request) {
        return Http::response([
            'matches' => [],
            'unmatched_en' => [0, 1],
            'unmatched_ru' => [0],
        ]);
    });

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
    ]);

    (new AlignEntitySentenceChunk($entityMatch->id, 0, 0, 0, 75, true))->handle();

    Http::assertSent(function (Request $request): bool {
        $enTexts = $request->data()['en_sentences'] ?? [];
        $ruTexts = $request->data()['ru_sentences'] ?? [];

        return str_ends_with($request->url(), '/align')
            && $enTexts === [str_repeat('a', 100), str_repeat('b', 100)]
            && $ruTexts === [str_repeat('c', 100)]
            && $request->data()['max_window'] === 1;
    });
});

it('configures alignment jobs to retry with backoff', function () {
    $coordinator = new AlignEntitySentences(1);
    $chunk = new AlignEntitySentenceChunk(1, 0, 0, 0, 75);

    expect($coordinator->timeout)->toBe(180)
        ->and($coordinator->tries)->toBe(5)
        ->and($coordinator->backoff())->toEqual([30, 60, 120, 300])
        ->and($chunk->timeout)->toBe(600)
        ->and($chunk->tries)->toBe(5)
        ->and($chunk->backoff())->toEqual([30, 60, 120, 300]);
});
