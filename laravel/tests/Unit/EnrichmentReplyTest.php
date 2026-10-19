<?php

use App\Support\EnrichmentReply;

/**
 * The lenient JSON parser behind POST /ai/word-enrich (popup-ai-enrich
 * ticket 04): the model's reply — fences, prose preamble and all — decodes
 * into the five string-array sections with counts capped (12 translations /
 * 8 definitions / 4 each of the rest) and lengths truncated to the column
 * limits. Garbage in, empty lists out; no JSON object at all answers null so
 * the endpoint can finalize the ledger as a retryable failure.
 */
it('parses a plain JSON object into the five string lists', function () {
    $reply = json_encode([
        'translations' => ['банк', 'нассыпь'],
        'definitions' => ['Финансовая организация.'],
        'transcriptions' => ['bæŋk'],
        'examples' => ['He robbed the bank.'],
        'etymologies' => ['From Italian banca.'],
    ]);

    expect(EnrichmentReply::parse($reply))->toBe([
        'translations' => ['банк', 'нассыпь'],
        'definitions' => ['Финансовая организация.'],
        'transcriptions' => ['bæŋk'],
        'examples' => ['He robbed the bank.'],
        'etymologies' => ['From Italian banca.'],
    ]);
});

it('strips code fences and surrounding prose around the JSON object', function () {
    $fenced = "```json\n".json_encode(['translations' => ['банк']])."\n```";
    expect(EnrichmentReply::parse($fenced)['translations'])->toBe(['банк']);

    $prose = 'Here is the JSON you asked for: '.json_encode([
        'definitions' => ['A financial institution.'],
    ]).' — hope it helps!';
    expect(EnrichmentReply::parse($prose)['definitions'])->toBe(['A financial institution.']);
});

it('answers null when the reply holds no JSON object at all', function () {
    expect(EnrichmentReply::parse('I am sorry, I cannot help with that.'))->toBeNull();
    expect(EnrichmentReply::parse(''))->toBeNull();
    expect(EnrichmentReply::parse('{"translations": [broken'))->toBeNull();
});

it('coerces sloppy shapes: missing keys, bare strings, numbers, junk items', function () {
    $reply = json_encode([
        // Missing definitions/transcriptions/examples/etymologies entirely.
        'translations' => 'банк',
        'definitions' => ['Kept.', '', '   ', 42, 3.14, true, null, ['nested' => 'dropped'], 'Also kept.'],
    ]);

    $parsed = EnrichmentReply::parse($reply);

    expect($parsed['translations'])->toBe(['банк']);
    expect($parsed['definitions'])->toBe(['Kept.', '42', '3.14', 'Also kept.']);
    expect($parsed['transcriptions'])->toBe([]);
    expect($parsed['examples'])->toBe([]);
    expect($parsed['etymologies'])->toBe([]);
});

it('dedupes within a list before the cap is applied', function () {
    $reply = json_encode([
        'translations' => array_fill(0, 20, 'банк'),
        'definitions' => ['One.', 'One.', 'Two.', 'Two.'],
    ]);

    $parsed = EnrichmentReply::parse($reply);

    expect($parsed['translations'])->toBe(['банк']);
    expect($parsed['definitions'])->toBe(['One.', 'Two.']);
});

it('caps the counts at 12 translations, 8 definitions, 4 of the rest', function () {
    $reply = json_encode([
        'translations' => array_map(fn (int $i): string => "слово{$i}", range(1, 20)),
        'definitions' => array_map(fn (int $i): string => "Definition {$i}.", range(1, 15)),
        'transcriptions' => array_map(fn (int $i): string => "t{$i}", range(1, 9)),
        'examples' => array_map(fn (int $i): string => "Example {$i}.", range(1, 9)),
        'etymologies' => array_map(fn (int $i): string => "Etymology {$i}.", range(1, 9)),
    ]);

    $parsed = EnrichmentReply::parse($reply);

    expect(count($parsed['translations']))->toBe(12);
    expect(count($parsed['definitions']))->toBe(8);
    expect(count($parsed['transcriptions']))->toBe(4);
    expect(count($parsed['examples']))->toBe(4);
    expect(count($parsed['etymologies']))->toBe(4);
});

it('truncates to the column limits and drops over-long translations', function () {
    $reply = json_encode([
        'definitions' => [str_repeat('а', 700)],
        'transcriptions' => [str_repeat('b', 150)],
        'examples' => [str_repeat('c', 600)],
        'etymologies' => [str_repeat('d', 1200)],
        // A translation is a lookup candidate — a truncated word is no word:
        // over-long candidates are dropped, not truncated.
        'translations' => [str_repeat('е', 300), 'банк'],
    ]);

    $parsed = EnrichmentReply::parse($reply);

    expect($parsed['definitions'][0])->toBe(str_repeat('а', 500));
    expect($parsed['transcriptions'][0])->toBe(str_repeat('b', 100));
    expect($parsed['examples'][0])->toBe(str_repeat('c', 500));
    expect($parsed['etymologies'][0])->toBe(str_repeat('d', 1000));
    expect($parsed['translations'])->toBe(['банк']);
});
