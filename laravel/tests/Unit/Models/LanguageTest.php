<?php

use App\Models\Language;
use Database\Seeders\LanguageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('casts is_enabled as boolean and sort_order as integer', function () {
    $language = Language::create([
        'code' => 'fr',
        'name' => 'French',
        'is_enabled' => 1,
        'sort_order' => '5',
    ]);

    expect($language->is_enabled)->toBeTrue()
        ->and($language->sort_order)->toBeInt()->toBe(5);
});

it('filters enabled languages via the scope', function () {
    Language::create(['code' => 'fr', 'name' => 'French', 'is_enabled' => true, 'sort_order' => 2]);
    Language::create(['code' => 'de', 'name' => 'German', 'is_enabled' => false, 'sort_order' => 3]);

    $enabled = Language::enabled()->get();

    expect($enabled)->toHaveCount(1)
        ->and($enabled->first()->code)->toBe('fr');
});

it('seeds en and ru enabled by default', function () {
    app(LanguageSeeder::class)->run();

    expect(Language::query()->count())->toBe(2);
    expect(Language::query()->where('code', 'en')->exists())->toBeTrue();
    expect(Language::query()->where('code', 'ru')->exists())->toBeTrue();
});

it('seeder is idempotent', function () {
    app(LanguageSeeder::class)->run();
    app(LanguageSeeder::class)->run();

    expect(Language::query()->count())->toBe(2);
});
