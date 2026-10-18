<?php

use App\Classes\WiktionaryParser;
use App\Models\Definition;
use App\Models\Form;
use App\Models\Language;
use App\Models\Transcription;
use App\Models\TranscriptionType;
use App\Models\Word;
use App\Models\WordClass;

beforeEach(function () {
    $languages = createLanguages();

    WordClass::create(['language_id' => $languages['en']->id, 'slug' => 'noun', 'title' => 'Noun', 'description' => 'Test noun']);
    WordClass::create(['language_id' => $languages['en']->id, 'slug' => 'verb', 'title' => 'Verb', 'description' => 'Test verb']);
    WordClass::create(['language_id' => $languages['en']->id, 'slug' => 'unknown', 'title' => 'Unknown', 'description' => 'Unknown POS']);
    WordClass::create(['language_id' => $languages['ru']->id, 'slug' => 'noun', 'title' => 'Существительное', 'description' => 'Тест']);
    WordClass::create(['language_id' => $languages['ru']->id, 'slug' => 'verb', 'title' => 'Глагол', 'description' => 'Тест']);
    WordClass::create(['language_id' => $languages['ru']->id, 'slug' => 'unknown', 'title' => 'Неизвестно', 'description' => 'Тест']);
    TranscriptionType::create(['language_id' => $languages['en']->id, 'slug' => 'ipa', 'title' => 'IPA', 'description' => 'Test']);
    TranscriptionType::create(['language_id' => $languages['en']->id, 'slug' => 'enpr', 'title' => 'English Pronunciation', 'description' => 'Test']);
});

it('imports a file and creates database records', function () {
    $enLanguageId = createLanguages()['en']->id;
    $tmpFile = tempnam(sys_get_temp_dir(), 'wiktionary_test_');
    $lines = [
        json_encode(['word' => 'cat', 'pos' => 'noun', 'senses' => [['glosses' => ['A small domesticated feline']]], 'translations' => [['code' => 'ru', 'word' => 'кошка']]]),
        json_encode(['word' => 'cat', 'pos' => 'noun', 'forms' => [['form' => 'cats']]]),
        json_encode(['word' => 'dog', 'pos' => 'noun', 'senses' => [['glosses' => ['A domesticated canine']]], 'translations' => [['code' => 'ru', 'word' => 'собака']]]),
    ];
    file_put_contents($tmpFile, implode("\n", $lines)."\n");

    $parser = new WiktionaryParser('en', 'ru', 100);
    $stats = $parser->import($tmpFile);

    expect($stats['words_imported'])->toBeGreaterThan(0);
    expect(Word::where('word', 'cat')->where('language_id', $enLanguageId)->count())->toBe(1);
    expect(Word::where('word', 'dog')->where('language_id', $enLanguageId)->count())->toBe(1);
    expect(Definition::count())->toBeGreaterThan(0);
    expect(Form::where('form', 'cats')->count())->toBe(1);

    $cat = Word::where('word', 'cat')->where('language_id', $enLanguageId)->first();
    expect($cat->translations)->toBeArray();
    expect($cat->translations)->toContain('кошка');

    $dog = Word::where('word', 'dog')->where('language_id', $enLanguageId)->first();
    expect($dog->translations)->toBeArray();
    expect($dog->translations)->toContain('собака');

    unlink($tmpFile);
});

it('creates a word class for an unseen pos and imports the word', function () {
    $enLanguageId = createLanguages()['en']->id;
    $tmpFile = tempnam(sys_get_temp_dir(), 'wiktionary_test_');
    $lines = [
        json_encode(['word' => 'test', 'pos' => 'unsupported_pos', 'senses' => [['glosses' => ['A test']]]]),
    ];
    file_put_contents($tmpFile, implode("\n", $lines)."\n");

    $parser = new WiktionaryParser('en', 'ru', 100);
    $stats = $parser->import($tmpFile);

    expect($stats['words_imported'])->toBe(1);
    $word = Word::where('word', 'test')->where('language_id', $enLanguageId)->first();
    expect($word)->not->toBeNull();
    expect($word->wordClass->slug)->toBe('unsupported_pos');
    expect(Definition::count())->toBe(1);

    $createdClass = WordClass::where('language_id', $enLanguageId)->where('slug', 'unsupported_pos')->first();
    expect($createdClass)->not->toBeNull();
    expect($createdClass->title)->toBe('unsupported_pos');

    unlink($tmpFile);
});

