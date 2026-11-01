<?php

use App\Classes\Crossword;
use App\Models\Definition;
use App\Models\Form;
use App\Models\Language;
use App\Models\Word;
use App\Models\WordClass;
use App\Models\WordTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function crosswordWords(): array
{
    createWordClasses();

    $stems = ['BLEAT', 'TABLE', 'ABLE', 'LATE', 'TEAL', 'BEAT', 'TALE', 'BALL', 'LAB'];

    $languageId = Language::query()->where('code', 'en')->value('id');
    $classId = WordClass::query()->where('slug', 'noun')->where('language_id', $languageId)->value('id');

    return collect($stems)
        ->map(fn ($stem) => Word::query()->firstOrCreate(
            ['word' => $stem, 'language_id' => $languageId, 'word_class_id' => $classId],
            ['l_word' => mb_strtolower($stem)],
        ))
        ->all();
}

function buildCrossword(): Crossword
{
    $words = crosswordWords();

    $crossword = new Crossword($words);
    $crossword->crossword();

    return $crossword;
}

it('places words or reports them as removed', function () {
    $crossword = buildCrossword();

    $placed = collect($crossword->words)->pluck('value');

    expect($placed->count() + count($crossword->removed))->toBe(9);
    expect($placed->count())->toBeGreaterThan(4);

    foreach ($crossword->words as $word) {
        expect(mb_strlen($word['value']))->toBeGreaterThanOrEqual(2);
    }
});

it('produces a consistent grid where crossing letters agree', function () {
    $crossword = buildCrossword();

    foreach ($crossword->newGrid as $row) {
        foreach ($row as $cell) {
            if ($cell['type'] === 4) {
                expect($cell['answer'])->toBe('');
            }
        }
    }

    // Every placed word's letters sit on letter cells (type 4) of the new grid.
    foreach ($crossword->words as $word) {
        for ($i = 0; $i < mb_strlen($word['value']); $i++) {
            $y = $word['vector'] ? $word['y'] : $word['y'] + $i;
            $x = $word['vector'] ? $word['x'] + $i : $word['x'];

            expect($crossword->newGrid[$y][$x]['type'])->toBe(4);
        }
    }
});

it('is deterministic for identical input', function () {
    $first = buildCrossword();
    $second = buildCrossword();

    expect($second->words)->toBe($first->words);
    expect($second->newGrid)->toBe($first->newGrid);
});

it('masks the word inside its own definitions', function () {
    $word = createWord('en', 'story', 'noun');
    Definition::query()->create([
        'word_id' => $word->id,
        'definition' => 'A story about a hero.',
    ]);

    $crossword = new Crossword([$word->refresh()]);
    $crossword->crossword();

    expect($crossword->dictionary['story']['sections'][0]['definitions'][0])->toBe('A **** about a hero.');
});

it('masks every family headword, longest first, across definitions and staged strings', function () {
    $run = createWord('en', 'run', 'verb');
    Definition::query()->create(['word_id' => $run->id, 'definition' => 'To run fast.']);
    $running = createWord('en', 'running', 'verb', ['translations' => ['бег', 'running wild']]);
    Definition::query()->create(['word_id' => $running->id, 'definition' => 'The running of a machine.']);
    Form::query()->create(['word_id' => $run->id, 'form' => 'running', 'l_word' => 'running']);

    $crossword = new Crossword([$running->refresh()]);
    $crossword->crossword();

    $sections = $crossword->dictionary['running']['sections'];

    // Both entries are verbs: one merged section whose definitions and staged
    // strings censor both headwords ("run" must not split "running" first).
    expect($sections)->toHaveCount(1)
        ->and($sections[0]['definitions'])->toContain('To **** fast.')
        ->and($sections[0]['definitions'])->toContain('The **** of a machine.')
        ->and($sections[0]['staged_translations'])->toBe(['бег', '**** wild']);
});

it('projects linked translations into the section with language codes, native first', function () {
    $en = createWord('en', 'book', 'noun');
    createLanguages();
    $ruLanguageId = Language::query()->where('code', 'ru')->value('id');
    $ru = Word::query()->create([
        'word' => 'книга',
        'l_word' => 'книга',
        'language_id' => $ruLanguageId,
        'word_class_id' => $en->word_class_id,
    ]);
    WordTranslation::link($en->id, $ru->id);

    $crossword = new Crossword([$en->refresh()], $ruLanguageId);
    $crossword->crossword();

    expect($crossword->dictionary['book']['sections'][0]['translations'])->toBe([
        ['word' => 'книга', 'language_code' => 'ru'],
    ]);
});

it('stages raw translations per section, deduped against linked words and case twins', function () {
    $en = createWord('en', 'book', 'noun', ['translations' => ['книга', 'том', 'Том']]);
    createLanguages();
    $ruLanguageId = Language::query()->where('code', 'ru')->value('id');
    $ru = Word::query()->create([
        'word' => 'книга',
        'l_word' => 'книга',
        'language_id' => $ruLanguageId,
        'word_class_id' => $en->word_class_id,
    ]);
    WordTranslation::link($en->id, $ru->id);

    $crossword = new Crossword([$en->refresh()], $ruLanguageId);
    $crossword->crossword();

    $section = $crossword->dictionary['book']['sections'][0];
    expect($section['translations'])->toBe([['word' => 'книга', 'language_code' => 'ru']])
        ->and($section['staged_translations'])->toBe(['том']);
});

it('orders sections by canonical class priority', function () {
    $noun = createWord('en', 'run', 'noun');
    Definition::query()->create(['word_id' => $noun->id, 'definition' => 'A pace faster than walking.']);
    $verb = createWord('en', 'run', 'verb');
    Definition::query()->create(['word_id' => $verb->id, 'definition' => 'To run swiftly.']);

    $crossword = new Crossword([$verb->refresh()]);
    $crossword->crossword();

    $sections = $crossword->dictionary['run']['sections'];

    expect(count($sections))->toBe(2)
        ->and($sections[0]['word_class'])->toBe('Noun')
        ->and($sections[0]['definitions'])->toBe(['A pace faster than walking.'])
        ->and($sections[1]['word_class'])->toBe('Verb')
        ->and($sections[1]['definitions'])->toBe(['To **** swiftly.']);
});

it('omits sections that hold neither definitions nor translations', function () {
    $word = createWord('en', 'story', 'noun');

    $crossword = new Crossword([$word->refresh()]);
    $crossword->crossword();

    expect($crossword->dictionary['story']['sections'])->toBe([]);
});
