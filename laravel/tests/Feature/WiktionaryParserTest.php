<?php

use App\Classes\WiktionaryParser;
use App\Models\Definition;
use App\Models\Etymology;
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

// Writes the lines (already-encoded JSON strings) to a temp JSONL fixture
// file and returns its path — the caller unlinks it when the test is done.
// The prefix keeps a leaked temp file identifiable to its test.
function writeJsonlFixture(array $lines, string $prefix = 'wiktionary'): string
{
    $tmpFile = tempnam(sys_get_temp_dir(), $prefix.'_');
    file_put_contents($tmpFile, implode("\n", $lines)."\n");

    return $tmpFile;
}

it('imports a file and creates database records', function () {
    $enLanguageId = createLanguages()['en']->id;
    $tmpFile = writeJsonlFixture([
        json_encode(['word' => 'cat', 'pos' => 'noun', 'senses' => [['glosses' => ['A small domesticated feline']]], 'translations' => [['code' => 'ru', 'word' => 'кошка']]]),
        json_encode(['word' => 'cat', 'pos' => 'noun', 'forms' => [['form' => 'cats']]]),
        json_encode(['word' => 'dog', 'pos' => 'noun', 'senses' => [['glosses' => ['A domesticated canine']]], 'translations' => [['code' => 'ru', 'word' => 'собака']]]),
    ], 'wiktionary_test');

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
    $tmpFile = writeJsonlFixture([
        json_encode(['word' => 'test', 'pos' => 'unsupported_pos', 'senses' => [['glosses' => ['A test']]]]),
    ], 'wiktionary_test');

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
    $tmpFile = writeJsonlFixture([
        json_encode(['word' => 'Hund', 'pos' => 'noun', 'sounds' => [['ipa' => '/hʊnt/']], 'senses' => [['glosses' => ['A dog']]]]),
    ], 'wiktionary_test');

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
    $tmpFile = writeJsonlFixture([
        json_encode(['word' => 'cat', 'pos' => 'noun', 'translations' => [
            ['code' => 'ru', 'word' => 'кошка'],
            ['code' => 'de', 'word' => 'Katze'],
            ['code' => 'fr', 'word' => 'chat'],
        ]]),
    ], 'wiktionary_multi');

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

    $ruFile = writeJsonlFixture([json_encode(['word' => 'дом', 'pos' => 'noun', 'forms' => [
        ['form' => 'до́ма'],
        ['form' => 'доме́'],
    ]])], 'wiktionary_ru');
    (new WiktionaryParser('ru', 'en', 100))->import($ruFile);

    // The marks leave the stored display words AND the headword (the ADR
    // 0078 kaikki carve-out is reversed), which now also stores itself as
    // the lemma form: no stressed twin rows, the lookup keys were stripped
    // all along.
    $dom = Word::where('word', 'дом')->where('language_id', $languages['ru']->id)->firstOrFail();
    expect($dom->forms->pluck('form')->sort()->values()->all())->toBe(['дом', 'дома', 'доме'])
        ->and($dom->forms->pluck('l_word')->sort()->values()->all())->toBe(['дом', 'дома', 'доме']);
    unlink($ruFile);

    $enFile = writeJsonlFixture([json_encode(['word' => 'кот', 'pos' => 'noun', 'forms' => [
        ['form' => 'cats'],
    ]])], 'wiktionary_en');
    (new WiktionaryParser('en', 'ru', 100))->import($enFile);

    // Non-stress-mark languages keep their words and forms exactly as the
    // dump has them — plus the lemma form every word now carries.
    $kot = Word::where('word', 'кот')->where('language_id', $languages['en']->id)->firstOrFail();
    expect($kot->forms->pluck('form')->sort()->values()->all())->toBe(['cats', 'кот']);
    unlink($enFile);
});

it('stores a stressed ru headword plain while its definition keeps its marks', function () {
    $languages = createLanguages();

    $tmpFile = writeJsonlFixture([json_encode([
        'word' => 'свобо́дный',
        'pos' => 'adjective',
        'senses' => [['glosses' => ['свобо́дный человек — тот, кто не в неволе']]],
    ])], 'wiktionary_ru');
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

    $tmpFile = writeJsonlFixture([
        json_encode(['word' => 'quick', 'pos' => 'adj', 'senses' => [['glosses' => ['fast']]]]),
        json_encode(['word' => 'slow', 'pos' => 'adjective', 'senses' => [['glosses' => ['not fast']]]]),
    ], 'wiktionary_adj');
    (new WiktionaryParser('en', 'ru', 100))->import($tmpFile);

    // 'adj' canonicalizes into the same class slug 'adjective' carries: one
    // taxonomy, no parallel 'adj' class minted beside it.
    expect(WordClass::where('language_id', $enLanguageId)->where('slug', 'adj')->exists())->toBeFalse()
        ->and(Word::where('word', 'quick')->where('language_id', $enLanguageId)->firstOrFail()->wordClass->slug)->toBe('adjective');

    unlink($tmpFile);
});