it('bootstraps a fresh language with placeholder lookups', function () {
    $de = Language::create(['code' => 'de', 'name' => 'German', 'is_enabled' => false, 'sort_order' => 5]);
    $tmpFile = tempnam(sys_get_temp_dir(), 'wiktionary_test_');
    $lines = [
        json_encode(['word' => 'Hund', 'pos' => 'noun', 'sounds' => [['ipa' => '/hʊnt/']], 'senses' => [['glosses' => ['A dog']]]]),
    ];
    file_put_contents($tmpFile, implode("\n", $lines)."\n");

    $parser = new WiktionaryParser('de', 'en', 100);
    $stats = $parser->import($tmpFile);

    expect($stats['words_imported'])->toBe(1);
    expect($stats['lookups_created'])->toBe(3); // unknown + noun word classes, ipa transcription type

    expect(WordClass::where('language_id', $de->id)->where('slug', 'unknown')->where('title', 'unknown')->exists())->toBeTrue();
    expect(WordClass::where('language_id', $de->id)->where('slug', 'noun')->where('title', 'noun')->exists())->toBeTrue();
    expect(TranscriptionType::where('language_id', $de->id)->where('slug', 'ipa')->where('title', 'ipa')->exists())->toBeTrue();

    $word = Word::where('word', 'Hund')->where('language_id', $de->id)->first();
    expect($word)->not->toBeNull();
    expect($word->transcriptions)->toHaveCount(1);
    expect($word->transcriptions->first()->transcription)->toBe('/hʊnt/');

    unlink($tmpFile);
});

it('throws on missing file', function () {
    $parser = new WiktionaryParser('en', 'ru');
    $parser->import('/nonexistent/file.jsonl');
})->throws(InvalidArgumentException::class);

it('reads gzipped jsonl files transparently', function () {
    $enLanguageId = createLanguages()['en']->id;
    $tmpFile = tempnam(sys_get_temp_dir(), 'wiktionary_gz_').'.jsonl.gz';
    $lines = [
        json_encode(['word' => 'cat', 'pos' => 'noun', 'senses' => [['glosses' => ['A small domesticated feline']]]]),
        json_encode(['word' => 'dog', 'pos' => 'noun', 'senses' => [['glosses' => ['A domesticated canine']]]]),
    ];
    file_put_contents($tmpFile, gzencode(implode("\n", $lines)."\n"));

    $parser = new WiktionaryParser('en', 'ru', 100);
    $stats = $parser->import($tmpFile);

    expect($stats['lines_read'])->toBe(2);
    expect($stats['words_imported'])->toBe(2);
    expect(Word::where('word', 'cat')->where('language_id', $enLanguageId)->count())->toBe(1);
    expect(Word::where('word', 'dog')->where('language_id', $enLanguageId)->count())->toBe(1);

    unlink($tmpFile);
});

it('stages translations for multiple target languages', function () {
    $enLanguageId = createLanguages()['en']->id;
    $tmpFile = tempnam(sys_get_temp_dir(), 'wiktionary_multi_');
    $lines = [
        json_encode(['word' => 'cat', 'pos' => 'noun', 'translations' => [
            ['code' => 'ru', 'word' => 'кошка'],
            ['code' => 'de', 'word' => 'Katze'],
            ['code' => 'fr', 'word' => 'chat'],
        ]]),
    ];
    file_put_contents($tmpFile, implode("\n", $lines)."\n");

    $parser = new WiktionaryParser('en', ['ru', 'de'], 100);
    $stats = $parser->import($tmpFile);

    expect($stats['words_imported'])->toBe(1);

    $cat = Word::where('word', 'cat')->where('language_id', $enLanguageId)->first();
    expect($cat->translations)->toBeArray();
    expect($cat->translations)->toContain('кошка');
    expect($cat->translations)->toContain('Katze');
    expect($cat->translations)->not->toContain('chat');
    expect($cat->translations)->toHaveCount(2);

    unlink($tmpFile);
});

