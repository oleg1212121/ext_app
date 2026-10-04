<?php

use App\Classes\PythonClient;
use App\Classes\SentenceSplitter;
use App\Classes\SparseOrderService;
use App\Jobs\ProcessEntityFile;
use App\Jobs\SplitEntityFileSentences;
use App\Models\SentenceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
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

function makeSplitter(): SentenceSplitter
{
    return new SentenceSplitter(
        new SparseOrderService,
        new PythonClient('http://ext_python:8000', 30, 600),
    );
}

it('assigns sparse document orders (0, 1024, 2048, ...) to inserted sentences', function () {
    Http::fake([
        '*' => Http::response([
            'sentences' => [
                ['content' => 'First.', 'type' => 'sentence'],
                ['content' => 'Second.', 'type' => 'sentence'],
                ['content' => 'Third.', 'type' => 'sentence'],
            ],
            'remainder' => '',
        ]),
    ]);

    $text = 'First. Second. Third.';
    $filePath = 'entities/'.uniqid('sparse_', true).'.txt';
    Storage::disk('local')->put($filePath, $text);

    $entity = createEntity('en', null, ['name' => 'Sparse', 'file_path' => $filePath]);

    makeSplitter()->process($entity->id, $filePath, $text);

    expect($entity->sentences()->orderBy('order')->pluck('order')->all())
        ->toEqual([0, 1024, 2048]);
});

it('streams file sentences with the same output as in-memory splitting', function () {
    config(['services.python.sentence_split_chunk_bytes' => 17]);

    fakePythonSplitter();

    $text = 'First sentence here. Second one follows. Third one ends.';
    $filePath = 'entities/'.uniqid('stream_', true).'.txt';
    Storage::disk('local')->put($filePath, $text);

    $streamedEntity = createEntity('en', null, ['name' => 'Streamed', 'file_path' => $filePath]);
    $memoryEntity = createEntity('en', null, ['name' => 'Memory', 'file_path' => $filePath]);

    $splitter = makeSplitter();
    $streamedStats = $splitter->process($streamedEntity->id, $filePath);
    $memoryStats = $splitter->process($memoryEntity->id, $filePath, $text);

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

    $entity = createEntity('en', null, ['name' => 'Boundary', 'file_path' => $filePath]);

    $stats = makeSplitter()->process($entity->id, $filePath);

    expect($entity->sentences()->orderBy('order')->pluck('content')->all())
        ->toEqual([
            'This sentence crosses several chunks before ending.',
            'Short one.',
        ])
        ->and($stats['sentences'])->toBe(2)
        ->and($stats['max_buffer_bytes'])->toBeGreaterThan(10);
});

