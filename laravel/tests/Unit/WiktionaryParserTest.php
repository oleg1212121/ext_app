<?php

use App\Classes\WiktionaryParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    createLanguages();
});

it('parses a single valid JSONL line', function () {
    $parser = new WiktionaryParser('en', 'ru');
    $line = json_decode('{"word":"hello","pos":"noun","senses":[{"glosses":["A greeting"]}],"translations":[{"code":"ru","word":"привет"}],"forms":[{"form":"hellos"}],"sounds":[{"ipa":"/həˈloʊ/"}],"etymology_text":"From Old English"}');

    $result = $parser->parseLine($line);

    expect($result)->not->toBeNull();
    expect($result['word'])->toBe('hello');
    expect($result['pos'])->toBe('noun');
    expect($result['definitions'])->toContain(['text' => 'A greeting', 'labels' => []]);
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

it('strips stress marks from the ru display word and the lookup key', function () {
    $parser = new WiktionaryParser('ru', 'en');
    $line = json_decode('{"word":"свобо́дный","pos":"adjective","senses":[{"glosses":["free"]}]}');

    $result = $parser->parseLine($line);

    expect($result['word'])->toBe('свободный');
    expect($result['l_word'])->toBe('свободный');
});

it('keeps non-stress-mark display words verbatim', function () {
    $parser = new WiktionaryParser('en', 'ru');
    $line = json_decode('{"word":"café","pos":"noun"}');

    $result = $parser->parseLine($line);

    // Diacritics are legitimate spelling outside the stress-mark languages.
    expect($result['word'])->toBe('café');
});

it('rejects a ru headword that strips to nothing', function () {
    $parser = new WiktionaryParser('ru', 'en');
    $line = json_decode('{"word":"'."\u{0301}".'","pos":"noun"}');

    expect($parser->parseLine($line))->toBeNull();
});

it('maps the raw kaikki pos through the shared alias table', function () {
    $parser = new WiktionaryParser('en', 'ru');
    $adj = $parser->parseLine(json_decode('{"word":"quick","pos":"adj"}'));

    expect($adj['pos'])->toBe('adjective');
    expect($parser->parseLine(json_decode('{"word":"murky","pos":"unknown"}'))['pos'])->toBe('unknown');
    expect($parser->parseLine(json_decode('{"word":"quox","pos":"unsupported_pos"}'))['pos'])->toBe('unsupported_pos');
});

it('skips romanization-tagged forms', function () {
    $parser = new WiktionaryParser('ru', 'en');
    $line = json_decode('{"word":"четвереньках","pos":"noun","forms":['
        .'{"form":"četverénʹkax","tags":["romanization"]},'
        .'{"form":"четвереньки","tags":["genitive"]},'
        .'{"form":"доме"}]}');

    $result = $parser->parseLine($line);

    // The Latin transliteration never lands; other-tagged and untagged
    // forms pass through untouched.
    expect($result['forms'])->toBe(['четвереньки', 'доме']);
});

it('merges records for same word and pos', function () {
    $parser = new WiktionaryParser('en', 'ru');
    $existing = [
        'word' => 'run',
        'l_word' => 'run',
        'pos' => 'noun',
        'definitions' => [['text' => 'A physical activity', 'labels' => []]],
        'forms' => ['runs'],
        'sounds' => [['value' => '/rʌn/', 'type' => 'ipa']],
        'etymology' => 'Old English',
        'translations' => ['бег'],
    ];
    $incoming = [
        'word' => 'run',
        'l_word' => 'run',
        'pos' => 'noun',
        'definitions' => [['text' => 'Another definition', 'labels' => ['rare']]],
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
        ['word' => 'cat', 'word_class_id' => 1],
        ['word' => 'cat', 'word_class_id' => 1],
        ['word' => 'dog', 'word_class_id' => 1],
    ];

    $parser->uniqueByCompound($rows, ['word', 'word_class_id']);
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
    expect($result['definitions'][0]['text'])->toBe('raw def');
});

it('maps sense tags onto usage labels on both gloss paths, dropping grammar noise', function () {
    $parser = new WiktionaryParser('en', 'ru');
    $line = json_decode('{"word":"knave","pos":"noun","senses":['
        .'{"raw_glosses":["a dishonest man"],"tags":["archaic","masculine"]},'
        .'{"glosses":["a naughty child"],"tags":["dated","colloquial","singular"]},'
        .'{"glosses":["any given card in a deck"]},'
        .'{"glosses":["obsolete spelling of knafe"],"tags":["obsolete","form-of"]}]}');

    $result = $parser->parseLine($line);

    // The closed set survives in the dump's own order; the grammar/gender
    // tags (masculine, singular, form-of) drop; an untagged sense stores
    // unlabelled. Both gloss paths label.
    expect($result['definitions'][0])->toBe(['text' => 'a dishonest man', 'labels' => ['archaic']])
        ->and($result['definitions'][1]['labels'])->toBe(['dated', 'colloquial'])
        ->and($result['definitions'][2])->toBe(['text' => 'any given card in a deck', 'labels' => []])
        ->and($result['definitions'][3]['labels'])->toBe(['obsolete']);
});

it('dedupes same-text definitions keeping the first sense\'s labels', function () {
    $parser = new WiktionaryParser('en', 'ru');
    $line = json_decode('{"word":"awe","pos":"noun","senses":['
        .'{"glosses":["A feeling of fear and reverence"],"tags":["rare"]},'
        .'{"glosses":["A feeling of fear and reverence"],"tags":["obsolete"]}]}');

    $result = $parser->parseLine($line);

    // One gloss text, one row: the first occurrence (and its tags) stands.
    expect($result['definitions'])->toBe([['text' => 'A feeling of fear and reverence', 'labels' => ['rare']]]);
});

it('merges definitions across records by text, the first labels standing', function () {
    $parser = new WiktionaryParser('en', 'ru');
    $existing = [
        'word' => 'run', 'l_word' => 'run', 'pos' => 'noun', 'relay_base' => null,
        'definitions' => [['text' => 'A stream', 'labels' => []]],
        'forms' => [], 'sounds' => [], 'etymology' => null, 'translations' => [],
    ];
    $incoming = [
        'word' => 'run', 'l_word' => 'run', 'pos' => 'noun', 'relay_base' => null,
        'definitions' => [['text' => 'A stream', 'labels' => ['dialectal']], ['text' => 'A race', 'labels' => ['rare']]],
        'forms' => [], 'sounds' => [], 'etymology' => null, 'translations' => [],
    ];

    $merged = $parser->mergeRecord($existing, $incoming);

    expect($merged['definitions'])->toBe([
        ['text' => 'A stream', 'labels' => []],
        ['text' => 'A race', 'labels' => ['rare']],
    ]);
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

it('stores a marked ru staged candidate plain', function () {
    $parser = new WiktionaryParser('en', 'ru');
    // Build JSON with actual combining acute accent (U+0301) embedded in the word
    $jsonStr = '{"word":"house","pos":"noun","senses":[{"glosses":["building"],"translations":[{"code":"ru","word":"до'."\u{0301}".'м"}]}]}';
    $line = json_decode($jsonStr);

    // Staged candidates sit in words.translations verbatim until a link
    // pass converts them — so the strip happens at staging time (spec
    // rule 1: staged candidates never carry combining marks).
    expect($parser->extractTranslations($line))->toBe(['дом']);
});

it('stages nothing for a ru candidate that is nothing but combining marks', function () {
    $parser = new WiktionaryParser('en', 'ru');
    $line = json_decode('{"word":"house","pos":"noun","translations":[{"code":"ru","word":"'."\u{0301}".'"}]}');

    expect($parser->extractTranslations($line))->toBe([]);
});

it('keeps a non-stress-mark target candidate verbatim', function () {
    $parser = new WiktionaryParser('en', ['ru', 'de']);
    $line = json_decode('{"word":"house","pos":"noun","translations":[{"code":"de","word":"Gebäude"},{"code":"ru","word":"до'."\u{0301}".'м"}]}');

    // Diacritics outside the StressMarks languages are legitimate spelling;
    // the ru candidate strips anyway.
    expect($parser->extractTranslations($line))->toBe(['Gebäude', 'дом']);
});

it('accepts multiple target languages for staged translations', function () {
    $parser = new WiktionaryParser('en', ['ru', 'de']);
    $line = json_decode('{"word":"test","pos":"noun","translations":[{"code":"ru","word":"тест"},{"code":"de","word":"Test"},{"code":"fr","word":"essai"}]}');

    $translations = $parser->extractTranslations($line);
    expect($translations)->toContain('тест');
    expect($translations)->toContain('Test');
    expect($translations)->not->toContain('essai');
    expect($translations)->toHaveCount(2);
});

it('rejects an empty target language list', function () {
    new WiktionaryParser('en', []);
})->throws(InvalidArgumentException::class);

it('rejects the source language as a target', function () {
    new WiktionaryParser('en', ['ru', 'en']);
})->throws(InvalidArgumentException::class);

function relayParserLine(): object
{
    // The real dump shape of the четвереньках relay entry: one sense
    // carrying form_of + the form-of tag, plus the usual content keys a
    // relay must NOT import.
    $base = 'четвере'."\u{0301}".'ньки';

    return json_decode('{"word":"четвереньках","pos":"noun",'
        .'"forms":[{"form":"četverénʹkax","tags":["romanization"]}],'
        .'"sounds":[{"ipa":"[t͡ɕɪtvʲɪˈrʲenʲkəx]"}],'
        .'"senses":[{"glosses":["prepositional of '.$base.' (četverénʹki)"],"tags":["form-of","prepositional"],'
        .'"form_of":[{"word":"'.$base.'","extra":"četverénʹki"}]}],'
        .'"etymology_text":"From четвере'."\u{0301}".'ньки.",'
        .'"translations":[{"code":"en","word":"all fours"}]}');
}

function relayRecord(string $word, string $base): array
{
    return [
        'word' => $word,
        'l_word' => mb_strtolower($word),
        'pos' => 'noun',
        'relay_base' => $base,
        'definitions' => [],
        'forms' => [],
        'sounds' => [],
        'etymology' => null,
        'translations' => [],
    ];
}

function realRecord(string $word): array
{
    return [
        'word' => $word,
        'l_word' => mb_strtolower($word),
        'pos' => 'noun',
        'relay_base' => null,
        'definitions' => [['text' => 'A real gloss', 'labels' => []]],
        'forms' => [],
        'sounds' => [],
        'etymology' => null,
        'translations' => [],
    ];
}

it('marks a relay entry for demotion to a form of its base and carries no content', function () {
    $parser = new WiktionaryParser('ru', 'en');

    $result = $parser->parseLine(relayParserLine());

    expect($result['relay_base'])->toBe('четвереньки')
        ->and($result['definitions'])->toBe([])
        ->and($result['forms'])->toBe([])
        ->and($result['sounds'])->toBe([])
        ->and($result['translations'])->toBe([])
        ->and($result['etymology'])->toBeNull()
        ->and($result['word'])->toBe('четвереньках')
        ->and($result['l_word'])->toBe('четвереньках');
});

it('keeps a mixed entry a word with its real definitions', function () {
    $parser = new WiktionaryParser('en', 'ru');
    $line = json_decode('{"word":"run","pos":"noun","senses":['
        .'{"tags":["form-of"],"form_of":[{"word":"run"}]},'
        .'{"glosses":["An act of running"]}]}');

    $result = $parser->parseLine($line);

    expect($result['relay_base'])->toBeNull()
        ->and($result['definitions'])->toBe([['text' => 'An act of running', 'labels' => []]]);
});

it('keeps an en relay base verbatim where no strip applies', function () {
    $parser = new WiktionaryParser('en', 'ru');
    $line = json_decode('{"word":"cats","pos":"noun","senses":[{"glosses":["plural of cat"],'
        .'"tags":["form-of","plural"],"form_of":[{"word":"cat"}]}]}');

    expect($parser->parseLine($line)['relay_base'])->toBe('cat');
});

it('merges a relay line with a real line into a word (union of senses is mixed)', function () {
    $parser = new WiktionaryParser('en', 'ru');

    // Either order: the mixed union stays a word.
    $relayThenReal = $parser->mergeRecord(relayRecord('cats', 'cat'), realRecord('cats'));
    $realThenRelay = $parser->mergeRecord(realRecord('cats'), relayRecord('cats', 'cat'));

    expect($relayThenReal['relay_base'])->toBeNull()
        ->and($relayThenReal['definitions'])->toBe([['text' => 'A real gloss', 'labels' => []]])
        ->and($realThenRelay['relay_base'])->toBeNull()
        ->and($realThenRelay['definitions'])->toBe([['text' => 'A real gloss', 'labels' => []]]);
});

it('merges two relay lines of one base into a single demotion', function () {
    $parser = new WiktionaryParser('en', 'ru');

    $merged = $parser->mergeRecord(relayRecord('cats', 'cat'), relayRecord('cats', 'Cat'));

    expect($merged['relay_base'])->toBe('cat');
});

it('keeps two relay lines with disagreeing bases a word', function () {
    $parser = new WiktionaryParser('en', 'ru');

    $merged = $parser->mergeRecord(relayRecord('cats', 'cat'), relayRecord('cats', 'Cat'));

    $disagreeing = $parser->mergeRecord($merged, relayRecord('cats', 'dog'));
    expect($disagreeing['relay_base'])->toBeNull();
});