it('strips stress marks from ru display words and forms while keeping en forms verbatim', function () {
    $languages = createLanguages();

    $ruFile = tempnam(sys_get_temp_dir(), 'wiktionary_ru_');
    file_put_contents($ruFile, json_encode(['word' => 'дом', 'pos' => 'noun', 'forms' => [
        ['form' => 'до́ма'],
        ['form' => 'доме́'],
    ]])."\n");
    (new WiktionaryParser('ru', 'en', 100))->import($ruFile);

    // The marks leave the stored display words AND the headword (the ADR
    // 0078 kaikki carve-out is reversed), which now also stores itself as
    // the lemma form: no stressed twin rows, the lookup keys were stripped
    // all along.
    $dom = Word::where('word', 'дом')->where('language_id', $languages['ru']->id)->firstOrFail();
    expect($dom->forms->pluck('form')->sort()->values()->all())->toBe(['дом', 'дома', 'доме'])
        ->and($dom->forms->pluck('l_word')->sort()->values()->all())->toBe(['дом', 'дома', 'доме']);
    unlink($ruFile);

    $enFile = tempnam(sys_get_temp_dir(), 'wiktionary_en_');
    file_put_contents($enFile, json_encode(['word' => 'кот', 'pos' => 'noun', 'forms' => [
        ['form' => 'cats'],
    ]])."\n");
    (new WiktionaryParser('en', 'ru', 100))->import($enFile);

    // Non-stress-mark languages keep their words and forms exactly as the
    // dump has them — plus the lemma form every word now carries.
    $kot = Word::where('word', 'кот')->where('language_id', $languages['en']->id)->firstOrFail();
    expect($kot->forms->pluck('form')->sort()->values()->all())->toBe(['cats', 'кот']);
    unlink($enFile);
});

it('stores a stressed ru headword plain while its definition keeps its marks', function () {
    $languages = createLanguages();

    $tmpFile = tempnam(sys_get_temp_dir(), 'wiktionary_ru_');
    file_put_contents($tmpFile, json_encode([
        'word' => 'свобо́дный',
        'pos' => 'adjective',
        'senses' => [['glosses' => ['свобо́дный человек — тот, кто не в неволе']]],
    ])."\n");
    (new WiktionaryParser('ru', 'en', 100))->import($tmpFile);

    $word = Word::where('l_word', 'свободный')->where('language_id', $languages['ru']->id)->firstOrFail();
    expect($word->word)->toBe('свободный')
        ->and($word->l_word)->toBe('свободный')
        // Definition prose keeps its marks — only the headword string strips.
        ->and($word->definitions->first()->definition)->toContain('свобо́дный')
        // The stripped headword stores itself as the lemma form.
        ->and($word->forms->pluck('form')->all())->toBe(['свободный']);

    unlink($tmpFile);
});

it('maps raw kaikki pos through the shared alias map into canonical classes', function () {
    $enLanguageId = createLanguages()['en']->id;

    $tmpFile = tempnam(sys_get_temp_dir(), 'wiktionary_adj_');
    file_put_contents($tmpFile, implode("\n", [
        json_encode(['word' => 'quick', 'pos' => 'adj', 'senses' => [['glosses' => ['fast']]]]),
        json_encode(['word' => 'slow', 'pos' => 'adjective', 'senses' => [['glosses' => ['not fast']]]]),
    ])."\n");
    (new WiktionaryParser('en', 'ru', 100))->import($tmpFile);

    // 'adj' canonicalizes into the same class slug 'adjective' carries: one
    // taxonomy, no parallel 'adj' class minted beside it.
    expect(WordClass::where('language_id', $enLanguageId)->where('slug', 'adj')->exists())->toBeFalse()
        ->and(Word::where('word', 'quick')->where('language_id', $enLanguageId)->firstOrFail()->wordClass->slug)->toBe('adjective');

    unlink($tmpFile);
});

