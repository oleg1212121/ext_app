<?php

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

function seedMeaningMatchJunction(EnRuMeaningMatch $match, EnEntitySentence $en, RuEntitySentence $ru, int $ruOrder = 0): void
{
    EnSentenceMeaningMatch::create([
        'en_entity_sentence_id' => $en->id,
        'en_ru_meaning_match_id' => $match->id,
        'order' => 0,
    ]);
    RuSentenceMeaningMatch::create([
        'ru_entity_sentence_id' => $ru->id,
        'en_ru_meaning_match_id' => $match->id,
        'order' => $ruOrder,
    ]);
}

it('preserves human rows and auto-landmarks, deletes low-confidence rows, and re-aligns the gaps', function () {
    $calls = [];
    Http::fake(function (Request $request) use (&$calls) {
        $en = $request->data()['en_sentences'] ?? [];
        $ru = $request->data()['ru_sentences'] ?? [];
        $calls[] = ['en' => $en, 'ru' => $ru];

        $count = min(count($en), count($ru));
        $matches = [];

        for ($i = 0; $i < $count; $i++) {
            $matches[] = ['en_start' => $i, 'en_end' => $i + 1, 'ru_start' => $i, 'ru_end' => $i + 1, 'score' => 0.9];
        }

        return Http::response(['matches' => $matches, 'unmatched_en' => [], 'unmatched_ru' => []]);
    });

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
        'status' => 'completed',
        'chunk_size' => 75,
        'max_n' => 2,
        'en_total_sentences' => 6,
        'ru_total_sentences' => 6,
        'linked_count' => 4,
        'last_en_sentence_offset' => 6,
        'last_ru_sentence_offset' => 6,
    ]);

    $humanRow = EnRuMeaningMatch::create([
        'en_ru_entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 1.0,
        'alignment_chunk' => -1,
    ]);
    seedMeaningMatchJunction($humanRow, $enSentences[1], $ruSentences[1]);

    $autoLandmark = EnRuMeaningMatch::create([
        'en_ru_entity_match_id' => $entityMatch->id,
        'order' => 1024,
        'similarity' => 0.95,
        'alignment_chunk' => 0,
    ]);
    seedMeaningMatchJunction($autoLandmark, $enSentences[3], $ruSentences[3]);

    $lowConfidenceA = EnRuMeaningMatch::create([
        'en_ru_entity_match_id' => $entityMatch->id,
        'order' => 2048,
        'similarity' => 0.5,
        'alignment_chunk' => 0,
    ]);
    seedMeaningMatchJunction($lowConfidenceA, $enSentences[2], $ruSentences[2]);

    $lowConfidenceB = EnRuMeaningMatch::create([
        'en_ru_entity_match_id' => $entityMatch->id,
        'order' => 3072,
        'similarity' => 0.4,
        'alignment_chunk' => 0,
    ]);
    seedMeaningMatchJunction($lowConfidenceB, $enSentences[4], $ruSentences[4]);

    AlignEntitySentences::begin($entityMatch->id);

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('aligning')
        ->and($entityMatch->last_en_sentence_offset)->toBe(0)
        ->and($entityMatch->last_ru_sentence_offset)->toBe(0)
        ->and($entityMatch->linked_count)->toBe(2)
        ->and($entityMatch->started_at)->not->toBeNull()
        ->and($entityMatch->completed_at)->toBeNull();

    expect(EnRuMeaningMatch::find($humanRow->id))->not->toBeNull()
        ->and(EnRuMeaningMatch::find($autoLandmark->id))->not->toBeNull()
        ->and(EnRuMeaningMatch::find($lowConfidenceA->id))->toBeNull()
        ->and(EnRuMeaningMatch::find($lowConfidenceB->id))->toBeNull();

    Bus::assertDispatched(AlignEntitySentences::class, 1);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('completed')
        ->and($entityMatch->last_en_sentence_offset)->toBe(6)
        ->and($entityMatch->last_ru_sentence_offset)->toBe(6)
        ->and($entityMatch->completed_at)->not->toBeNull()
        ->and(EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)->count())->toBe(6);

    $humanRow->refresh();
    $autoLandmark->refresh();

    expect($humanRow->alignment_chunk)->toBe(-1)
        ->and($humanRow->similarity)->toBe('1.0000')
        ->and($humanRow->enSentenceMatches()->pluck('en_entity_sentence_id')->all())->toEqual([$enSentences[1]->id])
        ->and($humanRow->ruSentenceMatches()->pluck('ru_entity_sentence_id')->all())->toEqual([$ruSentences[1]->id])
        ->and($autoLandmark->alignment_chunk)->toBe(0)
        ->and($autoLandmark->similarity)->toBe('0.9500')
        ->and($autoLandmark->enSentenceMatches()->pluck('en_entity_sentence_id')->all())->toEqual([$enSentences[3]->id])
        ->and($autoLandmark->ruSentenceMatches()->pluck('ru_entity_sentence_id')->all())->toEqual([$ruSentences[3]->id]);

    expect(EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)
        ->where('alignment_chunk', '>', 0)
        ->orderBy('alignment_chunk')
        ->pluck('alignment_chunk')
        ->unique()
        ->all())->toEqual([1, 2, 3]);

    expect(array_column($calls, 'en'))->toBe([
        ['English 1.'],
        ['English 3.'],
        ['English 5.', 'English 6.'],
    ]);

    foreach ($calls as $call) {
        expect($call['en'])->not->toContain('English 2.')
            ->and($call['en'])->not->toContain('English 4.')
            ->and($call['ru'])->not->toContain('Russian 2.')
            ->and($call['ru'])->not->toContain('Russian 4.');
    }

    Bus::assertDispatched(AlignEntitySentences::class, 1);
});

