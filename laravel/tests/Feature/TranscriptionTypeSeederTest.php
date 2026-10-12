<?php

use App\Models\Language;
use App\Models\TranscriptionType;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\TranscriptionTypeSeeder;

it('seeds one belarusian ipa transcription type', function () {
    app(LanguageSeeder::class)->run();
    app(TranscriptionTypeSeeder::class)->run();

    $be = Language::query()->where('code', 'be')->firstOrFail();
    $types = TranscriptionType::query()
        ->where('language_id', $be->id)
        ->get()
        ->mapWithKeys(fn (TranscriptionType $type): array => [$type->slug => $type->title])
        ->all();

    expect($types)->toBe(['ipa' => 'МФА']);
});

it('seeds belarusian transcription types idempotently', function () {
    app(LanguageSeeder::class)->run();
    app(TranscriptionTypeSeeder::class)->run();
    $be = Language::query()->where('code', 'be')->firstOrFail();
    $afterFirstRun = TranscriptionType::query()->where('language_id', $be->id)->count();

    app(TranscriptionTypeSeeder::class)->run();

    expect(TranscriptionType::query()->where('language_id', $be->id)->count())->toBe($afterFirstRun);
});