it('merges aliased pos lines of one word into a single row', function () {
    $enLanguageId = createLanguages()['en']->id;

    $tmpFile = tempnam(sys_get_temp_dir(), 'wiktionary_alias_');
    file_put_contents($tmpFile, implode("\n", [
        json_encode(['word' => 'quick', 'pos' => 'adj', 'senses' => [['glosses' => ['fast']]]]),
        json_encode(['word' => 'quick', 'pos' => 'adjective', 'senses' => [['glosses' => ['lively']]]]),
    ])."\n");
    (new WiktionaryParser('en', 'ru', 100))->import($tmpFile);

    // The aliased slugs land on one class, so the two lines merge into one
    // word row carrying both senses.
    $quick = Word::where('word', 'quick')->where('language_id', $enLanguageId)->firstOrFail();
    expect(Word::where('word', 'quick')->where('language_id', $enLanguageId)->count())->toBe(1)
        ->and($quick->wordClass->slug)->toBe('adjective')
        ->and($quick->definitions->pluck('definition')->sort()->values()->all())->toBe(['fast', 'lively']);

    unlink($tmpFile);
});

it('skips romanization-tagged forms and keeps the lemma form', function () {
    $languages = createLanguages();

    $tmpFile = tempnam(sys_get_temp_dir(), 'wiktionary_rom_');
    file_put_contents($tmpFile, json_encode(['word' => 'четвереньках', 'pos' => 'noun', 'forms' => [
        ['form' => 'četverénʹkax', 'tags' => ['romanization']],
        ['form' => 'четвереньки', 'tags' => ['genitive']],
    ]])."\n");
    (new WiktionaryParser('ru', 'en', 100))->import($tmpFile);

    $word = Word::where('word', 'четвереньках')->where('language_id', $languages['ru']->id)->firstOrFail();
    $forms = $word->forms->pluck('form')->all();
    expect($forms)->toContain('четвереньках')
        ->and($forms)->toContain('четвереньки')
        ->and($forms)->not->toContain('četverénʹkax');

    unlink($tmpFile);
});

function relayFixtureLines(): array
{
    $base = 'четвере'."\u{0301}".'ньки';

    return [
        // The base lemma entry: a real word with a real gloss.
        json_encode(['word' => 'четвереньки', 'pos' => 'noun', 'senses' => [['glosses' => ['on all fours']]]]),
        // The relay entry, in the dump's own shape: stressed form_of base,
        // form-of tag, plus content a relay must not import.
        json_encode(['word' => 'четвереньках', 'pos' => 'noun',
            'forms' => [
                ['form' => 'четвере'."\u{0301}".'ньках', 'tags' => ['canonical', 'feminine', 'inanimate', 'plural']],
                ['form' => 'četverénʹkax', 'tags' => ['romanization']],
            ],
            'sounds' => [['ipa' => '[t͡ɕɪtvʲɪˈrʲenʲkəx]']],
            'senses' => [['glosses' => ['prepositional of '.$base.' (četverénʹki)'], 'tags' => ['form-of', 'prepositional'],
                'form_of' => [['word' => $base, 'extra' => 'četverénʹki']]]],
        ]),
    ];
}

