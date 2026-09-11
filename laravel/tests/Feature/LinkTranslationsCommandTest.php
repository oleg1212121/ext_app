<?php

use App\Models\Word;
use App\Models\WordTranslation;

beforeEach(function () {
    createWordClasses();
});

it('links EN to RU words via stored translations', function () {
    $enWord = createWord('en', 'cat', 'noun', ['translations' => ['кошка']]);
    $ruWord = createWord('ru', 'кошка', 'noun');

    $this->artisan('wiktionary:link-translations')
        ->assertExitCode(0);

    expect(WordTranslation::count())->toBe(1)
        ->and(WordTranslation::where('word_a_id', min($enWord->id, $ruWord->id))->where('word_b_id', max($enWord->id, $ruWord->id))->exists())->toBeTrue();
});

it('strips stress marks when matching RU words', function () {
    // EN word has translation with stress mark (combining acute accent U+0301)
    createWord('en', 'house', 'noun', ['translations' => ["до\xCC\x81м"]]);
    // RU word is stored without stress mark
    createWord('ru', 'дом', 'noun');

    $this->artisan('wiktionary:link-translations')
        ->assertExitCode(0);

    expect(WordTranslation::count())->toBe(1);
});

it('matches by same POS only', function () {
    // EN noun 'run' translates to RU noun 'бег'
    $enWord = createWord('en', 'run', 'noun', ['translations' => ['бег']]);
    // RU noun 'бег' exists
    $noun = createWord('ru', 'бег', 'noun');
    // RU verb 'бежать' also exists
    createWord('ru', 'бежать', 'verb');

    $this->artisan('wiktionary:link-translations')
        ->assertExitCode(0);

    expect(WordTranslation::count())->toBe(1)
        ->and($enWord->translationWords()->pluck('id'))->toContain($noun->id)
        ->and($enWord->translationWords()->pluck('id'))->not->toContain(
            Word::query()->where('word', 'бежать')->value('id'),
        );
});

it('skips unmatched translations', function () {
    createWord('en', 'test', 'noun', ['translations' => ['несуществующееслово']]);
    createWord('ru', 'слово', 'noun');

    $this->artisan('wiktionary:link-translations')
        ->assertExitCode(0);

    expect(WordTranslation::count())->toBe(0);
});

it('links RU to EN words via stored translations', function () {
    $enWord = createWord('en', 'cat', 'noun');
    $ruWord = createWord('ru', 'кошка', 'noun', ['translations' => ['cat']]);

    $this->artisan('wiktionary:link-translations')
        ->assertExitCode(0);

    expect(WordTranslation::count())->toBe(1)
        ->and(WordTranslation::where('word_a_id', min($enWord->id, $ruWord->id))->where('word_b_id', max($enWord->id, $ruWord->id))->exists())->toBeTrue();
});

it('creates a single canonical row when both words stage each other', function () {
    $enWord = createWord('en', 'cat', 'noun', ['translations' => ['кошка']]);
    $ruWord = createWord('ru', 'кошка', 'noun', ['translations' => ['cat']]);

    $this->artisan('wiktionary:link-translations')
        ->assertExitCode(0);

    expect(WordTranslation::count())->toBe(1)
        ->and(WordTranslation::where('word_a_id', min($enWord->id, $ruWord->id))->where('word_b_id', max($enWord->id, $ruWord->id))->exists())->toBeTrue();
});

it('is idempotent on re-run and never duplicates manual links', function () {
    $enWord = createWord('en', 'cat', 'noun', ['translations' => ['кошка']]);
    $ruWord = createWord('ru', 'кошка', 'noun');

    // A manual link created before the run — same pair the linker would find.
    WordTranslation::link($enWord->id, $ruWord->id);

    $this->artisan('wiktionary:link-translations')->assertExitCode(0);
    $this->artisan('wiktionary:link-translations')->assertExitCode(0);

    expect(WordTranslation::count())->toBe(1);
});

it('links symmetrically from the manual helper regardless of argument order', function () {
    $enWord = createWord('en', 'cat', 'noun');
    $ruWord = createWord('ru', 'кошка', 'noun');

    $link = WordTranslation::link($ruWord->id, $enWord->id);

    expect($link->wasRecentlyCreated)->toBeTrue()
        ->and($link->word_a_id)->toBe(min($enWord->id, $ruWord->id))
        ->and($link->word_b_id)->toBe(max($enWord->id, $ruWord->id))
        ->and(WordTranslation::count())->toBe(1);
});
