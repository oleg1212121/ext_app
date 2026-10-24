<?php

use App\Models\Language;
use App\Models\Word;
use App\Models\WordClass;
use App\Models\WordTranslation;

beforeEach(function () {
    createWordClasses();
});

it('links EN to RU words via stored translations', function () {
    $enWord = createWord('en', 'cat', 'noun', ['translations' => ['кошка']]);
    $ruWord = createWord('ru', 'кошка', 'noun');

    $this->artisan('dictionary:link-translations')
        ->assertExitCode(0);

    expect(WordTranslation::count())->toBe(1)
        ->and(WordTranslation::where('word_a_id', min($enWord->id, $ruWord->id))->where('word_b_id', max($enWord->id, $ruWord->id))->exists())->toBeTrue();
});

it('strips stress marks when matching RU words', function () {
    // EN word has translation with stress mark (combining acute accent U+0301)
    createWord('en', 'house', 'noun', ['translations' => ["до\xCC\x81м"]]);
    // RU word is stored without stress mark
    createWord('ru', 'дом', 'noun');

    $this->artisan('dictionary:link-translations')
        ->assertExitCode(0);

    expect(WordTranslation::count())->toBe(1);
});

it('strips stress marks when matching BE words', function () {
    createLanguages(['en', 'ru', 'be']);
    createWordClasses();
    $beLanguageId = Language::query()->where('code', 'be')->value('id');
    $beNounId = WordClass::query()->create(['language_id' => $beLanguageId, 'slug' => 'noun', 'title' => 'Назоўнік'])->id;

    // EN word has translation with stress mark (combining acute accent U+0301)
    createWord('en', 'house', 'noun', ['translations' => ["до\xCC\x81м"]]);
    // BE word is stored without stress mark
    Word::query()->create([
        'word' => 'дом',
        'l_word' => 'дом',
        'language_id' => $beLanguageId,
        'word_class_id' => $beNounId,
    ]);

    $this->artisan('dictionary:link-translations')
        ->assertExitCode(0);

    expect(WordTranslation::count())->toBe(1);
});

it('prefers the target row sharing the source word class', function () {
    // EN noun 'run' translates to RU noun 'бег'
    $enWord = createWord('en', 'run', 'noun', ['translations' => ['бег']]);
    // RU noun 'бег' exists
    $noun = createWord('ru', 'бег', 'noun');
    // RU verb 'бежать' also exists
    createWord('ru', 'бежать', 'verb');

    $this->artisan('dictionary:link-translations')
        ->assertExitCode(0);

    expect(WordTranslation::count())->toBe(1)
        ->and($enWord->translationWords()->pluck('id'))->toContain($noun->id)
        ->and($enWord->translationWords()->pluck('id'))->not->toContain(
            Word::query()->where('word', 'бежать')->value('id'),
        );
});

it('falls back to another class when the target language lacks the wanted class', function () {
    // EN noun stages a candidate whose only RU row is a verb — the old
    // class-strict match skipped it; the shared resolver's any-class
    // fallback links it.
    $enWord = createWord('en', 'run', 'noun', ['translations' => ['бег']]);
    $ruVerb = createWord('ru', 'бег', 'verb');

    $this->artisan('dictionary:link-translations')
        ->assertExitCode(0);

    expect(WordTranslation::count())->toBe(1)
        ->and($enWord->translationWords()->pluck('id'))->toContain($ruVerb->id);
});

it('links a multi-word candidate to the phrase-class row', function () {
    // A multi-word staged candidate wants the phrase class; the source
    // word's own class (verb) must not gate it.
    $enWord = createWord('en', 'give up', 'verb', ['translations' => ['сдаваться и плакать']]);
    $phrase = createWord('ru', 'сдаваться и плакать', 'phrase');

    $this->artisan('dictionary:link-translations')
        ->assertExitCode(0);

    expect(WordTranslation::count())->toBe(1)
        ->and($enWord->translationWords()->pluck('id'))->toContain($phrase->id);
});

it('links the lowest-id row when cross-class rows share the l_word', function () {
    $enWord = createWord('en', 'cat', 'noun', ['translations' => ['кошка']]);
    $ruLanguageId = Language::query()->where('code', 'ru')->value('id');
    $verbId = WordClass::query()->where('language_id', $ruLanguageId)->where('slug', 'verb')->value('id');
    $phraseId = WordClass::query()->where('language_id', $ruLanguageId)->where('slug', 'phrase')->value('id');

    // Cross-class rows sharing one l_word are legal (die noun/verb) — and
    // since the same-class preference has no match here, the lowest id must
    // win. Creation order pins the ids.
    $lower = Word::query()->create(['word' => 'Кошка', 'l_word' => 'кошка', 'language_id' => $ruLanguageId, 'word_class_id' => $verbId]);
    $higher = Word::query()->create(['word' => 'кошка', 'l_word' => 'кошка', 'language_id' => $ruLanguageId, 'word_class_id' => $phraseId]);
    expect($lower->id)->toBeLessThan($higher->id);

    $this->artisan('dictionary:link-translations')
        ->assertExitCode(0);

    expect(WordTranslation::count())->toBe(1)
        ->and($enWord->translationWords()->pluck('id'))->toContain($lower->id)
        ->and($enWord->translationWords()->pluck('id'))->not->toContain($higher->id);
});

