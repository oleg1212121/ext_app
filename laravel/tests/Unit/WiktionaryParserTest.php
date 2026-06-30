<?php

use App\Classes\WiktionaryParser;

it('parses a single valid JSONL line', function () {
    $parser = new WiktionaryParser('en', 'ru');
    $line = json_decode('{"word":"hello","pos":"noun","senses":[{"glosses":["A greeting"]}],"translations":[{"code":"ru","word":"привет"}],"forms":[{"form":"hellos"}],"sounds":[{"ipa":"/həˈloʊ/"}],"etymology_text":"From Old English"}');

    $result = $parser->parseLine($line);

    expect($result)->not->toBeNull();
    expect($result['word'])->toBe('hello');
    expect($result['pos'])->toBe('noun');
    expect($result['definitions'])->toContain('A greeting');
    expect($result['translations'])->toContain('привет');
    expect($result['forms'])->toContain('hellos');
    expect($result['sounds'])->toHaveCount(1);
    expect($result['sounds'][0])->toBe(['value' => '/həˈloʊ/', 'type' => 'ipa']);
    expect($result['etymology'])->toBe('From Old English');
});

it('returns null for line without word', function () {
    $parser = new WiktionaryParser('en', 'ru');
    $line = json_decode('{"pos":"noun","senses":[]}');

    $result = $parser->parseLine($line);
    expect($result)->toBeNull();
});

it('handles missing pos with unknown default', function () {
    $parser = new WiktionaryParser('en', 'ru');
    $line = json_decode('{"word":"test"}');

    $result = $parser->parseLine($line);
    expect($result['pos'])->toBe('unknown');
});

it('merges records for same word and pos', function () {
    $parser = new WiktionaryParser('en', 'ru');
    $existing = [
        'word' => 'run',
        'l_word' => 'run',
        'pos' => 'noun',
        'definitions' => ['A physical activity'],
        'forms' => ['runs'],
        'sounds' => [['value' => '/rʌn/', 'type' => 'ipa']],
        'etymology' => 'Old English',
        'translations' => ['бег'],
    ];
    $incoming = [
        'word' => 'run',
        'l_word' => 'run',
        'pos' => 'noun',
        'definitions' => ['Another definition'],
        'forms' => ['running'],
        'sounds' => [],
        'etymology' => null,
        'translations' => ['пробежка'],
    ];

    $merged = $parser->mergeRecord($existing, $incoming);

    expect($merged['definitions'])->toHaveCount(2);
    expect($merged['forms'])->toHaveCount(2);
    expect($merged['translations'])->toHaveCount(2);
    expect($merged['etymology'])->toBe('Old English');
});

it('uniqueByCompound removes duplicates', function () {
    $parser = new WiktionaryParser('en', 'ru');
    $rows = [
        ['word' => 'cat', 'en_word_class_id' => 1],
        ['word' => 'cat', 'en_word_class_id' => 1],
        ['word' => 'dog', 'en_word_class_id' => 1],
    ];

    $parser->uniqueByCompound($rows, ['word', 'en_word_class_id']);
    expect($rows)->toHaveCount(2);
});

it('parses sounds with enpr type', function () {
    $parser = new WiktionaryParser('en', 'ru');
    $line = json_decode('{"word":"hello","pos":"noun","sounds":[{"enpr":"hə-LOH"}]}');

    $result = $parser->parseLine($line);
    expect($result['sounds'])->toHaveCount(1);
    expect($result['sounds'][0]['type'])->toBe('enpr');
});

it('prioritizes raw_glosses over glosses', function () {
    $parser = new WiktionaryParser('en', 'ru');
    $line = json_decode('{"word":"test","pos":"noun","senses":[{"raw_glosses":["raw def"],"glosses":["gloss def"]}]}');

    $result = $parser->parseLine($line);
    expect($result['definitions'][0])->toBe('raw def');
});

it('throws on unsupported source language', function () {
    new WiktionaryParser('xx', 'ru');
})->throws(InvalidArgumentException::class);

it('skips empty forms and sounds', function () {
    $parser = new WiktionaryParser('en', 'ru');
    $line = json_decode('{"word":"test","pos":"noun","forms":[{"form":""},{"form":"tests"}],"sounds":[{"ipa":""},{"ipa":"/tɛst/"}]}');

    $result = $parser->parseLine($line);
    expect($result['forms'])->toHaveCount(1);
    expect($result['forms'][0])->toBe('tests');
    expect($result['sounds'])->toHaveCount(1);
});

it('collects translations from both senses and top-level', function () {
    $parser = new WiktionaryParser('en', 'ru');
    $line = json_decode('{"word":"test","pos":"noun","senses":[{"glosses":["def"],"translations":[{"code":"ru","word":"тест1"}]}],"translations":[{"code":"ru","word":"тест2"},{"code":"fr","word":"test2"}]}');

    $result = $parser->parseLine($line);
    expect($result['translations'])->toContain('тест1');
    expect($result['translations'])->toContain('тест2');
    expect($result['translations'])->toHaveCount(2);
});

it('extracts translations via extractTranslations method', function () {
    $parser = new WiktionaryParser('en', 'ru');
    $line = json_decode('{"word":"test","pos":"noun","senses":[{"glosses":["def"],"translations":[{"code":"ru","word":"тест1"},{"code":"fr","word":"essai"}]}],"translations":[{"code":"ru","word":"тест2"}]}');

    $translations = $parser->extractTranslations($line);
    expect($translations)->toContain('тест1');
    expect($translations)->toContain('тест2');
    expect($translations)->not->toContain('essai');
    expect($translations)->toHaveCount(2);
});

it('extracts translations with stress marks preserved', function () {
    $parser = new WiktionaryParser('en', 'ru');
    // Build JSON with actual combining acute accent (U+0301) embedded in the word
    $jsonStr = '{"word":"house","pos":"noun","senses":[{"glosses":["building"],"translations":[{"code":"ru","word":"до'."\u{0301}".'м"}]}]}';
    $line = json_decode($jsonStr);

    $translations = $parser->extractTranslations($line);
    expect($translations)->toHaveCount(1);
    // Verify the translation contains the combining mark (U+0301)
    expect(preg_match('/\x{0301}/u', $translations[0]))->toBe(1);
});
