<?php

use App\Classes\Crossword;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function crosswordWords(): array
{
    createWordClasses();

    $stems = ['BLEAT', 'TABLE', 'ABLE', 'LATE', 'TEAL', 'BEAT', 'TALE', 'BALL', 'LAB'];

    $languageId = \App\Models\Language::query()->where('code', 'en')->value('id');
    $classId = \App\Models\WordClass::query()->where('slug', 'noun')->where('language_id', $languageId)->value('id');

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
    \App\Models\Definition::query()->create([
        'word_id' => $word->id,
        'definition' => 'A story about a hero.',
    ]);

    $crossword = new Crossword([$word->refresh()]);
    $crossword->crossword();

    expect($crossword->dictionary['story']['definitions'][0])->toBe('A **** about a hero.');
});

it('lists translations as plain strings', function () {
    $en = createWord('en', 'book', 'noun');
    createLanguages();
    $ruLanguageId = \App\Models\Language::query()->where('code', 'ru')->value('id');
    $ru = Word::query()->create([
        'word' => 'книга',
        'l_word' => 'книга',
        'language_id' => $ruLanguageId,
        'word_class_id' => $en->word_class_id,
    ]);
    \App\Models\WordTranslation::link($en->id, $ru->id);

    $crossword = new Crossword([$en->refresh()], $ruLanguageId);
    $crossword->crossword();

    expect($crossword->dictionary['book']['translations'])->toBe(['книга']);
});
