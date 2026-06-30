<?php

use App\Classes\WiktionaryParser;
use App\Models\EnDefinition;
use App\Models\EnForm;
use App\Models\EnTranscriptionType;
use App\Models\EnWord;
use App\Models\EnWordClass;
use App\Models\RuWordClass;

beforeEach(function () {
    EnWordClass::create(['slug' => 'noun', 'title' => 'Noun', 'description' => 'Test noun']);
    EnWordClass::create(['slug' => 'verb', 'title' => 'Verb', 'description' => 'Test verb']);
    EnWordClass::create(['slug' => 'unknown', 'title' => 'Unknown', 'description' => 'Unknown POS']);
    RuWordClass::create(['slug' => 'noun', 'title' => 'Существительное', 'description' => 'Тест']);
    RuWordClass::create(['slug' => 'verb', 'title' => 'Глагол', 'description' => 'Тест']);
    RuWordClass::create(['slug' => 'unknown', 'title' => 'Неизвестно', 'description' => 'Тест']);
    EnTranscriptionType::create(['slug' => 'ipa', 'title' => 'IPA', 'description' => 'Test']);
    EnTranscriptionType::create(['slug' => 'enpr', 'title' => 'English Pronunciation', 'description' => 'Test']);
});

it('imports a file and creates database records', function () {
    $tmpFile = tempnam(sys_get_temp_dir(), 'wiktionary_test_');
    $lines = [
        json_encode(['word' => 'cat', 'pos' => 'noun', 'senses' => [['glosses' => ['A small domesticated feline']]], 'translations' => [['code' => 'ru', 'word' => 'кошка']]]),
        json_encode(['word' => 'cat', 'pos' => 'noun', 'forms' => [['form' => 'cats']]]),
        json_encode(['word' => 'dog', 'pos' => 'noun', 'senses' => [['glosses' => ['A domesticated canine']]], 'translations' => [['code' => 'ru', 'word' => 'собака']]]),
    ];
    file_put_contents($tmpFile, implode("\n", $lines)."\n");

    $parser = new WiktionaryParser('en', 'ru', 100);
    $stats = $parser->import($tmpFile);

    expect($stats['words_imported'])->toBeGreaterThan(0);
    expect(EnWord::where('word', 'cat')->count())->toBe(1);
    expect(EnWord::where('word', 'dog')->count())->toBe(1);
    expect(EnDefinition::count())->toBeGreaterThan(0);
    expect(EnForm::where('form', 'cats')->count())->toBe(1);

    $cat = EnWord::where('word', 'cat')->first();
    expect($cat->translations)->toBeArray();
    expect($cat->translations)->toContain('кошка');

    $dog = EnWord::where('word', 'dog')->first();
    expect($dog->translations)->toBeArray();
    expect($dog->translations)->toContain('собака');

    unlink($tmpFile);
});

it('skips lines with unsupported pos', function () {
    $tmpFile = tempnam(sys_get_temp_dir(), 'wiktionary_test_');
    $lines = [
        json_encode(['word' => 'test', 'pos' => 'unsupported_pos', 'senses' => [['glosses' => ['A test']]]]),
    ];
    file_put_contents($tmpFile, implode("\n", $lines)."\n");

    $parser = new WiktionaryParser('en', 'ru', 100);
    $stats = $parser->import($tmpFile);

    expect($stats['words_skipped_pos'])->toBe(1);
    expect(EnWord::count())->toBe(0);

    unlink($tmpFile);
});

it('throws on missing file', function () {
    $parser = new WiktionaryParser('en', 'ru');
    $parser->import('/nonexistent/file.jsonl');
})->throws(InvalidArgumentException::class);