it('merges aliased pos lines of one word into a single row', function () {
    $enLanguageId = createLanguages()['en']->id;

    $tmpFile = writeJsonlFixture([
        json_encode(['word' => 'quick', 'pos' => 'adj', 'senses' => [['glosses' => ['fast']]]]),
        json_encode(['word' => 'quick', 'pos' => 'adjective', 'senses' => [['glosses' => ['lively']]]]),
    ], 'wiktionary_alias');
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

    $tmpFile = writeJsonlFixture([json_encode(['word' => 'четвереньках', 'pos' => 'noun', 'forms' => [
        ['form' => 'četverénʹkax', 'tags' => ['romanization']],
        ['form' => 'четвереньки', 'tags' => ['genitive']],
    ]])], 'wiktionary_rom');
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
        json_encode(['word' => 'четвереньки', 'pos' => 'noun', 'senses' => [['glosses' => ['передвижение на четвереньках']]]]),
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

    $tmpFile = writeJsonlFixture(relayFixtureLines(), 'wiktionary_relay');
    $stats = (new WiktionaryParser('ru', 'en', 100))->import($tmpFile);

    // No word row for the relay — and none of its content anywhere.
    expect(Word::where('l_word', 'четвереньках')->where('language_id', $ruId)->count())->toBe(0)
        ->and(Transcription::count())->toBe(0)
        ->and(Definition::where('definition', 'like', '%prepositional of%')->count())->toBe(0);

    // The base imports as a word; the relay headword is its form.
    $base = Word::where('word', 'четвереньки')->where('language_id', $ruId)->firstOrFail();
    expect($base->definitions->pluck('definition')->all())->toBe(['передвижение на четвереньках'])
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

    $tmpFile = writeJsonlFixture([relayFixtureLines()[1]], 'wiktionary_relay_orphan');
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

    $tmpFile = writeJsonlFixture([
        json_encode(['word' => 'бегом', 'pos' => 'adverb', 'senses' => [
            ['glosses' => ['быстрым бегом, не переходя на шаг']],
            ['glosses' => ['instrumental of бег'], 'tags' => ['form-of', 'instrumental'],
                'form_of' => [['word' => 'бег']]],
        ]]),
    ], 'wiktionary_mixed');
    $stats = (new WiktionaryParser('ru', 'en', 100))->import($tmpFile);

    $word = Word::where('word', 'бегом')->where('language_id', $ruId)->firstOrFail();
    // A mixed entry stays a word and keeps the definitions extraction it
    // always had — only pure relays demote. The guard then independently
    // drops the relay sense's Latin-majority gloss at the flush; the
    // Russian real gloss survives it.
    expect($word->definitions->pluck('definition')->all())->toBe(['быстрым бегом, не переходя на шаг'])
        ->and(Word::where('language_id', $ruId)->count())->toBe(1)
        ->and($stats['relays_demoted'])->toBe(0)
        ->and($stats['relay_bases_created'])->toBe(0)
        ->and($stats['definitions_skipped_foreign'])->toBe(1);

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

    $tmpFile = writeJsonlFixture([relayFixtureLines()[1]], 'wiktionary_relay_pref');
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

    $tmpFile = writeJsonlFixture([json_encode(['word' => 'бегом', 'pos' => 'unknown', 'senses' => [
        ['glosses' => ['instrumental of бег'], 'tags' => ['form-of', 'instrumental'],
            'form_of' => [['word' => 'бег']]],
    ]])], 'wiktionary_relay_unknown');
    (new WiktionaryParser('ru', 'en', 100))->import($tmpFile);

    expect(Form::where('form', 'бегом')->where('word_id', $noun->id)->count())->toBe(1)
        ->and(Form::where('form', 'бегом')->where('word_id', $unknown->id)->count())->toBe(0);

    unlink($tmpFile);
});