it('demotes a relay entry to a form of its base word', function () {
    $languages = createLanguages();
    $ruId = $languages['ru']->id;

    $tmpFile = tempnam(sys_get_temp_dir(), 'wiktionary_relay_');
    file_put_contents($tmpFile, implode("\n", relayFixtureLines())."\n");
    $stats = (new WiktionaryParser('ru', 'en', 100))->import($tmpFile);

    // No word row for the relay — and none of its content anywhere.
    expect(Word::where('l_word', 'четвереньках')->where('language_id', $ruId)->count())->toBe(0)
        ->and(Transcription::count())->toBe(0)
        ->and(Definition::where('definition', 'like', '%prepositional of%')->count())->toBe(0);

    // The base imports as a word; the relay headword is its form.
    $base = Word::where('word', 'четвереньки')->where('language_id', $ruId)->firstOrFail();
    expect($base->definitions->pluck('definition')->all())->toBe(['on all fours'])
        ->and($base->forms->pluck('form')->sort()->values()->all())->toBe(['четвереньках', 'четвереньки'])
        ->and(Form::where('form', 'четвереньках')->where('word_id', $base->id)->count())->toBe(1);

    // No stressed twin of the relay form, no romanization.
    expect(Form::count())->toBe(2);

    // Stats count the demotion.
    expect($stats['relays_demoted'])->toBe(1)
        ->and($stats['relay_bases_created'])->toBe(0)
        // The relay is not a word: only the base counts as imported.
        ->and($stats['words_imported'])->toBe(1);

    // Re-import stays idempotent: still one form row on the base.
    (new WiktionaryParser('ru', 'en', 100))->import($tmpFile);
    expect(Form::where('form', 'четвереньках')->where('word_id', $base->id)->count())->toBe(1)
        ->and(Form::count())->toBe(2)
        ->and(Word::where('language_id', $ruId)->count())->toBe(1);

    unlink($tmpFile);
});

it('creates a proxy headword for a relay base the dump never lists as a lemma', function () {
    $languages = createLanguages();
    $ruId = $languages['ru']->id;

    $tmpFile = tempnam(sys_get_temp_dir(), 'wiktionary_relay_orphan_');
    file_put_contents($tmpFile, implode("\n", [relayFixtureLines()[1]])."\n");
    $stats = (new WiktionaryParser('ru', 'en', 100))->import($tmpFile);

    // The base exists by proxy — a dictionary headword, not translation
    // text: plain stripped display, the relay's pos class, no staged
    // translations, and it carries itself as its lemma form.
    $proxy = Word::where('l_word', 'четвереньки')->where('language_id', $ruId)->firstOrFail();
    expect($proxy->word)->toBe('четвереньки')
        ->and($proxy->wordClass->slug)->toBe('noun')
        ->and($proxy->translations)->toBeNull()
        ->and($proxy->definitions)->toHaveCount(0)
        ->and($proxy->forms->pluck('form')->all())->toBe(['четвереньки', 'четвереньках']);

    // The relay headword attaches as the proxy's form.
    expect(Form::where('form', 'четвереньках')->where('word_id', $proxy->id)->count())->toBe(1)
        ->and($stats['relays_demoted'])->toBe(1)
        ->and($stats['relay_bases_created'])->toBe(1)
        // The relay itself is still no word row; the proxy is the only row.
        ->and(Word::where('language_id', $ruId)->count())->toBe(1);

    unlink($tmpFile);
});

it('imports a mixed entry as a word with its real definitions', function () {
    $languages = createLanguages();
    $ruId = $languages['ru']->id;

    $tmpFile = tempnam(sys_get_temp_dir(), 'wiktionary_mixed_');
    file_put_contents($tmpFile, implode("\n", [
        json_encode(['word' => 'бегом', 'pos' => 'adverb', 'senses' => [
            ['glosses' => ['running, at a run (of motion)']],
            ['glosses' => ['instrumental of бег'], 'tags' => ['form-of', 'instrumental'],
                'form_of' => [['word' => 'бег']]],
        ]]),
    ])."\n");
    $stats = (new WiktionaryParser('ru', 'en', 100))->import($tmpFile);

    $word = Word::where('word', 'бегом')->where('language_id', $ruId)->firstOrFail();
    // A mixed entry stays a word and keeps the definitions extraction it
    // always had — real gloss AND the relay-sense gloss (definitions were
    // explicitly left alone by the spec); only pure relays demote.
    expect($word->definitions->pluck('definition')->all())->toBe(['running, at a run (of motion)', 'instrumental of бег'])
        ->and(Word::where('language_id', $ruId)->count())->toBe(1)
        ->and($stats['relays_demoted'])->toBe(0)
        ->and($stats['relay_bases_created'])->toBe(0);

    unlink($tmpFile);
});

