<?php

use App\Classes\SentenceSplitter;
use App\Jobs\SplitEntityFileSentences;
use App\Models\EnEntity;
use App\Models\RuEntity;
use App\Models\SentenceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    SentenceType::query()->insert([
        ['name' => 'sentence', 'description' => 'A regular sentence'],
        ['name' => 'title', 'description' => 'A title'],
        ['name' => 'quote', 'description' => 'A quoted passage'],
    ]);
});

it('streams file sentences with the same output as in-memory splitting', function () {
    config(['services.embedding.sentence_split_chunk_bytes' => 17]);

    $text = 'Dr. Smith arrived. "Hello there!" THIS IS A TITLE. Final sentence?';
    $filePath = 'entities/'.uniqid('stream_', true).'.txt';
    Storage::disk('local')->put($filePath, $text);

    $streamedEntity = EnEntity::query()->create(['name' => 'Streamed', 'file_path' => $filePath]);
    $memoryEntity = EnEntity::query()->create(['name' => 'Memory', 'file_path' => $filePath]);

    $splitter = new SentenceSplitter;
    $streamedStats = $splitter->process($streamedEntity->id, $filePath, 'en');
    $memoryStats = $splitter->process($memoryEntity->id, $filePath, 'en', $text);

    $streamed = $streamedEntity->sentences()->orderBy('order')->pluck('content')->all();
    $inMemory = $memoryEntity->sentences()->orderBy('order')->pluck('content')->all();

    expect($streamed)->toEqual($inMemory)
        ->and($streamedStats['sentences'])->toBe($memoryStats['sentences'])
        ->and($streamedStats['bytes_read'])->toBe(strlen($text));
});

it('keeps a sentence crossing chunk boundaries intact', function () {
    config(['services.embedding.sentence_split_chunk_bytes' => 10]);

    $text = 'This sentence crosses several chunks before ending. Short one.';
    $filePath = 'entities/'.uniqid('boundary_', true).'.txt';
    Storage::disk('local')->put($filePath, $text);

    $entity = EnEntity::query()->create(['name' => 'Boundary', 'file_path' => $filePath]);

    $stats = (new SentenceSplitter)->process($entity->id, $filePath, 'en');

    expect($entity->sentences()->orderBy('order')->pluck('content')->all())
        ->toEqual([
            'This sentence crosses several chunks before ending.',
            'Short one.',
        ])
        ->and($stats['sentences'])->toBe(2)
        ->and($stats['max_buffer_bytes'])->toBeGreaterThan(10);
});

it('does not split abbreviations near chunk boundaries', function () {
    config(['services.embedding.sentence_split_chunk_bytes' => 4]);

    $text = 'Dr. Smith arrived. He stayed.';
    $filePath = 'entities/'.uniqid('abbr_', true).'.txt';
    Storage::disk('local')->put($filePath, $text);

    $entity = EnEntity::query()->create(['name' => 'Abbreviation', 'file_path' => $filePath]);

    (new SentenceSplitter)->process($entity->id, $filePath, 'en');

    expect($entity->sentences()->orderBy('order')->pluck('content')->all())
        ->toEqual([
            'Dr. Smith arrived.',
            'He stayed.',
        ]);
});

it('splits after sentence punctuation inside closing quotes and apostrophes', function () {
    $text = '\'Hello.\' Then he left. “Wait!” She shouted. «Привет!» Потом ушел.';
    $filePath = 'entities/'.uniqid('quotes_', true).'.txt';
    Storage::disk('local')->put($filePath, $text);

    $entity = EnEntity::query()->create(['name' => 'Quotes', 'file_path' => $filePath]);

    (new SentenceSplitter)->process($entity->id, $filePath, 'en', $text);

    expect($entity->sentences()->orderBy('order')->pluck('content')->all())
        ->toEqual([
            '\'Hello.\'',
            'Then he left.',
            '“Wait!”',
            'She shouted.',
            '«Привет!»',
            'Потом ушел.',
        ]);
});

it('recognizes short punctuation-free lines as titles', function () {
    $text = "CHAPTER ONE\nThe Beginning\nThis is the first sentence. Another follows.";
    $filePath = 'entities/'.uniqid('titles_', true).'.txt';
    Storage::disk('local')->put($filePath, $text);

    $entity = EnEntity::query()->create(['name' => 'Titles', 'file_path' => $filePath]);

    (new SentenceSplitter)->process($entity->id, $filePath, 'en', $text);

    $sentences = $entity->sentences()
        ->with('sentenceType')
        ->orderBy('order')
        ->get()
        ->map(fn ($sentence): array => [$sentence->content, $sentence->sentenceType->name])
        ->all();

    expect($sentences)->toEqual([
        ['CHAPTER ONE', 'title'],
        ['The Beginning', 'title'],
        ['This is the first sentence.', 'sentence'],
        ['Another follows.', 'sentence'],
    ]);
});

it('keeps quoted sentence boundaries intact across chunks', function () {
    config(['services.embedding.sentence_split_chunk_bytes' => 7]);

    $text = '\'Hello.\' Then he left.';
    $filePath = 'entities/'.uniqid('quote_boundary_', true).'.txt';
    Storage::disk('local')->put($filePath, $text);

    $entity = EnEntity::query()->create(['name' => 'Quote Boundary', 'file_path' => $filePath]);

    (new SentenceSplitter)->process($entity->id, $filePath, 'en');

    expect($entity->sentences()->orderBy('order')->pluck('content')->all())
        ->toEqual([
            '\'Hello.\'',
            'Then he left.',
        ]);
});

it('recognizes Russian title lines before quoted sentences', function () {
    $text = "Глава 1\n«Привет!» Потом он ушел.";
    $filePath = 'entities/'.uniqid('ru_titles_', true).'.txt';
    Storage::disk('local')->put($filePath, $text);

    $entity = RuEntity::query()->create(['name' => 'Russian Titles', 'file_path' => $filePath]);

    (new SentenceSplitter)->process($entity->id, $filePath, 'ru', $text);

    $sentences = $entity->sentences()
        ->with('sentenceType')
        ->orderBy('order')
        ->get()
        ->map(fn ($sentence): array => [$sentence->content, $sentence->sentenceType->name])
        ->all();

    expect($sentences)->toEqual([
        ['Глава 1', 'title'],
        ['«Привет!»', 'quote'],
        ['Потом он ушел.', 'sentence'],
    ]);
});

it('inserts large streamed files in order while keeping the split job payload small', function () {
    config(['services.embedding.sentence_split_chunk_bytes' => 64]);

    $sentences = array_map(
        fn (int $number): string => "Sentence {$number} ends here.",
        range(1, 750),
    );
    $text = implode(' ', $sentences);
    $filePath = 'entities/'.uniqid('large_', true).'.txt';
    Storage::disk('local')->put($filePath, $text);

    $entity = EnEntity::query()->create(['name' => 'Large', 'file_path' => $filePath]);

    $stats = (new SentenceSplitter)->process($entity->id, $filePath, 'en');
    $payload = serialize(new SplitEntityFileSentences($entity->id, $filePath, 'en'));

    expect($stats['sentences'])->toBe(750)
        ->and($stats['batches'])->toBe(2)
        ->and($entity->sentences()->count())->toBe(750)
        ->and($entity->sentences()->orderBy('order')->first()->content)->toBe('Sentence 1 ends here.')
        ->and($entity->sentences()->orderByDesc('order')->first()->content)->toBe('Sentence 750 ends here.')
        ->and(strlen($payload))->toBeLessThan(2048)
        ->and($payload)->not->toContain('Sentence 750 ends here.');
});
