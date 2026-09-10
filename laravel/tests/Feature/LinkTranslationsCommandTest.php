<?php

use App\Models\Word;
use App\Models\WordClass;
use App\Models\WordTranslation;

beforeEach(function () {
    $languages = createLanguages();

    WordClass::create(['language_id' => $languages['en']->id, 'slug' => 'noun', 'title' => 'Noun', 'description' => 'Test noun']);
    WordClass::create(['language_id' => $languages['en']->id, 'slug' => 'verb', 'title' => 'Verb', 'description' => 'Test verb']);
    WordClass::create(['language_id' => $languages['en']->id, 'slug' => 'unknown', 'title' => 'Unknown', 'description' => 'Unknown POS']);
    WordClass::create(['language_id' => $languages['ru']->id, 'slug' => 'noun', 'title' => 'Существительное', 'description' => 'Тест']);
    WordClass::create(['language_id' => $languages['ru']->id, 'slug' => 'verb', 'title' => 'Глагол', 'description' => 'Тест']);
    WordClass::create(['language_id' => $languages['ru']->id, 'slug' => 'unknown', 'title' => 'Неизвестно', 'description' => 'Тест']);
});

function wordClassId(string $languageCode, string $slug): int
{
    $languages = createLanguages();

    return (int) WordClass::query()
        ->where('language_id', $languages[$languageCode]->id)
        ->where('slug', $slug)
        ->value('id');
}

it('links EN to RU words via stored translations', function () {
    $languages = createLanguages();

    $enWord = Word::create(['word' => 'cat', 'l_word' => 'cat', 'language_id' => $languages['en']->id, 'word_class_id' => wordClassId('en', 'noun'), 'translations' => ['кошка']]);
    $ruWord = Word::create(['word' => 'кошка', 'l_word' => 'кошка', 'language_id' => $languages['ru']->id, 'word_class_id' => wordClassId('ru', 'noun')]);

    $this->artisan('wiktionary:link-translations')
        ->assertExitCode(0);

    expect(WordTranslation::count())->toBe(1)
        ->and(WordTranslation::where('from_word_id', $enWord->id)->where('to_word_id', $ruWord->id)->exists())->toBeTrue()
        ->and(WordTranslation::where('from_word_id', $ruWord->id)->count())->toBe(0);
});

it('strips stress marks when matching RU words', function () {
    $languages = createLanguages();

    // EN word has translation with stress mark (combining acute accent U+0301)
    Word::create(['word' => 'house', 'l_word' => 'house', 'language_id' => $languages['en']->id, 'word_class_id' => wordClassId('en', 'noun'), 'translations' => ["до\xCC\x81м"]]);
    // RU word is stored without stress mark
    Word::create(['word' => 'дом', 'l_word' => 'дом', 'language_id' => $languages['ru']->id, 'word_class_id' => wordClassId('ru', 'noun')]);

    $this->artisan('wiktionary:link-translations')
        ->assertExitCode(0);

    expect(WordTranslation::count())->toBe(1);
});

it('matches by same POS only', function () {
    $languages = createLanguages();

    // EN noun 'run' translates to RU noun 'бег'
    Word::create(['word' => 'run', 'l_word' => 'run', 'language_id' => $languages['en']->id, 'word_class_id' => wordClassId('en', 'noun'), 'translations' => ['бег']]);
    // RU noun 'бег' exists
    Word::create(['word' => 'бег', 'l_word' => 'бег', 'language_id' => $languages['ru']->id, 'word_class_id' => wordClassId('ru', 'noun')]);
    // RU verb 'бежать' also exists
    Word::create(['word' => 'бежать', 'l_word' => 'бежать', 'language_id' => $languages['ru']->id, 'word_class_id' => wordClassId('ru', 'verb')]);

    $this->artisan('wiktionary:link-translations')
        ->assertExitCode(0);

    $link = WordTranslation::first();
    expect($link->to_word_id)->toBe(Word::where('word', 'бег')->where('language_id', $languages['ru']->id)->first()->id);
});

it('skips unmatched translations', function () {
    $languages = createLanguages();

    Word::create(['word' => 'test', 'l_word' => 'test', 'language_id' => $languages['en']->id, 'word_class_id' => wordClassId('en', 'noun'), 'translations' => ['несуществующееслово']]);
    Word::create(['word' => 'слово', 'l_word' => 'слово', 'language_id' => $languages['ru']->id, 'word_class_id' => wordClassId('ru', 'noun')]);

    $this->artisan('wiktionary:link-translations')
        ->assertExitCode(0);

    expect(WordTranslation::count())->toBe(0);
});

it('links RU to EN words via stored translations', function () {
    $languages = createLanguages();

    $enWord = Word::create(['word' => 'cat', 'l_word' => 'cat', 'language_id' => $languages['en']->id, 'word_class_id' => wordClassId('en', 'noun')]);
    $ruWord = Word::create(['word' => 'кошка', 'l_word' => 'кошка', 'language_id' => $languages['ru']->id, 'word_class_id' => wordClassId('ru', 'noun'), 'translations' => ['cat']]);

    $this->artisan('wiktionary:link-translations')
        ->assertExitCode(0);

    expect(WordTranslation::count())->toBe(1)
        ->and(WordTranslation::where('from_word_id', $ruWord->id)->where('to_word_id', $enWord->id)->exists())->toBeTrue()
        ->and(WordTranslation::where('from_word_id', $enWord->id)->count())->toBe(0);
});

it('creates both directions when both have translations', function () {
    $languages = createLanguages();

    $enWord = Word::create(['word' => 'cat', 'l_word' => 'cat', 'language_id' => $languages['en']->id, 'word_class_id' => wordClassId('en', 'noun'), 'translations' => ['кошка']]);
    $ruWord = Word::create(['word' => 'кошка', 'l_word' => 'кошка', 'language_id' => $languages['ru']->id, 'word_class_id' => wordClassId('ru', 'noun'), 'translations' => ['cat']]);

    $this->artisan('wiktionary:link-translations')
        ->assertExitCode(0);

    expect(WordTranslation::count())->toBe(2)
        ->and(WordTranslation::where('from_word_id', $enWord->id)->where('to_word_id', $ruWord->id)->exists())->toBeTrue()
        ->and(WordTranslation::where('from_word_id', $ruWord->id)->where('to_word_id', $enWord->id)->exists())->toBeTrue();
});