it('resolves a relay base by class preference when several rows share the l_word', function () {
    $languages = createLanguages();
    $ruId = $languages['ru']->id;
    $nounClass = WordClass::where('language_id', $ruId)->where('slug', 'noun')->firstOrFail();
    $verbClass = WordClass::where('language_id', $ruId)->where('slug', 'verb')->firstOrFail();

    // Two rows share the base l_word; the relay's noun pos must prefer the
    // noun row over the lower-id verb row.
    $verb = Word::create(['word' => 'четвереньки', 'l_word' => 'четвереньки', 'language_id' => $ruId, 'word_class_id' => $verbClass->id]);
    $noun = Word::create(['word' => 'четвереньки', 'l_word' => 'четвереньки', 'language_id' => $ruId, 'word_class_id' => $nounClass->id]);
    expect($verb->id)->toBeLessThan($noun->id);

    $tmpFile = tempnam(sys_get_temp_dir(), 'wiktionary_relay_pref_');
    file_put_contents($tmpFile, implode("\n", [relayFixtureLines()[1]])."\n");
    $stats = (new WiktionaryParser('ru', 'en', 100))->import($tmpFile);

    expect(Form::where('form', 'четвереньках')->where('word_id', $noun->id)->count())->toBe(1)
        ->and(Form::where('form', 'четвереньках')->where('word_id', $verb->id)->count())->toBe(0)
        // Both rows already exist: no proxy creation.
        ->and($stats['relay_bases_created'])->toBe(0)
        ->and($stats['relays_demoted'])->toBe(1);

    unlink($tmpFile);
});

it('skips class preference for an unknown-pos relay and falls back to the lowest id', function () {
    $languages = createLanguages();
    $ruId = $languages['ru']->id;
    $nounClass = WordClass::where('language_id', $ruId)->where('slug', 'noun')->firstOrFail();
    $unknownClass = WordClass::where('language_id', $ruId)->where('slug', 'unknown')->firstOrFail();

    // The pinned resolver semantics (same-class → any-class → lowest id):
    // a pos-less relay carries no wanted class, so the lowest-id row wins
    // whatever class it carries — here the classed one, created first. An
    // unknown twin never wins BECAUSE of its class; only by id.
    $noun = Word::create(['word' => 'бег', 'l_word' => 'бег', 'language_id' => $ruId, 'word_class_id' => $nounClass->id]);
    $unknown = Word::create(['word' => 'бег', 'l_word' => 'бег', 'language_id' => $ruId, 'word_class_id' => $unknownClass->id]);
    expect($noun->id)->toBeLessThan($unknown->id);

    $tmpFile = tempnam(sys_get_temp_dir(), 'wiktionary_relay_unknown_');
    file_put_contents($tmpFile, json_encode(['word' => 'бегом', 'pos' => 'unknown', 'senses' => [
        ['glosses' => ['instrumental of бег'], 'tags' => ['form-of', 'instrumental'],
            'form_of' => [['word' => 'бег']]],
    ]])."\n");
    (new WiktionaryParser('ru', 'en', 100))->import($tmpFile);

    expect(Form::where('form', 'бегом')->where('word_id', $noun->id)->count())->toBe(1)
        ->and(Form::where('form', 'бегом')->where('word_id', $unknown->id)->count())->toBe(0);

    unlink($tmpFile);
});
