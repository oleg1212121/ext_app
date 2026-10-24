<?php

use App\Models\Language;
use App\Models\WordClass;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\WordClassSeeder;

it('seeds curated belarusian word classes mirroring the shared slugs', function () {
    app(LanguageSeeder::class)->run();
    app(WordClassSeeder::class)->run();

    $be = Language::query()->where('code', 'be')->firstOrFail();
    // Explicit slug ordering: physical row order is not stable across the
    // parallel workers' freshly created databases, so the two compared
    // sequences must not rely on natural order.
    $enSlugs = WordClass::query()
        ->whereHas('language', fn ($query) => $query->where('code', 'en'))
        ->orderBy('slug')
        ->pluck('slug');
    $beClasses = WordClass::query()
        ->where('language_id', $be->id)
        ->orderBy('slug')
        ->get()
        ->mapWithKeys(fn (WordClass $class): array => [$class->slug => $class->title]);

    // Same slug set as English (slug-sorted sequences), native titles in Belarusian.
    expect($beClasses)->toHaveCount($enSlugs->count())
        ->and($beClasses->keys()->all())->toBe($enSlugs->values()->all())
        ->and($beClasses['noun'])->toBe('назоўнік')
        ->and($beClasses['verb'])->toBe('дзеяслоў')
        ->and($beClasses['proper_noun'])->toBe('Уласнае імя')
        ->and($beClasses['unknown'])->toBe('невядома');
});

it('seeds belarusian word classes idempotently', function () {
    app(LanguageSeeder::class)->run();
    app(WordClassSeeder::class)->run();
    $be = Language::query()->where('code', 'be')->firstOrFail();
    $afterFirstRun = WordClass::query()->where('language_id', $be->id)->count();

    app(WordClassSeeder::class)->run();

    expect(WordClass::query()->where('language_id', $be->id)->count())->toBe($afterFirstRun);
});
