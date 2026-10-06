<?php

use App\Models\PromptTemplate;
use App\Support\PromptTemplates;
use Database\Seeders\PromptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('seeds one row per prompt key', function () {
    app(PromptTemplateSeeder::class)->run();

    expect(PromptTemplate::query()->count())->toBe(3);
    foreach ([PromptTemplates::FORMAT_KEY, PromptTemplates::TASKS_KEY, PromptTemplates::EXPLANATION_KEY] as $key) {
        expect(PromptTemplate::query()->where('key', $key)->exists())->toBeTrue();
    }
});

it('seeds the fallback text', function () {
    app(PromptTemplateSeeder::class)->run();

    expect(PromptTemplate::query()->where('key', PromptTemplates::FORMAT_KEY)->value('text'))
        ->toBe(PromptTemplates::FORMAT_FALLBACK);
    expect(PromptTemplate::query()->where('key', PromptTemplates::TASKS_KEY)->value('text'))
        ->toBe(PromptTemplates::TASKS_FALLBACK);
    expect(PromptTemplate::query()->where('key', PromptTemplates::EXPLANATION_KEY)->value('text'))
        ->toBe(PromptTemplates::EXPLANATION_FALLBACK);
});

it('is idempotent', function () {
    app(PromptTemplateSeeder::class)->run();
    app(PromptTemplateSeeder::class)->run();

    expect(PromptTemplate::query()->count())->toBe(3);
});

it('preserves admin-edited text on re-seed', function () {
    app(PromptTemplateSeeder::class)->run();

    PromptTemplate::query()->where('key', PromptTemplates::FORMAT_KEY)->firstOrFail()
        ->update(['text' => 'admin custom prompt']);

    app(PromptTemplateSeeder::class)->run();

    expect(PromptTemplate::query()->where('key', PromptTemplates::FORMAT_KEY)->value('text'))
        ->toBe('admin custom prompt');
    expect(PromptTemplate::query()->count())->toBe(3);
});
