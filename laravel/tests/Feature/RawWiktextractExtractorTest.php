<?php

use App\Classes\RawWiktextractExtractor;

function rawDumpBase(string $gzPath): string
{
    $base = str_ends_with($gzPath, '.gz') ? substr($gzPath, 0, -3) : $gzPath;

    return str_ends_with($base, '.jsonl') ? substr($base, 0, -6) : $base;
}

function makeRawDumpFixture(string $path, array $lines): void
{
    file_put_contents($path, str_ends_with($path, '.gz') ? gzencode(implode("\n", $lines)."\n") : implode("\n", $lines)."\n");
}

function cleanupRawDump(string $gzPath, array $codes): void
{
    @unlink($gzPath);
    foreach ($codes as $code) {
        @unlink(rawDumpBase($gzPath).'.'.$code.'.jsonl');
    }
}

it('keeps only raw lines of the wanted languages and skips the rest without decoding', function () {
    $gzPath = sys_get_temp_dir().'/raw-extractor-'.uniqid().'.jsonl.gz';

    $enLine = '{"word": "cat", "pos": "noun", "lang": "English", "lang_code": "en", "senses": [{"glosses": ["feline"]}]}';
    $ruLine = '{"word": "кошка", "pos": "noun", "lang": "Russian", "lang_code": "ru"}';
    $frLine = '{"word": "chien", "pos": "noun", "lang": "French", "lang_code": "fr"}';
    $noLangLine = '{"word": "unknownorigin", "pos": "noun"}';
    // Needle "lang_code": "en" appears nested, but top-level lang_code is fr —
    // must be skipped, not written into the en extract.
    $falsePositiveLine = '{"word": "plant", "pos": "noun", "lang": "French", "lang_code": "fr", "etymology_templates": [{"name": "root", "args": {"lang_code": "en"}}]}';

    makeRawDumpFixture($gzPath, [$enLine, $ruLine, $frLine, $noLangLine, $falsePositiveLine, '']);

    $stats = (new RawWiktextractExtractor)->extract($gzPath, ['en', 'ru']);

    expect($stats['lines_read'])->toBe(6);
    expect($stats['kept']['en'])->toBe(1);
    expect($stats['kept']['ru'])->toBe(1);
    expect($stats['truncated'])->toBeFalse();

    expect(file_get_contents(rawDumpBase($gzPath).'.en.jsonl'))->toBe($enLine."\n");
    expect(file_get_contents(rawDumpBase($gzPath).'.ru.jsonl'))->toBe($ruLine."\n");

    cleanupRawDump($gzPath, ['en', 'ru']);
});

it('keeps a wanted line even when a nested lang_code references another language', function () {
    $gzPath = sys_get_temp_dir().'/raw-extractor-'.uniqid().'.jsonl.gz';

    $enLine = '{"word": "night", "pos": "noun", "lang": "English", "lang_code": "en", "etymology_templates": [{"name": "cog", "args": {"lang_code": "ru"}}]}';
    makeRawDumpFixture($gzPath, [$enLine]);

    $stats = (new RawWiktextractExtractor)->extract($gzPath, ['en', 'ru']);

    expect($stats['kept']['en'])->toBe(1);
    expect($stats['kept']['ru'])->toBe(0);
    expect(file_get_contents(rawDumpBase($gzPath).'.en.jsonl'))->toBe($enLine."\n");

    cleanupRawDump($gzPath, ['en', 'ru']);
});

it('supports compact json separators without spaces', function () {
    $gzPath = sys_get_temp_dir().'/raw-extractor-'.uniqid().'.jsonl.gz';

    $ruLine = '{"word":"кот","pos":"noun","lang":"Russian","lang_code":"ru"}';
    makeRawDumpFixture($gzPath, [$ruLine]);

    $stats = (new RawWiktextractExtractor)->extract($gzPath, ['ru']);

    expect($stats['kept']['ru'])->toBe(1);
    expect(file_get_contents(rawDumpBase($gzPath).'.ru.jsonl'))->toBe($ruLine."\n");

    cleanupRawDump($gzPath, ['ru']);
});

it('stops reading at max-lines', function () {
    $gzPath = sys_get_temp_dir().'/raw-extractor-'.uniqid().'.jsonl.gz';

    $enLine = '{"word": "cat", "pos": "noun", "lang": "English", "lang_code": "en"}';
    $ruLine = '{"word": "кошка", "pos": "noun", "lang": "Russian", "lang_code": "ru"}';
    makeRawDumpFixture($gzPath, [$enLine, $ruLine]);

    $stats = (new RawWiktextractExtractor)->extract($gzPath, ['en', 'ru'], null, 1);

    expect($stats['truncated'])->toBeTrue();
    expect($stats['lines_read'])->toBe(1);
    expect($stats['kept']['ru'])->toBe(0);

    cleanupRawDump($gzPath, ['en', 'ru']);
});

it('reads plain jsonl without a gz suffix', function () {
    $gzPath = sys_get_temp_dir().'/raw-extractor-'.uniqid().'.jsonl';

    $enLine = '{"word": "cat", "pos": "noun", "lang": "English", "lang_code": "en"}';
    makeRawDumpFixture($gzPath, [$enLine]);

    $stats = (new RawWiktextractExtractor)->extract($gzPath, ['en']);

    expect($stats['kept']['en'])->toBe(1);
    expect(file_get_contents(rawDumpBase($gzPath).'.en.jsonl'))->toBe($enLine."\n");

    cleanupRawDump($gzPath, ['en']);
});

it('throws on a missing file', function () {
    (new RawWiktextractExtractor)->extract('/nonexistent/dump.jsonl.gz', ['en']);
})->throws(InvalidArgumentException::class);
