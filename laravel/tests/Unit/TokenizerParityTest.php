<?php

use App\Classes\WordTokenizer;
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The browser tokenizer (resources/js/lib/wordTokenizer.mjs) drives
 * interactive word rendering, the PHP one drives the server word map. They
 * MUST produce identical l_word keys and counts or the map lookups miss.
 */
it('produces the same tokens as the browser tokenizer', function () {
    $samples = [
        'The quick brown foxes are running — don\'t stop!',
        'Привет, мир! Кошка спит на подоконнике; Ампер умер.',
        'well-known state-of-the-art naïve café',
        'Она сказала: «Я не знаю».',
        'It\'s the dogs\' bones, Ampère\'s law.',
        'Один-два-три… Слова с\' апострофом и дефис-черточка.',
        'Mix: кофейня, coexist, über, 单词.',
    ];

    $cli = base_path('resources/js/lib/wordTokenizer.cli.mjs');
    $escaped = implode(' ', array_map('escapeshellarg', $samples));
    $output = [];
    $exitCode = 1;
    exec('node '.escapeshellarg($cli).' '.$escaped.' 2>&1', $output, $exitCode);

    expect($exitCode)->toBe(0, 'node tokenizer CLI failed: '.implode("\n", $output));

    $jsSegments = json_decode(implode("\n", $output), true);
    expect($jsSegments)->toBeArray();

    $phpTokenizer = new WordTokenizer;

    foreach ($samples as $index => $sample) {
        $phpTokens = collect($phpTokenizer->tokenize($sample))
            ->map(fn (array $value, string $key): array => ['key' => $key, 'count' => $value['count']])
            ->sortBy('key')
            ->values()
            ->all();

        $jsTokens = collect($jsSegments[$index])
            ->filter(fn (array $segment): bool => $segment['key'] !== null)
            ->groupBy('key')
            ->map(fn (Collection $group): array => ['key' => $group->first()['key'], 'count' => $group->count()])
            ->sortBy('key')
            ->values()
            ->all();

        expect($jsTokens)->toBe($phpTokens, "tokenizer mismatch for sample #{$index}: {$sample}");
    }
});

it('renders non-word text between tokens as plain segments', function () {
    $cli = base_path('resources/js/lib/wordTokenizer.cli.mjs');
    $output = [];
    $exitCode = 1;
    exec('node '.escapeshellarg($cli).' '.escapeshellarg('Кот, а также dog 42.').' 2>&1', $output, $exitCode);

    expect($exitCode)->toBe(0);

    $segments = json_decode($output[0] ?? '[]', true)[0] ?? [];
    $texts = array_column($segments, 'text');

    expect(implode('', $texts))->toBe('Кот, а также dog 42.')
        ->and(array_values(array_filter(array_column($segments, 'key'))))->toBe(['кот', 'также', 'dog']);
});
