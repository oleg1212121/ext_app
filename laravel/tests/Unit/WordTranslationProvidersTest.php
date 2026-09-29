<?php

use App\Classes\WordTranslations\GoogleTranslateProvider;
use App\Classes\WordTranslations\WordTranslationResolver;
use App\Classes\WordTranslations\YandexDictionaryProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

if (! function_exists('configureYandexTestProvider')) {
    function configureYandexTestProvider(): YandexDictionaryProvider
    {
        config([
            'services.yandex_translate.key' => 'yandex-test-key',
            'services.yandex_translate.folder_id' => 'folder-1',
            'services.yandex_translate.url' => 'https://yandex.test/translate/v2',
        ]);

        return new YandexDictionaryProvider;
    }
}

if (! function_exists('configureGoogleTestProvider')) {
    function configureGoogleTestProvider(): GoogleTranslateProvider
    {
        config([
            'services.google_translate.key' => 'google-test-key',
            'services.google_translate.url' => 'https://google.test/language/translate/v2',
        ]);

        return new GoogleTranslateProvider;
    }
}

it('reports providers unconfigured without keys', function () {
    config([
        'services.yandex_translate.key' => null,
        'services.google_translate.key' => null,
    ]);

    expect(new YandexDictionaryProvider)->isConfigured()->toBeFalse()
        ->and(new GoogleTranslateProvider)->isConfigured()->toBeFalse()
        ->and(new WordTranslationResolver)->hasProvider()->toBeFalse();
});

it('parses yandex dictionary entries, strips stress marks, dedupes and caps candidates', function () {
    $provider = configureYandexTestProvider();

    $filler = [];
    for ($i = 0; $i < 15; $i++) {
        $filler[] = ['text' => "синоним{$i}"];
    }

    Http::fake(['yandex.test/*' => Http::response([
        'def' => [
            [
                'pos' => 'noun',
                'text' => 'cat',
                'tr' => array_merge([
                    ['text' => "кот\u{0301}"],
                    ['text' => 'КОТ'], // case-insensitive duplicate of the first
                    ['text' => '   '], // blank
                ], $filler),
            ],
            // never reached — the candidate cap stops at the first definition
            ['pos' => 'verb', 'text' => 'cat', 'tr' => [['text' => 'жить по-кошачьи', 'pos' => 'verb']]],
        ],
    ])]);

    $candidates = $provider->fetch('cat', 'en', 'ru');

    expect($candidates)->toHaveCount(10)
        ->and($candidates[0])->toBe(['text' => 'кот', 'pos' => 'noun'])
        ->and($candidates[9])->toBe(['text' => 'синоним8', 'pos' => 'noun']);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://yandex.test/translate/v2/dictionary/lookup'
            && $request->hasHeader('Authorization', 'Api-Key yandex-test-key')
            && $request['texts'] === ['cat']
            && $request['sourceLanguageCode'] === 'en'
            && $request['targetLanguageCode'] === 'ru'
            && $request['folderId'] === 'folder-1';
    });
});

it('returns the single google translation as a pos-less candidate', function () {
    $provider = configureGoogleTestProvider();

    Http::fake(['google.test/*' => Http::response([
        'data' => ['translations' => [['translatedText' => 'кот']]],
    ])]);

    expect($provider->fetch('cat', 'en', 'ru'))->toBe([['text' => 'кот', 'pos' => null]]);

    Http::assertSent(fn ($request) => $request->url() === 'https://google.test/language/translate/v2?key=google-test-key'
        && $request['q'] === 'cat'
        && $request['source'] === 'en'
        && $request['target'] === 'ru'
        && $request['format'] === 'text');
});

it('treats a missing google translation as no candidates', function () {
    $provider = configureGoogleTestProvider();

    Http::fake(['google.test/*' => Http::response(['data' => ['translations' => []]])]);

    expect($provider->fetch('cat', 'en', 'ru'))->toBe([]);
});

it('prefers yandex and never calls google when yandex answers', function () {
    configureYandexTestProvider();
    configureGoogleTestProvider();

    Http::fake([
        'yandex.test/*' => Http::response(['def' => [['pos' => 'noun', 'tr' => [['text' => 'кот']]]]]),
        'google.test/*' => Http::response(['data' => ['translations' => [['translatedText' => 'should-not-be-used']]]]),
    ]);

    $result = (new WordTranslationResolver)->fetch('cat', 'en', 'ru');

    expect($result['provider'])->toBe('yandex')
        ->and($result['candidates'])->toBe([['text' => 'кот', 'pos' => 'noun']]);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'google.test'));
});

it('falls back to google when yandex has no dictionary entry', function () {
    configureYandexTestProvider();
    configureGoogleTestProvider();

    Http::fake([
        'yandex.test/*' => Http::response(['def' => []]),
        'google.test/*' => Http::response(['data' => ['translations' => [['translatedText' => 'кот']]]]),
    ]);

    $result = (new WordTranslationResolver)->fetch('cat', 'en', 'ru');

    expect($result['provider'])->toBe('google')
        ->and($result['candidates'])->toBe([['text' => 'кот', 'pos' => null]]);
});

it('falls back to google when yandex errors', function () {
    configureYandexTestProvider();
    configureGoogleTestProvider();

    Http::fake([
        'yandex.test/*' => Http::response(['message' => 'boom'], 500),
        'google.test/*' => Http::response(['data' => ['translations' => [['translatedText' => 'кот']]]]),
    ]);

    $result = (new WordTranslationResolver)->fetch('cat', 'en', 'ru');

    expect($result['provider'])->toBe('google')
        ->and($result['candidates'])->toBe([['text' => 'кот', 'pos' => null]]);
});

it('throws when a provider fails and none can answer', function () {
    configureYandexTestProvider();
    configureGoogleTestProvider();

    Http::fake([
        'yandex.test/*' => Http::response(['message' => 'boom'], 500),
        'google.test/*' => Http::response(['message' => 'boom'], 503),
    ]);

    expect(fn () => (new WordTranslationResolver)->fetch('cat', 'en', 'ru'))->toThrow(RuntimeException::class);
});
