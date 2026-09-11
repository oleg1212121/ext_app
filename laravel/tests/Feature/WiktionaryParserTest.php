<?php

use App\Classes\WiktionaryParser;
use App\Models\Definition;
use App\Models\Form;
use App\Models\Language;
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

it('creates a word class for an unseen pos and imports the word', function () {
    $enLanguageId = createLanguages()['en']->id;
    $tmpFile = tempnam(sys_get_temp_dir(), 'wiktionary_test_');
    $lines = [
        json_encode(['word' => 'test', 'pos' => 'unsupported_pos', 'senses' => [['glosses' => ['A test']]]]),
    ];
    file_put_contents($tmpFile, implode("\n", $lines)."\n");

    $parser = new WiktionaryParser('en', 'ru', 100);
    $stats = $parser->import($tmpFile);

    expect($stats['words_imported'])->toBe(1);
    $word = Word::where('word', 'test')->where('language_id', $enLanguageId)->first();
    expect($word)->not->toBeNull();
    expect($word->wordClass->slug)->toBe('unsupported_pos');
    expect(Definition::count())->toBe(1);

    $createdClass = WordClass::where('language_id', $enLanguageId)->where('slug', 'unsupported_pos')->first();
    expect($createdClass)->not->toBeNull();
    expect($createdClass->title)->toBe('unsupported_pos');

    unlink($tmpFile);
});

it('bootstraps a fresh language with placeholder lookups', function () {
    $de = Language::create(['code' => 'de', 'name' => 'German', 'is_enabled' => false, 'sort_order' => 5]);
    $tmpFile = tempnam(sys_get_temp_dir(), 'wiktionary_test_');
    $lines = [
        json_encode(['word' => 'Hund', 'pos' => 'noun', 'sounds' => [['ipa' => '/hʊnt/']], 'senses' => [['glosses' => ['A dog']]]]),
    ];
    file_put_contents($tmpFile, implode("\n", $lines)."\n");

    $parser = new WiktionaryParser('de', 'en', 100);
    $stats = $parser->import($tmpFile);

    expect($stats['words_imported'])->toBe(1);
    expect($stats['lookups_created'])->toBe(3); // unknown + noun word classes, ipa transcription type

    expect(WordClass::where('language_id', $de->id)->where('slug', 'unknown')->where('title', 'unknown')->exists())->toBeTrue();
    expect(WordClass::where('language_id', $de->id)->where('slug', 'noun')->where('title', 'noun')->exists())->toBeTrue();
    expect(TranscriptionType::where('language_id', $de->id)->where('slug', 'ipa')->where('title', 'ipa')->exists())->toBeTrue();

    $word = Word::where('word', 'Hund')->where('language_id', $de->id)->first();
    expect($word)->not->toBeNull();
    expect($word->transcriptions)->toHaveCount(1);
    expect($word->transcriptions->first()->transcription)->toBe('/hʊnt/');

    unlink($tmpFile);
});

it('throws on missing file', function () {
    $parser = new WiktionaryParser('en', 'ru');
    $parser->import('/nonexistent/file.jsonl');
})->throws(InvalidArgumentException::class);