it('wipes every row including human-edited ones when starting from scratch', function () {
    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $enEntity = EnEntity::create(['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = RuEntity::create(['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    $enSentence = EnEntitySentence::create([
        'en_entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'English.',
        'order' => 1,
    ]);
    $ruSentence = RuEntitySentence::create([
        'ru_entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'Russian.',
        'order' => 1,
    ]);

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'pending',
        'chunk_size' => 200,
    ]);

    $humanRow = EnRuMeaningMatch::create([
        'en_ru_entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 1.0,
        'alignment_chunk' => -1,
    ]);
    seedMeaningMatchJunction($humanRow, $enSentence, $ruSentence);

    $machineRow = EnRuMeaningMatch::create([
        'en_ru_entity_match_id' => $entityMatch->id,
        'order' => 1024,
        'similarity' => 0.5,
        'alignment_chunk' => 0,
    ]);
    seedMeaningMatchJunction($machineRow, $enSentence, $ruSentence);

    AlignEntitySentences::beginFromScratch($entityMatch->id);

    $entityMatch->refresh();

    expect(EnRuMeaningMatch::where('en_ru_entity_match_id', $entityMatch->id)->count())->toBe(0)
        ->and($entityMatch->status)->toBe('aligning')
        ->and($entityMatch->en_total_sentences)->toBe(1)
        ->and($entityMatch->ru_total_sentences)->toBe(1)
        ->and($entityMatch->chunk_size)->toBe(1)
        ->and($entityMatch->last_en_sentence_offset)->toBe(0)
        ->and($entityMatch->last_ru_sentence_offset)->toBe(0)
        ->and($entityMatch->linked_count)->toBe(0)
        ->and($entityMatch->entity_similarity)->toBe('1.0000');

    Bus::assertDispatched(AlignEntitySentences::class, 1);
});

it('carves pools that never overlap a 1:N human landmark span', function () {
    $calls = [];
    Http::fake(function (Request $request) use (&$calls) {
        $en = $request->data()['en_sentences'] ?? [];
        $ru = $request->data()['ru_sentences'] ?? [];
        $calls[] = ['en' => $en, 'ru' => $ru];

        $count = min(count($en), count($ru));
        $matches = [];

        for ($i = 0; $i < $count; $i++) {
            $matches[] = ['en_start' => $i, 'en_end' => $i + 1, 'ru_start' => $i, 'ru_end' => $i + 1, 'score' => 0.9];
        }

        return Http::response(['matches' => $matches, 'unmatched_en' => [], 'unmatched_ru' => []]);
    });

    Bus::fake();

    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $enEntity = EnEntity::create(['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = RuEntity::create(['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    $enSentences = collect(range(1, 9))->map(fn (int $order): EnEntitySentence => EnEntitySentence::create([
        'en_entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => "English {$order}.",
        'order' => $order,
    ]));
    $ruSentences = collect(range(1, 9))->map(fn (int $order): RuEntitySentence => RuEntitySentence::create([
        'ru_entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => "Russian {$order}.",
        'order' => $order,
    ]));

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'completed',
        'chunk_size' => 75,
        'max_n' => 2,
        'en_total_sentences' => 9,
        'ru_total_sentences' => 9,
        'linked_count' => 1,
        'last_en_sentence_offset' => 9,
        'last_ru_sentence_offset' => 9,
    ]);

    $humanRow = EnRuMeaningMatch::create([
        'en_ru_entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 1.0,
        'alignment_chunk' => -1,
    ]);
    EnSentenceMeaningMatch::create([
        'en_entity_sentence_id' => $enSentences[4]->id,
        'en_ru_meaning_match_id' => $humanRow->id,
        'order' => 0,
    ]);
    foreach (range(0, 2) as $order) {
        RuSentenceMeaningMatch::create([
            'ru_entity_sentence_id' => $ruSentences[4 + $order]->id,
            'en_ru_meaning_match_id' => $humanRow->id,
            'order' => $order,
        ]);
    }

    AlignEntitySentences::begin($entityMatch->id);

    (new AlignEntitySentences($entityMatch->id))->handle();

    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('completed')
        ->and($entityMatch->last_en_sentence_offset)->toBe(9)
        ->and($entityMatch->last_ru_sentence_offset)->toBe(9);

    expect($calls)->toHaveCount(2)
        ->and(array_column($calls, 'en'))->toBe([
            ['English 1.', 'English 2.', 'English 3.', 'English 4.'],
            ['English 6.', 'English 7.', 'English 8.', 'English 9.'],
        ])
        ->and(array_column($calls, 'ru'))->toBe([
            ['Russian 1.', 'Russian 2.', 'Russian 3.', 'Russian 4.'],
            ['Russian 8.', 'Russian 9.'],
        ]);

    foreach ($calls as $call) {
        expect($call['en'])->not->toContain('English 5.')
            ->and($call['ru'])->not->toContain('Russian 5.')
            ->and($call['ru'])->not->toContain('Russian 6.')
            ->and($call['ru'])->not->toContain('Russian 7.');
    }

    $humanRow->refresh();

    expect($humanRow->enSentenceMatches()->pluck('en_entity_sentence_id')->all())->toEqual([$enSentences[4]->id])
        ->and($humanRow->ruSentenceMatches()->pluck('ru_entity_sentence_id')->all())
        ->toEqual($ruSentences->slice(4, 3)->pluck('id')->all());
});
