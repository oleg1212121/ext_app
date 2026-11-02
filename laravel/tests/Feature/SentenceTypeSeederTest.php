<?php

use App\Models\SentenceType;
use Database\Seeders\SentenceTypeSeeder;

it('seeds the paragraph sentence type beside the seven existing types', function () {
    app(SentenceTypeSeeder::class)->run();

    $names = SentenceType::query()->orderBy('name')->pluck('name')->all();

    expect($names)->toContain('paragraph')
        ->and(SentenceType::query()->count())->toBe(8)
        ->and(SentenceType::query()->where('name', 'paragraph')->sole()->description)
        ->toBe('A body sentence that opens a paragraph');
});

it('seeds sentence types idempotently', function () {
    app(SentenceTypeSeeder::class)->run();
    $afterFirstRun = SentenceType::query()->count();

    app(SentenceTypeSeeder::class)->run();

    expect(SentenceType::query()->count())->toBe($afterFirstRun)
        ->and(SentenceType::query()->where('name', 'paragraph')->count())->toBe(1);
});
