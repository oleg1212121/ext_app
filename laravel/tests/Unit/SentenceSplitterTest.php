<?php

use App\Classes\SentenceSplitter;
use App\Jobs\SplitEntityFileSentences;
use App\Models\EnEntity;
use App\Models\RuEntity;
use App\Models\SentenceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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

/**
 * Emulates the python /split endpoint: naive punctuation boundaries with
 * python-like holdback semantics (raw tail of the text is the remainder
 * unless the request is final).
 */
function fakePythonSplitter(): void
{
    Http::fake(function (Request $request) {
        $text = (string) ($request->data()['text'] ?? '');
        $finalize = (bool) ($request->data()['finalize'] ?? false);

        $parts = trim($text) === ''
            ? []
            : (preg_split('/(?<=[.!?])\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);

        $sentences = array_map(
            fn (string $part): array => ['content' => $part, 'type' => 'sentence'],
            $parts,
        );

        $remainder = '';
        if (! $finalize && $sentences !== []) {
            $last = array_pop($sentences)['content'];
            $pos = strrpos($text, $last);
            $remainder = $pos === false ? $last : substr($text, $pos);
        }

        return Http::response(['sentences' => $sentences, 'remainder' => $remainder]);
    });
}

it('streams file sentences with the same output as in-memory splitting', function () {
    config(['services.python.sentence_split_chunk_bytes' => 17]);

    fakePythonSplitter();

    $text = 'First sentence here. Second one follows. Third one ends.';
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
    config(['services.python.sentence_split_chunk_bytes' => 10]);

    fakePythonSplitter();

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

it('requests finalization only for the trailing remainder', function () {
    config(['services.python.sentence_split_chunk_bytes' => 10]);

    fakePythonSplitter();

    $text = 'Sentence one here. Sentence two here.';
    $filePath = 'entities/'.uniqid('finalize_', true).'.txt';
    Storage::disk('local')->put($filePath, $text);

    $entity = EnEntity::query()->create(['name' => 'Finalize', 'file_path' => $filePath]);

    (new SentenceSplitter)->process($entity->id, $filePath, 'en');

    Http::assertSent(function (Request $request): bool {
        return ($request->data()['finalize'] ?? null) === false;
    });

    $finalizeRequests = collect(Http::recorded())
        ->filter(fn (array $pair): bool => ($pair[0]->data()['finalize'] ?? null) === true);

    expect($finalizeRequests)->toHaveCount(1);
});

it('maps python sentence types to sentence type ids', function () {
    Http::fake([
        '*' => Http::response([
            'sentences' => [
                ['content' => 'CHAPTER ONE', 'type' => 'title'],
                ['content' => 'A regular one.', 'type' => 'sentence'],
                ['content' => '"Wait!"', 'type' => 'quote'],
            ],
            'remainder' => '',
        ]),
    ]);

    $text = "CHAPTER ONE\nA regular one. \"Wait!\"";
    $filePath = 'entities/'.uniqid('types_', true).'.txt';
    Storage::disk('local')->put($filePath, $text);

    $entity = EnEntity::query()->create(['name' => 'Types', 'file_path' => $filePath]);

    (new SentenceSplitter)->process($entity->id, $filePath, 'en', $text);

    $sentences = $entity->sentences()
        ->with('sentenceType')
        ->orderBy('order')
        ->get()
        ->map(fn ($sentence): array => [$sentence->content, $sentence->sentenceType->name])
        ->all();

    expect($sentences)->toEqual([
        ['CHAPTER ONE', 'title'],
        ['A regular one.', 'sentence'],
        ['"Wait!"', 'quote'],
    ]);
});

it('stores russian sentences on the russian entity', function () {
    Http::fake([
        '*' => Http::response([
            'sentences' => [
                ['content' => 'Первое предложение.', 'type' => 'sentence'],
                ['content' => 'Второе предложение.', 'type' => 'sentence'],
            ],
            'remainder' => '',
        ]),
    ]);

    $text = 'Первое предложение. Второе предложение.';
    $filePath = 'entities/'.uniqid('ru_', true).'.txt';
    Storage::disk('local')->put($filePath, $text);

    $entity = RuEntity::query()->create(['name' => 'Russian', 'file_path' => $filePath]);

    (new SentenceSplitter)->process($entity->id, $filePath, 'ru', $text);

    expect($entity->sentences()->orderBy('order')->pluck('content')->all())
        ->toEqual(['Первое предложение.', 'Второе предложение.']);

    Http::assertSent(function (Request $request): bool {
        return ($request->data()['language'] ?? null) === 'ru';
    });
});

it('inserts large streamed files in order while keeping the split job payload small', function () {
    config(['services.python.sentence_split_chunk_bytes' => 64]);

    fakePythonSplitter();

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

it('throws when the python split service responds with an error', function () {
    Http::fake(fn () => Http::response('service unavailable', 503));

    $text = 'Some text to split.';
    $filePath = 'entities/'.uniqid('error_', true).'.txt';
    Storage::disk('local')->put($filePath, $text);

    $entity = EnEntity::query()->create(['name' => 'Error', 'file_path' => $filePath]);

    (new SentenceSplitter)->process($entity->id, $filePath, 'en', $text);
})->throws(RuntimeException::class, 'Python split service error');