it('keeps a multi-byte utf-8 character crossing chunk boundaries intact', function () {
    config(['services.python.sentence_split_chunk_bytes' => 5]);

    Http::fake(function (Request $request) {
        $text = (string) ($request->data()['text'] ?? '');
        expect(mb_check_encoding($text, 'UTF-8'))->toBeTrue();

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

    $text = 'АБВ. ГДЕ.';
    $filePath = 'entities/'.uniqid('utf8_', true).'.txt';
    Storage::disk('local')->put($filePath, $text);

    $entity = createEntity('ru', null, ['name' => 'Utf8', 'file_path' => $filePath]);

    $stats = makeSplitter()->process($entity->id, $filePath);

    expect($entity->sentences()->orderBy('order')->pluck('content')->all())
        ->toEqual(['АБВ.', 'ГДЕ.'])
        ->and($stats['sentences'])->toBe(2);
});

it('requests finalization only for the trailing remainder', function () {
    config(['services.python.sentence_split_chunk_bytes' => 10]);

    fakePythonSplitter();

    $text = 'Sentence one here. Sentence two here.';
    $filePath = 'entities/'.uniqid('finalize_', true).'.txt';
    Storage::disk('local')->put($filePath, $text);

    $entity = createEntity('en', null, ['name' => 'Finalize', 'file_path' => $filePath]);

    makeSplitter()->process($entity->id, $filePath);

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

    $entity = createEntity('en', null, ['name' => 'Types', 'file_path' => $filePath]);

    makeSplitter()->process($entity->id, $filePath, $text);

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

    $entity = createEntity('ru', null, ['name' => 'Russian', 'file_path' => $filePath]);

    makeSplitter()->process($entity->id, $filePath, $text);

    expect($entity->sentences()->orderBy('order')->pluck('content')->all())
        ->toEqual(['Первое предложение.', 'Второе предложение.']);

    Http::assertSent(function (Request $request): bool {
        return ($request->data()['language'] ?? null) === 'ru';
    });
});

it('inserts large streamed files in order while keeping the split job payload small', function () {
    config([
        'services.python.sentence_split_chunk_bytes' => 64,
        'services.python.sentence_split_chunks_per_run' => 0, // unlimited: one run
    ]);

    fakePythonSplitter();

    $sentences = array_map(
        fn (int $number): string => "Sentence {$number} ends here.",
        range(1, 750),
    );
    $text = implode(' ', $sentences);
    $filePath = 'entities/'.uniqid('large_', true).'.txt';
    Storage::disk('local')->put($filePath, $text);

    $entity = createEntity('en', null, ['name' => 'Large', 'file_path' => $filePath]);

    $stats = makeSplitter()->process($entity->id, $filePath);
    $payload = serialize(new SplitEntityFileSentences($entity->id, $filePath));

    expect($stats['sentences'])->toBe(750)
        ->and($stats['eof'])->toBeTrue()
        ->and($entity->sentences()->count())->toBe(750)
        ->and($entity->sentences()->orderBy('order')->first()->content)->toBe('Sentence 1 ends here.')
        ->and($entity->sentences()->orderByDesc('order')->first()->content)->toBe('Sentence 750 ends here.')
        ->and(strlen($payload))->toBeLessThan(2048)
        ->and($payload)->not->toContain('Sentence 750 ends here.');
});

it('stops at the run budget and resumes without duplicating or losing sentences', function () {
    config(['services.python.sentence_split_chunk_bytes' => 16]);
    config(['services.python.sentence_split_chunks_per_run' => 2]);

    fakePythonSplitter();

    $text = 'First sentence here. Second one follows. Third one ends. Fourth also. Fifth closes.';
    $filePath = 'entities/'.uniqid('resume_', true).'.txt';
    Storage::disk('local')->put($filePath, $text);

    $entity = createEntity('en', null, ['name' => 'Resume', 'file_path' => $filePath]);
    $splitter = makeSplitter();

    $run = 0;

    do {
        $stats = $splitter->process($entity->id, $filePath);
        $run++;

        expect($run)->toBeLessThan(20, 'splitter never converged');
    } while (! $stats['eof']);

    expect($run)->toBeGreaterThan(1, 'the budget must have split the file across runs')
        ->and($entity->fresh()->split_offset)->toBe(strlen($text))
        ->and($entity->fresh()->split_remainder)->toBe('')
        ->and($entity->sentences()->orderBy('order')->pluck('content')->all())->toEqual([
            'First sentence here.',
            'Second one follows.',
            'Third one ends.',
            'Fourth also.',
            'Fifth closes.',
        ])
        ->and($entity->sentences()->count())->toBe(5);
});

it('keeps a sentence intact across a resume boundary', function () {
    // Chunk edges fall inside "First sentence here." — the run budget cuts
    // the run mid-sentence, so the resume must re-feed python's remainder.
    config(['services.python.sentence_split_chunk_bytes' => 5]);
    config(['services.python.sentence_split_chunks_per_run' => 1]);

    fakePythonSplitter();

    $text = 'First sentence here. Second one. Third one.';
    $filePath = 'entities/'.uniqid('boundary_resume_', true).'.txt';
    Storage::disk('local')->put($filePath, $text);

    $streamedEntity = createEntity('en', null, ['name' => 'Streamed', 'file_path' => $filePath]);
    $memoryEntity = createEntity('en', null, ['name' => 'Memory', 'file_path' => $filePath]);
    $splitter = makeSplitter();

    do {
        $stats = $splitter->process($streamedEntity->id, $filePath);
    } while (! $stats['eof']);

    $splitter->process($memoryEntity->id, $filePath, $text);

    expect($streamedEntity->sentences()->orderBy('order')->pluck('content')->all())
        ->toEqual($memoryEntity->sentences()->orderBy('order')->pluck('content')->all())
        ->and($streamedEntity->fresh()->split_offset)->toBe(strlen($text));
});

it('resumes from a mid-file offset without re-reading committed text', function () {
    config(['services.python.sentence_split_chunk_bytes' => 10]);
    config(['services.python.sentence_split_chunks_per_run' => 1]);

    $requests = [];
    Http::fake(function (Request $request) use (&$requests) {
        $requests[] = (string) ($request->data()['text'] ?? '');

        return Http::response(['sentences' => [], 'remainder' => '']);
    });

    $text = 'Alpha one. Beta two. Gamma three.';
    $filePath = 'entities/'.uniqid('offset_', true).'.txt';
    Storage::disk('local')->put($filePath, $text);

    $entity = createEntity('en', null, ['name' => 'Offset', 'file_path' => $filePath]);
    $splitter = makeSplitter();

    $splitter->process($entity->id, $filePath);

    // One chunk per run: the run consumed exactly the bytes it sent.
    $firstRunEnd = max(array_map(strlen(...), $requests));
    expect($entity->fresh()->split_offset)->toBe($firstRunEnd);

    $requests = [];

    do {
        $stats = $splitter->process($entity->id, $filePath);
    } while (! $stats['eof']);

    // The resumed runs re-fed only remainders + fresh bytes — the first
    // run's committed text never went back to python, and the file is
    // consumed exactly once (10-byte chunks: 4 requests total across runs).
    expect($entity->fresh()->split_offset)->toBe(strlen($text))
        ->and(collect($requests)->filter(fn (string $text) => str_contains($text, 'Alpha')))->toBeEmpty()
        ->and($entity->sentences()->count())->toBe(0);
});

it('resets the split resume state when the upload pipeline re-dispatches the splitter', function () {
    $text = 'Fresh content.';
    $filePath = 'entities/'.uniqid('reset_', true).'.txt';
    Storage::disk('local')->put($filePath, $text);

    $entity = createEntity('en', null, [
        'name' => 'Reset',
        'file_path' => $filePath,
        'split_offset' => 9999,
        'split_remainder' => 'stale remainder from a previous file',
    ]);

    Bus::fake();

    (new ProcessEntityFile($entity->id, $filePath))->handle();

    expect($entity->refresh()->split_offset)->toBe(0)
        ->and($entity->refresh()->split_remainder)->toBe('');

    Bus::assertDispatched(SplitEntityFileSentences::class);
});

it('throws when the python split service responds with an error', function () {
    Http::fake(fn () => Http::response('service unavailable', 503));

    $text = 'Some text to split.';
    $filePath = 'entities/'.uniqid('error_', true).'.txt';
    Storage::disk('local')->put($filePath, $text);

    $entity = createEntity('en', null, ['name' => 'Error', 'file_path' => $filePath]);

    makeSplitter()->process($entity->id, $filePath, $text);
})->throws(RuntimeException::class, 'Python split service error');

it('decodes html numeric character references before splitting', function () {
    Http::fake(function (Request $request) {
        $text = (string) ($request->data()['text'] ?? '');

        expect($text)->not->toContain('&#252;')
            ->and($text)->not->toContain('&#1090;')
            ->and($text)->toContain('ü')
            ->and($text)->toContain('т');

        return Http::response([
            'sentences' => [
                ['content' => $text, 'type' => 'sentence'],
            ],
            'remainder' => '',
        ]);
    });

    $text = 'Gr&#252;ße und &#1090;екст.';
    $filePath = 'entities/'.uniqid('html_', true).'.txt';
    Storage::disk('local')->put($filePath, $text);

    $entity = createEntity('en', null, ['name' => 'HtmlEntities', 'file_path' => $filePath]);

    makeSplitter()->process($entity->id, $filePath, $text);

    expect($entity->sentences()->pluck('content')->first())
        ->toContain('ü')
        ->toContain('т')
        ->not->toContain('&#252;')
        ->not->toContain('&#1090;');
});
