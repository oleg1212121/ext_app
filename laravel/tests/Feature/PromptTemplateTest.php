<?php

use App\Models\PromptTemplate;
use App\Support\PromptTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('blank template rows fall back to the built-in defaults', function () {
    PromptTemplate::query()->create(['key' => PromptTemplates::FORMAT_KEY, 'text' => '   ']);
    PromptTemplate::query()->create(['key' => PromptTemplates::TASKS_KEY, 'text' => '']);

    expect(PromptTemplates::format())->toBe(PromptTemplates::FORMAT_FALLBACK)
        ->and(PromptTemplates::tasks())->toBe(PromptTemplates::TASKS_FALLBACK);
});

test('admin-edited template rows are used verbatim', function () {
    PromptTemplate::query()->updateOrCreate(
        ['key' => PromptTemplates::FORMAT_KEY],
        ['text' => 'Judge :base against :learning.'],
    );

    expect(PromptTemplates::format())->toBe('Judge :base against :learning.')
        ->and(PromptTemplates::tasks())->toBe(PromptTemplates::TASKS_FALLBACK);
});

test('assemble substitutes both placeholders and joins the task list', function () {
    createLanguages();

    expect(PromptTemplates::assemble('Do this.', 'en', 'ru'))->toBe(
        str_replace([':base', ':learning'], ['English', 'Russian'], PromptTemplates::FORMAT_FALLBACK).' Do this.'
    );
});

test('assemble falls back to the default task list when none was sent', function () {
    expect(PromptTemplates::assemble('   ', null, null))->toBe(
        str_replace([':base', ':learning'], ['', ''], PromptTemplates::FORMAT_FALLBACK).' '.PromptTemplates::TASKS_FALLBACK
    );
});

test('assemble keeps an unknown language code as its own name', function () {
    expect(PromptTemplates::assemble('Do this.', 'fr', 'de'))->toContain('Compare fr original vs. my de translation.');
});

test('the word-explanation template falls back to the built-in default', function () {
    expect(PromptTemplates::explanation('bank', 'Russian'))->toBe(
        str_replace([':word', ':native'], ['bank', 'Russian'], PromptTemplates::EXPLANATION_FALLBACK)
    );
});

test('a seeded word-explanation template is substituted and used', function () {
    PromptTemplate::query()->updateOrCreate(
        ['key' => PromptTemplates::EXPLANATION_KEY],
        ['text' => 'Explain :word for a :native speaker.'],
    );

    expect(PromptTemplates::explanation('bank', 'Russian'))->toBe('Explain bank for a Russian speaker.');
});
