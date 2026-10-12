<?php

use App\Models\Language;
use App\Models\WordClass;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\WordClassSeeder;

it('seeds curated belarusian word classes mirroring the shared slugs', function () {
    app(LanguageSeeder::class)->run();
    app(WordClassSeeder::class)->run();

    $be = Language::query()->where('code', 'be')->firstOrFail();
    $enSlugs = WordClass::query()
        ->whereHas('language', fn ($query) => $query->where('code', 'en'))
        ->pluck('slug');
    $beClasses = WordClass::query()
        ->where('language_id', $be->id)
        ->get()
        ->mapWithKeys(fn (WordClass $class): array => [$class->slug => $class->title]);

    // Same slug set as English, native titles in Belarusian.
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