it('skips unmatched translations', function () {
    createWord('en', 'test', 'noun', ['translations' => ['несуществующееслово']]);
    createWord('ru', 'слово', 'noun');

    $this->artisan('dictionary:link-translations')
        ->assertExitCode(0);

    expect(WordTranslation::count())->toBe(0);
});

it('links RU to EN words via stored translations', function () {
    $enWord = createWord('en', 'cat', 'noun');
    $ruWord = createWord('ru', 'кошка', 'noun', ['translations' => ['cat']]);

    $this->artisan('dictionary:link-translations')
        ->assertExitCode(0);

    expect(WordTranslation::count())->toBe(1)
        ->and(WordTranslation::where('word_a_id', min($enWord->id, $ruWord->id))->where('word_b_id', max($enWord->id, $ruWord->id))->exists())->toBeTrue();
});

it('creates a single canonical row when both words stage each other', function () {
    $enWord = createWord('en', 'cat', 'noun', ['translations' => ['кошка']]);
    $ruWord = createWord('ru', 'кошка', 'noun', ['translations' => ['cat']]);

    $this->artisan('dictionary:link-translations')
        ->assertExitCode(0);

    expect(WordTranslation::count())->toBe(1)
        ->and(WordTranslation::where('word_a_id', min($enWord->id, $ruWord->id))->where('word_b_id', max($enWord->id, $ruWord->id))->exists())->toBeTrue();
});

it('prunes the linked candidates from the staged JSON and keeps the unmatched', function () {
    $enWord = createWord('en', 'cat', 'noun', ['translations' => ['кошка', 'несуществующееслово']]);
    createWord('ru', 'кошка', 'noun');

    $this->artisan('dictionary:link-translations')
        ->assertExitCode(0);

    $enWord->refresh();
    expect($enWord->translations)->toBe(['несуществующееслово'])
        ->and(WordTranslation::count())->toBe(1);
});

it('nulls the staged JSON once its last candidate linked', function () {
    $ruWord = createWord('ru', 'слово', 'noun', ['translations' => ['word']]);
    createWord('en', 'word', 'noun');

    $this->artisan('dictionary:link-translations')
        ->assertExitCode(0);

    $ruWord->refresh();
    expect($ruWord->translations)->toBeNull()
        ->and(WordTranslation::count())->toBe(1);
});

it('prunes the exact stored string even when it carried stress marks', function () {
    // Legacy kaikki-staged candidate with a combining acute: it matches the
    // plain row and the exact stored string (marks included) leaves the JSON.
    $stressed = "до\xCC\x81м";
    $enWord = createWord('en', 'house', 'noun', ['translations' => [$stressed]]);
    createWord('ru', 'дом', 'noun');

    $this->artisan('dictionary:link-translations')
        ->assertExitCode(0);

    $enWord->refresh();
    expect($enWord->translations)->toBeNull()
        ->and(WordTranslation::count())->toBe(1);
});

it('shrinks the staged JSON on the first run and stays idempotent on re-runs', function () {
    $enWord = createWord('en', 'cat', 'noun', ['translations' => ['кошка', 'несуществующееслово']]);
    $ruWord = createWord('ru', 'кошка', 'noun');

    // A manual link created before the run — same pair the linker would find.
    WordTranslation::link($enWord->id, $ruWord->id);

    $this->artisan('dictionary:link-translations')->assertExitCode(0);
    $this->artisan('dictionary:link-translations')->assertExitCode(0);

    $enWord->refresh();
    expect($enWord->translations)->toBe(['несуществующееслово'])
        ->and(WordTranslation::count())->toBe(1);
});

it('reports unmatched counts with a capped sample instead of per-candidate warns', function () {
    $misses = ['мимоодин', 'мимодва', 'мимотри', 'мимочетыре', 'мимопять', 'мимошесть', 'мимосемь', 'мимовосемь', 'мимодевять', 'мимодесять', 'мимоодиннадцать', 'мимодвенадцать'];
    createWord('en', 'test', 'noun', ['translations' => $misses]);
    createWord('ru', 'слово', 'noun');

    $this->artisan('dictionary:link-translations')
        // No per-candidate warn lines.
        ->doesntExpectOutputToContain('No match:')
        // The count lands in the summary table.
        ->expectsOutputToContain('Unmatched')
        // The sample is capped: the first ten misses appear, the tail does not.
        ->expectsOutputToContain('мимоодин')
        ->expectsOutputToContain('мимодесять')
        ->doesntExpectOutputToContain('мимоодиннадцать')
        ->doesntExpectOutputToContain('мимодвенадцать')
        ->assertExitCode(0);
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