it('joins a ru-edition etymology_texts array and still reads the en edition singular etymology_text', function () {
    $languages = createLanguages();
    $ruId = $languages['ru']->id;
    $enId = $languages['en']->id;

    // The ru.wiktionary edition carries etymologies as an ARRAY of text
    // blocks (`etymology_texts`); the singular `etymology_text` it never
    // emits must not gate them.
    $ruFile = writeJsonlFixture([
        json_encode([
            'word' => 'майка', 'pos' => 'сущ',
            'etymology_texts' => [
                'От франц. maillot, переоформленного при помощи суффикса -к(а).',
                'От майя — ‘лёгкая хлопчатобумажная ткань’, с помощью суффикса -к(а).',
            ],
            'senses' => [['glosses' => ['лёгкая приталенная футболка']]],
        ]),
        // A dump artifact the plural fallback must survive: an empty-string
        // singular gated the array — the word stored NO etymology at all.
        json_encode([
            'word' => 'рыба', 'pos' => 'сущ',
            'etymology_text' => '',
            'etymology_texts' => ['От общеслав. *ryba.'],
            'senses' => [['glosses' => ['водное позвоночное']]],
        ]),
    ], 'wiktionary_ru_ety');
    (new WiktionaryParser('ru', 'en', 100))->import($ruFile);

    $maika = Word::where('word', 'майка')->where('language_id', $ruId)->firstOrFail();
    expect($maika->etymologies->pluck('etymology')->all())->toBe([
        "От франц. maillot, переоформленного при помощи суффикса -к(а).\n"
            .'От майя — ‘лёгкая хлопчатобумажная ткань’, с помощью суффикса -к(а).',
    ]);

    $ryba = Word::where('word', 'рыба')->where('language_id', $ruId)->firstOrFail();
    expect($ryba->etymologies->pluck('etymology')->all())->toBe(['От общеслав. *ryba.']);
    unlink($ruFile);

    // En-edition regression guard: the singular string still lands, and it
    // wins on the (never-occurring-in-real-dumps) entry carrying both fields.
    $enFile = writeJsonlFixture([
        json_encode(['word' => 'cat', 'pos' => 'noun', 'etymology_text' => 'From Middle English cat.',
            'senses' => [['glosses' => ['A small domesticated feline']]]]),
        json_encode(['word' => 'dog', 'pos' => 'noun',
            'etymology_text' => 'From Old English docga.',
            'etymology_texts' => ['would be a plural-field double import'],
            'senses' => [['glosses' => ['A domesticated canine']]]]),
    ], 'wiktionary_en_ety');
    (new WiktionaryParser('en', 'ru', 100))->import($enFile);

    $cat = Word::where('word', 'cat')->where('language_id', $enId)->firstOrFail();
    $dog = Word::where('word', 'dog')->where('language_id', $enId)->firstOrFail();
    expect($cat->etymologies->pluck('etymology')->all())->toBe(['From Middle English cat.'])
        ->and($dog->etymologies->pluck('etymology')->all())->toBe(['From Old English docga.']);
    unlink($enFile);
});

it('stages translations from code-only, lang_code-only and both-fields objects', function () {
    $languages = createLanguages();
    $ruId = $languages['ru']->id;

    $tmpFile = writeJsonlFixture([
        // ru edition shape: lang_code only, no code.
        json_encode(['word' => 'собака', 'pos' => 'сущ', 'translations' => [
            ['word' => 'dog', 'lang_code' => 'en', 'lang' => 'Английский'],
        ]]),
        // en edition shape: both fields, equal values — stages exactly once.
        json_encode(['word' => 'кот', 'pos' => 'сущ', 'translations' => [
            ['word' => 'cat', 'code' => 'en', 'lang_code' => 'en', 'lang' => 'English'],
        ]]),
        // The legacy shape the en edition tests pinned: code only.
        json_encode(['word' => 'лис', 'pos' => 'сущ', 'translations' => [
            ['word' => 'fox', 'code' => 'en', 'lang' => 'English'],
        ]]),
        // Neither field: no target match, nothing stages.
        json_encode(['word' => 'ёж', 'pos' => 'сущ', 'translations' => [
            ['word' => 'hedgehog', 'lang' => 'English'],
        ]]),
    ], 'wiktionary_langcode');

    (new WiktionaryParser('ru', 'en', 100))->import($tmpFile);

    expect(Word::where('word', 'собака')->where('language_id', $ruId)->firstOrFail()->translations)->toBe(['dog'])
        ->and(Word::where('word', 'кот')->where('language_id', $ruId)->firstOrFail()->translations)->toBe(['cat'])
        ->and(Word::where('word', 'лис')->where('language_id', $ruId)->firstOrFail()->translations)->toBe(['fox'])
        ->and(Word::where('word', 'ёж')->where('language_id', $ruId)->firstOrFail()->translations)->toBeNull();

    unlink($tmpFile);
});

