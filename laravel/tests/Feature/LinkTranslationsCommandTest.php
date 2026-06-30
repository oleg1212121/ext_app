<?php

use App\Models\EnRuTranslation;
use App\Models\EnWord;
use App\Models\EnWordClass;
use App\Models\RuEnTranslation;
use App\Models\RuWord;
use App\Models\RuWordClass;

beforeEach(function () {
    EnWordClass::create(['slug' => 'noun', 'title' => 'Noun', 'description' => 'Test noun']);
    EnWordClass::create(['slug' => 'verb', 'title' => 'Verb', 'description' => 'Test verb']);
    EnWordClass::create(['slug' => 'unknown', 'title' => 'Unknown', 'description' => 'Unknown POS']);
    RuWordClass::create(['slug' => 'noun', 'title' => 'Существительное', 'description' => 'Тест']);
    RuWordClass::create(['slug' => 'verb', 'title' => 'Глагол', 'description' => 'Тест']);
    RuWordClass::create(['slug' => 'unknown', 'title' => 'Неизвестно', 'description' => 'Тест']);
});

it('links EN to RU words via stored translations', function () {
    $nounClassId = EnWordClass::where('slug', 'noun')->first()->id;
    $ruNounClassId = RuWordClass::where('slug', 'noun')->first()->id;

    EnWord::create(['word' => 'cat', 'l_word' => 'cat', 'en_word_class_id' => $nounClassId, 'translations' => ['кошка']]);
    RuWord::create(['word' => 'кошка', 'l_word' => 'кошка', 'ru_word_class_id' => $ruNounClassId]);

    $this->artisan('wiktionary:link-translations')
        ->assertExitCode(0);

    expect(EnRuTranslation::count())->toBe(1);
    expect(RuEnTranslation::count())->toBe(0);
});

it('strips stress marks when matching RU words', function () {
    $nounClassId = EnWordClass::where('slug', 'noun')->first()->id;
    $ruNounClassId = RuWordClass::where('slug', 'noun')->first()->id;

    // EN word has translation with stress mark (combining acute accent U+0301)
    EnWord::create(['word' => 'house', 'l_word' => 'house', 'en_word_class_id' => $nounClassId, 'translations' => ["до\xCC\x81м"]]);
    // RU word is stored without stress mark
    RuWord::create(['word' => 'дом', 'l_word' => 'дом', 'ru_word_class_id' => $ruNounClassId]);

    $this->artisan('wiktionary:link-translations')
        ->assertExitCode(0);

    expect(EnRuTranslation::count())->toBe(1);
});

it('matches by same POS only', function () {
    $nounClassId = EnWordClass::where('slug', 'noun')->first()->id;
    $verbClassId = EnWordClass::where('slug', 'verb')->first()->id;
    $ruNounClassId = RuWordClass::where('slug', 'noun')->first()->id;
    $ruVerbClassId = RuWordClass::where('slug', 'verb')->first()->id;

    // EN noun 'run' translates to RU noun 'бег'
    EnWord::create(['word' => 'run', 'l_word' => 'run', 'en_word_class_id' => $nounClassId, 'translations' => ['бег']]);
    // RU noun 'бег' exists
    RuWord::create(['word' => 'бег', 'l_word' => 'бег', 'ru_word_class_id' => $ruNounClassId]);
    // RU verb 'бежать' also exists
    RuWord::create(['word' => 'бежать', 'l_word' => 'бежать', 'ru_word_class_id' => $ruVerbClassId]);

    $this->artisan('wiktionary:link-translations')
        ->assertExitCode(0);

    $link = EnRuTranslation::first();
    expect($link->ru_word_id)->toBe(RuWord::where('word', 'бег')->first()->id);
});

it('skips unmatched translations', function () {
    $nounClassId = EnWordClass::where('slug', 'noun')->first()->id;

    EnWord::create(['word' => 'test', 'l_word' => 'test', 'en_word_class_id' => $nounClassId, 'translations' => ['несуществующееслово']]);

    $this->artisan('wiktionary:link-translations')
        ->assertExitCode(0);

    expect(EnRuTranslation::count())->toBe(0);
});

it('links RU to EN words via stored translations', function () {
    $nounClassId = EnWordClass::where('slug', 'noun')->first()->id;
    $ruNounClassId = RuWordClass::where('slug', 'noun')->first()->id;

    EnWord::create(['word' => 'cat', 'l_word' => 'cat', 'en_word_class_id' => $nounClassId]);
    RuWord::create(['word' => 'кошка', 'l_word' => 'кошка', 'ru_word_class_id' => $ruNounClassId, 'translations' => ['cat']]);

    $this->artisan('wiktionary:link-translations')
        ->assertExitCode(0);

    expect(RuEnTranslation::count())->toBe(1);
    expect(EnRuTranslation::count())->toBe(0);
});

it('creates both directions when both have translations', function () {
    $nounClassId = EnWordClass::where('slug', 'noun')->first()->id;
    $ruNounClassId = RuWordClass::where('slug', 'noun')->first()->id;

    EnWord::create(['word' => 'cat', 'l_word' => 'cat', 'en_word_class_id' => $nounClassId, 'translations' => ['кошка']]);
    RuWord::create(['word' => 'кошка', 'l_word' => 'кошка', 'ru_word_class_id' => $ruNounClassId, 'translations' => ['cat']]);

    $this->artisan('wiktionary:link-translations')
        ->assertExitCode(0);

    expect(EnRuTranslation::count())->toBe(1);
    expect(RuEnTranslation::count())->toBe(1);
});
