<?php

use App\Classes\WiktionaryParser;
use App\Models\Definition;
use App\Models\Form;
use App\Models\TranscriptionType;
use App\Models\Word;
use App\Models\WordClass;

beforeEach(function () {
    $languages = createLanguages();

    WordClass::create(['language_id' => $languages['en']->id, 'slug' => 'noun', 'title' => 'Noun', 'description' => 'Test noun']);
    WordClass::create(['language_id' => $languages['en']->id, 'slug' => 'verb', 'title' => 'Verb', 'description' => 'Test verb']);
    WordClass::create(['language_id' => $languages['en']->id, 'slug' => 'unknown', 'title' => 'Unknown', 'description' => 'Unknown POS']);
    WordClass::create(['language_id' => $languages['ru']->id, 'slug' => 'noun', 'title' => 'Существительное', 'description' => 'Тест']);
    WordClass::create(['language_id' => $languages['ru']->id, 'slug' => 'verb', 'title' => 'Глагол', 'description' => 'Тест']);
    WordClass::create(['language_id' => $languages['ru']->id, 'slug' => 'unknown', 'title' => 'Неизвестно', 'description' => 'Тест']);
    TranscriptionType::create(['language_id' => $languages['en']->id, 'slug' => 'ipa', 'title' => 'IPA', 'description' => 'Test']);
    TranscriptionType::create(['language_id' => $languages['en']->id, 'slug' => 'enpr', 'title' => 'English Pronunciation', 'description' => 'Test']);
});

it('imports a file and creates database records', function () {
    $enLanguageId = createLanguages()['en']->id;
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
    expect(Word::where('word', 'cat')->where('language_id', $enLanguageId)->count())->toBe(1);
    expect(Word::where('word', 'dog')->where('language_id', $enLanguageId)->count())->toBe(1);
    expect(Definition::count())->toBeGreaterThan(0);
    expect(Form::where('form', 'cats')->count())->toBe(1);

    $cat = Word::where('word', 'cat')->where('language_id', $enLanguageId)->first();
    expect($cat->translations)->toBeArray();
    expect($cat->translations)->toContain('кошка');

    $dog = Word::where('word', 'dog')->where('language_id', $enLanguageId)->first();
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
    expect(Word::count())->toBe(0);

    unlink($tmpFile);
});

it('throws on missing file', function () {
    $parser = new WiktionaryParser('en', 'ru');
    $parser->import('/nonexistent/file.jsonl');
})->throws(InvalidArgumentException::class);