it('stresses a lang_code-only candidate of a stress-mark target language at staging', function () {
    $languages = createLanguages();
    $ruId = $languages['ru']->id;

    // The ru edition's lang_code-only objects reach StressMarks targets
    // (be here) just like code-only ones did: the candidate strips at
    // staging time, per the ADR 0079 rule.
    $tmpFile = writeJsonlFixture([json_encode(['word' => 'дом', 'pos' => 'сущ', 'translations' => [
        ['word' => 'до'."\u{0301}".'м', 'lang_code' => 'be', 'lang' => 'Беларуская'],
    ]])], 'wiktionary_langcode_be');
    (new WiktionaryParser('ru', ['en', 'be'], 100))->import($tmpFile);

    expect(Word::where('word', 'дом')->where('language_id', $ruId)->firstOrFail()->translations)->toBe(['дом']);

    unlink($tmpFile);
});

it('demotes a ru-edition form-of relay to a form of its base, landing none of its content', function () {
    $languages = createLanguages();
    $ruId = $languages['ru']->id;

    $base = 'кру'."\u{0301}".'глый'; // stressed, as the dump carries it

    $tmpFile = writeJsonlFixture([
        // The lemma entry in the ru edition's own dialect: Russian pos
        // abbreviation, plural-array etymology, lang_code-only translation.
        json_encode([
            'word' => 'круглый', 'pos' => 'прил', 'lang_code' => 'ru',
            'etymology_texts' => ['Круг + -л-'],
            'senses' => [['glosses' => ['имеющий форму круга; не угловатый']]],
            'translations' => [['word' => 'round', 'lang_code' => 'en']],
        ]),
        // The relay: every sense a form_of pointer with the form-of tag,
        // plus content that must not land anywhere.
        json_encode([
            'word' => 'кругла', 'pos' => 'прил', 'lang_code' => 'ru',
            'etymology_texts' => ['relay etymology must not land'],
            'senses' => [[
                'glosses' => ['жен. р. к '.$base],
                'tags' => ['form-of', 'feminine'],
                'form_of' => [['word' => $base]],
            ]],
            'translations' => [['word' => 'relay must not stage', 'lang_code' => 'en']],
        ]),
    ], 'wiktionary_ru_relay');

    $stats = (new WiktionaryParser('ru', 'en', 100))->import($tmpFile);

    // No word row for the relay — and nothing it carried anywhere.
    expect(Word::where('l_word', 'кругла')->where('language_id', $ruId)->count())->toBe(0)
        ->and(Definition::where('definition', 'like', '%к круглый%')->count())->toBe(0)
        ->and(Etymology::where('etymology', 'like', '%must not%')->count())->toBe(0)
        ->and(Word::where('translations', 'like', '%must not stage%')->count())->toBe(0);

    // The lemma imports as a word with its ru-edition content; the relay
    // headword attaches as its form.
    $krugly = Word::where('word', 'круглый')->where('language_id', $ruId)->firstOrFail();
    expect($krugly->definitions->pluck('definition')->all())->toBe(['имеющий форму круга; не угловатый'])
        ->and($krugly->etymologies->pluck('etymology')->all())->toBe(['Круг + -л-'])
        ->and($krugly->translations)->toBe(['round'])
        ->and(Form::where('form', 'кругла')->where('word_id', $krugly->id)->count())->toBe(1)
        ->and($stats['relays_demoted'])->toBe(1)
        ->and($stats['relay_bases_created'])->toBe(0)
        ->and($stats['words_imported'])->toBe(1);

    unlink($tmpFile);
});

it('imports a ёлка entry as one word whose forms carry both spellings under one l_word', function () {
    $languages = createLanguages();
    $ruId = $languages['ru']->id;

    $tmpFile = writeJsonlFixture([json_encode([
        'word' => 'ёлка',
        'pos' => 'сущ',
        'senses' => [['glosses' => ['хвойное дерево с иглами вместо листьев']]],
    ])], 'wiktionary_yo');
    (new WiktionaryParser('ru', 'en', 100))->import($tmpFile);

    // ONE word row: the display keeps the source ё, the key folds to е —
    // and the folded key never exists as a second word row.
    $elka = Word::where('l_word', 'елка')->where('language_id', $ruId)->firstOrFail();
    expect($elka->word)->toBe('ёлка')
        ->and($elka->l_word)->toBe('елка')
        ->and(Word::where('language_id', $ruId)->count())->toBe(1)
        ->and(Word::where('word', 'елка')->where('language_id', $ruId)->count())->toBe(0);

    // The forms table holds ёлка AND its е-folded alias, both keyed елка —
    // the same key the words table row carries.
    $forms = $elka->forms->map(fn ($form) => ['form' => $form->form, 'l_word' => $form->l_word])
        ->sortBy('form')->values()->all();
    expect($forms)->toBe([
        ['form' => 'елка', 'l_word' => 'елка'],
        ['form' => 'ёлка', 'l_word' => 'елка'],
    ])
        ->and(Form::count())->toBe(2);

    unlink($tmpFile);
});

it('trims kaikki headwords and staged candidates at storage, en and ru alike', function () {
    $languages = createLanguages();
    $ruId = $languages['ru']->id;

    // The ru headword carries surrounding whitespace and stages candidates
    // into BOTH target languages, each candidate whitespace-padded.
    $ruFile = writeJsonlFixture([json_encode([
        'word' => ' ёлка ',
        'pos' => 'сущ',
        'senses' => [['glosses' => ['хвойное дерево']]],
        'translations' => [
            ['word' => ' dog ', 'lang_code' => 'en'],
            ['word' => ' до'."\u{0301}".'м ', 'lang_code' => 'be'],
        ],
    ])], 'wiktionary_trim_ru');
    (new WiktionaryParser('ru', ['en', 'be'], 100))->import($ruFile);

    // The headword stores trimmed (only emptiness was checked before) —
    // and the staged candidates store trimmed too, stress-mark target or not.
    $elka = Word::where('l_word', 'елка')->where('language_id', $ruId)->firstOrFail();
    expect($elka->word)->toBe('ёлка')
        ->and(Word::where('word', ' ёлка ')->exists())->toBeFalse()
        ->and($elka->translations)->toBe(['dog', 'дом']);

    unlink($ruFile);

    // The same trim on an en headword — en and ru alike.
    $enFile = writeJsonlFixture([
        json_encode(['word' => ' cat ', 'pos' => 'noun', 'senses' => [['glosses' => ['feline']]]]),
    ], 'wiktionary_trim_en');
    (new WiktionaryParser('en', 'ru', 100))->import($enFile);

    $cat = Word::where('l_word', 'cat')->where('language_id', $languages['en']->id)->firstOrFail();
    expect($cat->word)->toBe('cat')
        ->and(Word::where('word', ' cat ')->exists())->toBeFalse();

    unlink($enFile);
});

it('drops foreign-script glosses at the definition flush and counts them skipped', function () {
    $languages = createLanguages();
    $ruId = $languages['ru']->id;
    $enId = $languages['en']->id;

    // The en-Wiktionary edition's ru sections gloss in English: those
    // glosses are the other language's prose, not ru definitions. The
    // etymology is deliberately NOT guarded.
    $ruFile = writeJsonlFixture([
        json_encode([
            'word' => 'собака', 'pos' => 'сущ',
            'senses' => [
                ['glosses' => ['a domesticated canid']],
                ['glosses' => ['another English gloss']],
            ],
            'etymology_text' => 'From Proto-Slavic *sъbaka.',
        ]),
        json_encode([
            'word' => 'кот', 'pos' => 'сущ',
            'senses' => [['glosses' => ['домашнее животное семейства кошачьих']]],
        ]),
    ], 'wiktionary_deflang_ru');
    $stats = (new WiktionaryParser('ru', 'en', 100))->import($ruFile);

    $sobaka = Word::where('word', 'собака')->where('language_id', $ruId)->firstOrFail();
    $kot = Word::where('word', 'кот')->where('language_id', $ruId)->firstOrFail();
    expect($sobaka->definitions)->toHaveCount(0)
        ->and($stats['definitions_skipped_foreign'])->toBe(2)
        ->and($kot->definitions->pluck('definition')->all())->toBe(['домашнее животное семейства кошачьих'])
        ->and($sobaka->etymologies->pluck('etymology')->all())->toBe(['From Proto-Slavic *sъbaka.']);

    unlink($ruFile);

    // En behaves symmetrically: a Cyrillic gloss on an en word drops, the
    // English one stays.
    $enFile = writeJsonlFixture([
        json_encode([
            'word' => 'dog', 'pos' => 'noun',
            'senses' => [
                ['glosses' => ['домашнее животное']],
                ['glosses' => ['A domesticated canid']],
            ],
        ]),
    ], 'wiktionary_deflang_en');
    $stats = (new WiktionaryParser('en', 'ru', 100))->import($enFile);

    $dog = Word::where('word', 'dog')->where('language_id', $enId)->firstOrFail();
    expect($dog->definitions->pluck('definition')->all())->toBe(['A domesticated canid'])
        ->and($stats['definitions_skipped_foreign'])->toBe(1);

    unlink($enFile);
});
