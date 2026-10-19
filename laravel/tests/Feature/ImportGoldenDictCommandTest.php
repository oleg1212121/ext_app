<?php

use App\Classes\GoldenDictParser;
use App\Models\Definition;
use App\Models\Form;
use App\Models\Language;
use App\Models\Transcription;
use App\Models\TranscriptionType;
use App\Models\User;
use App\Models\Word;
use App\Models\WordClass;
use App\Models\WordTranslation;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\PendingCommand;

function goldendictFixturePath(): string
{
    return __DIR__.'/../Fixtures/goldendict-sample.jsonl';
}

beforeEach(function () {
    // A brand-new language: no seeded word classes or transcription types,
    // so every lookup the import needs must be auto-created.
    createLanguages(['be']);
});

it('rejects a missing file', function () {
    $this->artisan('goldendict:import', ['file' => '/nonexistent-dictionary.jsonl', '--lang' => 'be'])
        ->expectsOutputToContain('File not found')
        ->assertFailed();

    expect(Word::count())->toBe(0);
});

it('requires a known --lang registry code', function () {
    $this->artisan('goldendict:import', ['file' => goldendictFixturePath()])
        ->expectsOutputToContain('--lang')
        ->assertFailed();

    $this->artisan('goldendict:import', ['file' => goldendictFixturePath(), '--lang' => 'xx'])
        ->expectsOutputToContain('Unknown language')
        ->assertFailed();

    expect(Word::count())->toBe(0);
});

it('imports the fixture into a brand-new language with auto-created lookups', function () {
    $be = Language::query()->where('code', 'be')->first();

    $this->artisan('goldendict:import', ['file' => goldendictFixturePath(), '--lang' => 'be'])
        ->expectsOutputToContain('Import completed!')
        ->assertSuccessful();

    // Seven fixture records import; the malformed and blank-headword lines skip.
    expect(Word::query()->where('language_id', $be->id)->count())->toBe(6);

    // Every imported word carries the unranked frequency sentinel.
    Word::query()->where('language_id', $be->id)->each(
        fn (Word $word) => expect((float) $word->frequency)->toBe((float) Word::FREQUENCY_UNRANKED),
    );

    // Word classes auto-create with the slug as placeholder title.
    $slugs = WordClass::query()->where('language_id', $be->id)->pluck('title', 'slug');
    expect($slugs->keys()->sort()->values()->all())->toBe(['interjection', 'noun', 'phrase', 'unknown']);
    expect($slugs['noun'])->toBe('noun');

    // Bracketed IPA lands in transcriptions under the auto-created ipa type.
    expect(TranscriptionType::query()->where('language_id', $be->id)->where('slug', 'ipa')->exists())->toBeTrue();

    $cat = Word::query()->where('language_id', $be->id)->where('word', 'cat')->first();
    expect($cat)->not->toBeNull();
    expect($cat->wordClass->slug)->toBe('noun');
    // The fixture's English article prose is not a be definition — the
    // majority-script guard drops it (and counts it) at import.
    expect($cat->definitions)->toHaveCount(0);
    expect($cat->transcriptions->count())->toBe(1);
    expect($cat->transcriptions->first()->transcription)->toBe('kæt');
    expect($cat->transcriptions->first()->transcriptionType->slug)->toBe('ipa');

    // Slash IPA, several sense segments — all Latin, all guarded away on a
    // be word — and the alternate headword as a form beside the word's own
    // lemma form.
    $hello = Word::query()->where('language_id', $be->id)->where('word', 'hello')->first();
    expect($hello->wordClass->slug)->toBe('interjection');
    expect($hello->definitions->count())->toBe(0);
    expect($hello->transcriptions->first()->transcription)->toBe('həˈləʊ');
    expect(Form::query()->where('word_id', $hello->id)->pluck('form')->all())->toBe(['hello', 'hi']);

    // Multi-word headwords go to the phrase class despite the POS hint (ADR 0059).
    $giveUp = Word::query()->where('language_id', $be->id)->where('word', 'give up')->first();
    expect($giveUp->wordClass->slug)->toBe('phrase');

    // Cyrillic stress marks: be display words store plain letters — the
    // combining acute leaves the word entirely (the StressMarks rule), so
    // the stressed headword lands as "привет". Its own-language definition
    // passes the guard.
    $privet = Word::query()->where('language_id', $be->id)->where('word', 'привет')->first();
    expect($privet)->not->toBeNull();
    expect($privet->l_word)->toBe('привет');
    expect($privet->wordClass->slug)->toBe('unknown');
    expect($privet->definitions->first()->definition)->toContain('здравствуйте');

    // The 300-"word" Latin segment is guarded away like any other foreign
    // article prose (the 500-char cap is pinned by the guard test below).
    $long = Word::query()->where('language_id', $be->id)->where('word', 'long')->first();
    expect($long->definitions)->toHaveCount(0);

    // An empty article still imports the headword.
    $blank = Word::query()->where('language_id', $be->id)->where('word', 'blank')->first();
    expect($blank->definitions->count())->toBe(0);
});

it('writes nothing at all on --dry-run', function () {
    $this->artisan('goldendict:import', ['file' => goldendictFixturePath(), '--lang' => 'be', '--dry-run' => true])
        ->expectsOutputToContain('DRY RUN')
        ->assertSuccessful();

    expect(Word::count())->toBe(0);
    expect(Definition::count())->toBe(0);
    expect(Transcription::count())->toBe(0);
    expect(Form::count())->toBe(0);
    // Auto-created lookups are writes too: a dry run must not create them.
    expect(WordClass::count())->toBe(0);
    expect(TranscriptionType::count())->toBe(0);
});

it('caps the run with --max-entries', function () {
    $this->artisan('goldendict:import', ['file' => goldendictFixturePath(), '--lang' => 'be', '--max-entries' => 2])
        ->assertSuccessful();

    expect(Word::count())->toBe(2);
    expect(Word::query()->where('word', 'cat')->exists())->toBeTrue();
    expect(Word::query()->where('word', 'hello')->exists())->toBeTrue();
    expect(Word::query()->where('word', 'give up')->exists())->toBeFalse();
});

it('is idempotent on re-runs and keeps curated frequency ranks', function () {
    $import = fn () => $this->artisan('goldendict:import', ['file' => goldendictFixturePath(), '--lang' => 'be'])->assertSuccessful();

    $import();
    $import();

    expect(Word::count())->toBe(6);
    // Only привет's own-language definition survives the majority-script
    // guard; the fixture's six English article segments are counted skipped.
    expect(Definition::count())->toBe(1);
    expect(Transcription::count())->toBe(2);
    // Every word's lemma form (6) + the one alt form.
    expect(Form::count())->toBe(7);

    // A frequency rank curated after the first import survives a re-import:
    // the upsert refreshes l_word only, never the sentinel onto ranked words.
    $cat = Word::query()->where('word', 'cat')->first();
    $cat->forceFill(['frequency' => 500.5])->save();
    $import();

    expect((float) $cat->refresh()->frequency)->toBe(500.5);
});

it('imports monolingually with an extraction profile and writes no links', function () {
    createLanguages(['en']);

    // A profile without --target-lang tunes definitions/transcriptions only;
    // the bilingual machinery stays dormant. (The source is en, whose
    // English article text passes the majority-script guard.)
    $this->artisan('goldendict:import', [
        'file' => goldendictProfileFixturePath(),
        '--lang' => 'en',
        '--profile' => 'example-classes',
    ])
        ->expectsOutputToContain('Extraction profile: example-classes')
        ->assertSuccessful();

    $tree = Word::query()->where('word', 'tree')->firstOrFail();
    expect($tree->definitions->pluck('definition')->all())->toBe(['a tall plant with a woody trunk'])
        ->and($tree->transcriptions->pluck('transcription')->all())->toBe(['tri:'])
        ->and(WordTranslation::count())->toBe(0)
        ->and($tree->translations)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Bilingual mode (--target-lang)
|--------------------------------------------------------------------------
*/

function goldendictBilingualFixturePath(): string
{
    return __DIR__.'/../Fixtures/goldendict-bilingual-en-de.jsonl';
}

function goldendictRussianFixturePath(): string
{
    return __DIR__.'/../Fixtures/goldendict-bilingual-en-ru.jsonl';
}

function importBilingual(string $targetLang = 'de', array $extra = []): PendingCommand
{
    return test()->artisan('goldendict:import', array_merge([
        'file' => goldendictBilingualFixturePath(),
        '--lang' => 'en',
        '--target-lang' => $targetLang,
    ], $extra));
}

it('links candidates to existing target words, preferring the same class and phrases', function () {
    createLanguages(['en', 'de']);
    $en = Language::query()->where('code', 'en')->first();
    $de = Language::query()->where('code', 'de')->first();

    // The target language's own imports carry the rows: cat's equivalents
    // under noun, give up's under verb — plus a same-l_word decoy under the
    // wrong class for the multi-word candidate ("aufhören mit" exists under
    // noun AND phrase; the phrase row must win).
    $noun = WordClass::query()->create(['language_id' => $de->id, 'slug' => 'noun', 'title' => 'noun']);
    $verb = WordClass::query()->create(['language_id' => $de->id, 'slug' => 'verb', 'title' => 'verb']);
    $phrase = WordClass::query()->create(['language_id' => $de->id, 'slug' => 'phrase', 'title' => 'phrase']);
    $katze = Word::query()->create(['word' => 'Katze', 'l_word' => 'katze', 'language_id' => $de->id, 'word_class_id' => $noun->id]);
    $kater = Word::query()->create(['word' => 'Kater', 'l_word' => 'kater', 'language_id' => $de->id, 'word_class_id' => $noun->id]);
    $aufgeben = Word::query()->create(['word' => 'aufgeben', 'l_word' => 'aufgeben', 'language_id' => $de->id, 'word_class_id' => $verb->id]);
    $aufhoerenNoun = Word::query()->create(['word' => 'aufhören mit', 'l_word' => 'aufhören mit', 'language_id' => $de->id, 'word_class_id' => $noun->id]);
    $aufhoerenPhrase = Word::query()->create(['word' => 'aufhören mit', 'l_word' => 'aufhören mit', 'language_id' => $de->id, 'word_class_id' => $phrase->id]);

    importBilingual()
        ->expectsOutputToContain('Import completed!')
        ->assertSuccessful();

    // Link-only: no target row is created, the target classes are not
    // touched, and hello's candidates (no de rows) stay unmatched.
    expect(Word::query()->where('language_id', $de->id)->count())->toBe(5)
        ->and(WordTranslation::count())->toBe(4);

    $cat = Word::query()->where('language_id', $en->id)->where('word', 'cat')->firstOrFail();
    $giveUp = Word::query()->where('language_id', $en->id)->where('word', 'give up')->firstOrFail();

    expect(WordTranslation::isLinked($cat->id, $katze->id))->toBeTrue()
        ->and(WordTranslation::isLinked($cat->id, $kater->id))->toBeTrue()
        ->and(WordTranslation::isLinked($giveUp->id, $aufgeben->id))->toBeTrue()
        // The multi-word candidate prefers the phrase-class row over the
        // lower-id noun decoy (ADR 0059 survives as a linking preference).
        ->and(WordTranslation::isLinked($giveUp->id, $aufhoerenPhrase->id))->toBeTrue()
        ->and(WordTranslation::isLinked($giveUp->id, $aufhoerenNoun->id))->toBeFalse();

    // Linked candidates are pruned from the staged JSON by the trailing
    // link pass (the same stage → link → prune contract as the Wiktionary
    // import); hello's candidates (no de rows to resolve) stay staged.
    $hello = Word::query()->where('language_id', $en->id)->where('word', 'hello')->firstOrFail();
    expect($cat->refresh()->translations)->toBeNull()
        ->and($hello->translations)->not->toBeNull();

    // The monolingual side is untouched by bilingual mode: definitions and
    // transcriptions still land.
    expect($cat->definitions->count())->toBe(1)
        ->and($cat->transcriptions->first()->transcription)->toBe('kæt');
});

it('shows the linked translation in the word popup payload', function () {
    createLanguages(['en', 'de']);
    $de = Language::query()->where('code', 'de')->first();
    $noun = WordClass::query()->create(['language_id' => $de->id, 'slug' => 'noun', 'title' => 'noun']);
    foreach (['Katze', 'Kater'] as $word) {
        Word::query()->create(['word' => $word, 'l_word' => mb_strtolower($word), 'language_id' => $de->id, 'word_class_id' => $noun->id]);
    }

    importBilingual()->assertSuccessful();

    $cat = Word::query()->where('language_id', Language::query()->where('code', 'en')->value('id'))
        ->where('word', 'cat')->firstOrFail();

    $response = $this->actingAs(User::factory()->create())
        ->getJson(route('words.show', ['word' => $cat->id]))
        ->assertOk();

    $translations = collect($response->json('data.entries.0.translations'));
    expect($translations->count())->toBe(2)
        ->and($translations->pluck('word')->sort()->values()->all())->toBe(['Kater', 'Katze'])
        ->and($translations->pluck('language_code')->unique()->values()->all())->toBe(['de']);
});

it('writes word_translations links in canonical order regardless of id order', function () {
    createLanguages(['en', 'de']);
    $de = Language::query()->where('code', 'de')->first();

    // The target word exists BEFORE the import, so its id is lower than the
    // imported source word's id: the link must be canonicalized (swapped).
    $noun = WordClass::query()->create(['language_id' => $de->id, 'slug' => 'noun', 'title' => 'noun']);
    $existingKatze = Word::query()->create([
        'word' => 'Katze',
        'l_word' => 'katze',
        'language_id' => $de->id,
        'word_class_id' => $noun->id,
        'frequency' => Word::FREQUENCY_UNRANKED,
    ]);

    importBilingual()->assertSuccessful();

    $cat = Word::query()->where('language_id', Language::query()->where('code', 'en')->value('id'))
        ->where('word', 'cat')->firstOrFail();

    expect($existingKatze->refresh()->id)->toBeLessThan($cat->id)
        ->and(WordTranslation::query()->where('word_a_id', $existingKatze->id)->where('word_b_id', $cat->id)->exists())->toBeTrue()
        ->and(WordTranslation::query()->where('word_a_id', $cat->id)->where('word_b_id', $existingKatze->id)->exists())->toBeFalse();

    // Every link row of the run follows word_a_id < word_b_id (ADR 0020).
    WordTranslation::query()->each(
        fn (WordTranslation $link) => expect($link->word_a_id)->toBeLessThan($link->word_b_id),
    );
});

it('rejects junk translation candidates', function () {
    createLanguages(['en', 'de']);
    importBilingual()->assertSuccessful();

    // Sentence punctuation, overlong text, bare IPA, POS/gender markers and
    // numbers never become target words (usableCandidates mirror).
    foreach (['Beispiel mit Punkt.', 'siehe: woanders', 'kəˈtʃa', '12345'] as $junk) {
        expect(Word::query()->where('word', $junk)->exists())->toBeFalse();
    }

    // The junk article's headword still imports monolingually, but stages
    // no translations and links nothing.
    $junk = Word::query()->where('word', 'junk')->firstOrFail();
    expect($junk->translations)->toBeNull()
        ->and(WordTranslation::query()->where('word_a_id', $junk->id)->orWhere('word_b_id', $junk->id)->exists())->toBeFalse();
});

it('is idempotent on re-runs and merges staged translations without clobbering', function () {
    createLanguages(['en', 'de']);
    $de = Language::query()->where('code', 'de')->first();
    $noun = WordClass::query()->create(['language_id' => $de->id, 'slug' => 'noun', 'title' => 'noun']);
    foreach (['Katze', 'Kater'] as $word) {
        Word::query()->create(['word' => $word, 'l_word' => mb_strtolower($word), 'language_id' => $de->id, 'word_class_id' => $noun->id]);
    }

    importBilingual()->assertSuccessful();
    importBilingual()->assertSuccessful();

    expect(Word::query()->where('language_id', $de->id)->count())->toBe(2)
        ->and(WordTranslation::count())->toBe(2)
        ->and(Definition::count())->toBe(6);

    $cat = Word::query()->where('word', 'cat')->firstOrFail();
    // Both candidates resolved against the seeded de rows, so the trailing
    // link pass linked AND pruned them: the staged JSON empties to null.
    expect($cat->refresh()->translations)->toBeNull();

    // Translations staged by another source survive a re-import: the merge
    // pass unions the JSON, the upsert never replaces it, and the trailing
    // pass prunes only what resolves — the unmatched Kätzchen stays staged.
    $cat->forceFill(['translations' => ['Katze', 'Kater', 'Kätzchen']])->save();
    importBilingual()->assertSuccessful();

    expect($cat->refresh()->translations)->toBe(['Kätzchen'])
        ->and(WordTranslation::count())->toBe(2);
});

it('skips the bilingual link and staging phases with --no-link', function () {
    createLanguages(['en', 'de']);
    $en = Language::query()->where('code', 'en')->first();
    $de = Language::query()->where('code', 'de')->first();

    importBilingual('de', ['--no-link' => true])->assertSuccessful();

    // No target words, no links, no staging, no target-language lookups.
    expect(Word::query()->where('language_id', $de->id)->count())->toBe(0)
        ->and(WordTranslation::count())->toBe(0)
        ->and(Word::query()->whereNotNull('translations')->count())->toBe(0)
        ->and(WordClass::query()->where('language_id', $de->id)->count())->toBe(0);

    // The monolingual import still runs fully.
    expect(Word::query()->where('language_id', $en->id)->count())->toBe(4);
});

it('leaves an existing staged backlog alone on --no-link and --dry-run, then converts it on a default run', function () {
    createLanguages(['en', 'de']);
    $en = Language::query()->where('code', 'en')->first();
    $de = Language::query()->where('code', 'de')->first();
    $noun = WordClass::query()->create(['language_id' => $de->id, 'slug' => 'noun', 'title' => 'noun']);
    $katze = Word::query()->create(['word' => 'Katze', 'l_word' => 'katze', 'language_id' => $de->id, 'word_class_id' => $noun->id]);
    $cat = Word::query()->create([
        'word' => 'cat', 'l_word' => 'cat', 'language_id' => $en->id,
        'word_class_id' => $noun->id, 'translations' => ['Katze'],
    ]);

    // --no-link skips the trailing pass: the backlog stays staged, no links.
    importBilingual('de', ['--no-link' => true])->assertSuccessful();
    expect($cat->refresh()->translations)->toBe(['Katze'])
        ->and(WordTranslation::count())->toBe(0);

    // --dry-run writes nothing either: the backlog survives untouched.
    importBilingual('de', ['--dry-run' => true])->assertSuccessful();
    expect($cat->refresh()->translations)->toBe(['Katze'])
        ->and(WordTranslation::count())->toBe(0);

    // The default run converts the backlog: linked and pruned.
    importBilingual()->assertSuccessful();
    expect($cat->refresh()->translations)->toBeNull()
        ->and(WordTranslation::isLinked($cat->id, $katze->id))->toBeTrue();
});

it('validates --target-lang', function () {
    createLanguages(['en']);

    $this->artisan('goldendict:import', ['file' => goldendictBilingualFixturePath(), '--lang' => 'en', '--target-lang' => 'xx'])
        ->expectsOutputToContain('Unknown target language')
        ->assertFailed();

    $this->artisan('goldendict:import', ['file' => goldendictBilingualFixturePath(), '--lang' => 'en', '--target-lang' => 'en'])
        ->expectsOutputToContain('must differ')
        ->assertFailed();

    expect(Word::count())->toBe(0);
});

it('strips stress marks from staged ru candidates and links existing plain rows', function () {
    createLanguages(['en', 'ru']);
    $ru = Language::query()->where('code', 'ru')->first();

    // The target language's own import carries the plain rows (kaikki).
    $noun = WordClass::query()->create(['language_id' => $ru->id, 'slug' => 'noun', 'title' => 'noun']);
    $koshka = Word::query()->create(['word' => 'кошка', 'l_word' => 'кошка', 'language_id' => $ru->id, 'word_class_id' => $noun->id]);
    $sobaka = Word::query()->create(['word' => 'собака', 'l_word' => 'собака', 'language_id' => $ru->id, 'word_class_id' => $noun->id]);

    test()->artisan('goldendict:import', [
        'file' => goldendictRussianFixturePath(),
        '--lang' => 'en',
        '--target-lang' => 'ru',
    ])->assertSuccessful();

    // The candidates store as plain letters: the combining acutes leave the
    // staged strings, so no stressed twin of "кошка" is ever looked up. The
    // trailing link pass then prunes what resolved (кошка, собака) and
    // leaves only the unmatched "кот" staged.
    $cat = Word::query()->where('word', 'cat')->firstOrFail();
    $dog = Word::query()->where('word', 'dog')->firstOrFail();
    expect($cat->translations)->toBe(['кот'])
        ->and($dog->translations)->toBeNull();

    // Links point at the existing plain rows ("кот" has no ru row: staged
    // only); no ru word row was created.
    expect(Word::query()->where('language_id', $ru->id)->count())->toBe(2)
        ->and(WordTranslation::isLinked($cat->id, $koshka->id))->toBeTrue()
        ->and(WordTranslation::isLinked($dog->id, $sobaka->id))->toBeTrue();
});

it('writes nothing at all on --dry-run in bilingual mode', function () {
    createLanguages(['en', 'de']);

    importBilingual('de', ['--dry-run' => true])
        ->expectsOutputToContain('DRY RUN')
        ->assertSuccessful();

    expect(Word::count())->toBe(0)
        ->and(WordTranslation::count())->toBe(0)
        ->and(Definition::count())->toBe(0)
        ->and(WordClass::count())->toBe(0)
        ->and(Word::query()->whereNotNull('translations')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Extraction profiles (--profile)
|--------------------------------------------------------------------------
*/

function goldendictProfileFixturePath(): string
{
    return __DIR__.'/../Fixtures/goldendict-profile.jsonl';
}

function goldendictNoMatchFixturePath(): string
{
    return __DIR__.'/../Fixtures/goldendict-profile-nomatch.jsonl';
}

it('applies the profile selectors over the generic heuristics and falls back per selector', function () {
    createLanguages(['en', 'de']);
    $en = Language::query()->where('code', 'en')->first();
    $de = Language::query()->where('code', 'de')->first();

    $import = fn (array $extra = []) => test()->artisan('goldendict:import', array_merge([
        'file' => goldendictProfileFixturePath(),
        '--lang' => 'en',
        '--target-lang' => 'de',
    ], $extra));

    $import(['--profile' => 'example-classes'])
        ->expectsOutputToContain('Import completed!')
        ->assertSuccessful();

    // With the profile: definitions only from .sense blocks, transcriptions
    // from .ts nodes ("tri:" is not bracketed/IPA-marked — the generic
    // heuristic would never find it), translations only from .tr nodes —
    // staged only, since no de row carries "Baum".
    $tree = Word::query()->where('language_id', $en->id)->where('word', 'tree')->firstOrFail();
    expect($tree->definitions->pluck('definition')->all())->toBe(['a tall plant with a woody trunk'])
        ->and($tree->transcriptions->pluck('transcription')->all())->toBe(['tri:'])
        ->and($tree->translations)->toBe(['Baum'])
        ->and(Word::query()->where('language_id', $de->id)->count())->toBe(0)
        ->and(WordTranslation::count())->toBe(0);

    // Per-selector fallback: bird's .tr2 class matches nothing — for
    // definitions that falls back to the heuristic, but a configured
    // translations selector vouches for where the equivalents live: a
    // miss means this article has no headword translations, so no
    // candidate is mined from the raw block text.
    $bird = Word::query()->where('language_id', $en->id)->where('word', 'bird')->firstOrFail();
    expect($bird->definitions->pluck('definition')->all())->toBe(['animal with feathers'])
        ->and($bird->translations)->toBeNull();

    // Plain-text article (StarDict sametypesequence=m shape): the
    // transcriptions and definitions still fall back to the generic
    // heuristics — nothing drops — while the vouched translations
    // selector finds no nodes and yields no candidates.
    $dog = Word::query()->where('language_id', $en->id)->where('word', 'dog')->firstOrFail();
    expect($dog->definitions->pluck('definition')->all())->toBe(['dog [dɔg] n. Hund, Köter'])
        ->and($dog->transcriptions->pluck('transcription')->all())->toBe(['dɔg'])
        ->and($dog->translations)->toBeNull();

    // The demo: the SAME jsonl again without the profile visibly changes what
    // is extracted — the heuristic now re-stages the raw block definition,
    // but the 7-token segment is clause-sized: since the candidate guards it
    // no longer becomes a candidate (4-token phrases stay).
    $import()->assertSuccessful();

    expect($tree->refresh()->definitions->count())->toBe(2)
        ->and($tree->translations)->toBe(['Baum']);
});

it('errors on an unknown --profile slug listing the known profiles', function () {
    $this->artisan('goldendict:import', [
        'file' => goldendictProfileFixturePath(),
        '--lang' => 'be',
        '--profile' => 'nope',
    ])
        ->expectsOutputToContain('Unknown extraction profile: nope')
        ->expectsOutputToContain('example-classes')
        ->assertFailed();

    expect(Word::count())->toBe(0);
});

it('rejects a profile with unknown selector keys', function () {
    config(['goldendict.profiles.typo' => ['transcription' => '//span']]);

    $this->artisan('goldendict:import', [
        'file' => goldendictProfileFixturePath(),
        '--lang' => 'be',
        '--profile' => 'typo',
    ])
        ->expectsOutputToContain('unknown key')
        ->assertFailed();

    expect(Word::count())->toBe(0);
});

it('logs capped samples of articles where the profile matched nothing', function () {
    Log::spy();

    $this->artisan('goldendict:import', [
        'file' => goldendictNoMatchFixturePath(),
        '--lang' => 'be',
        '--profile' => 'example-classes',
    ])
        ->expectsOutputToContain('Profile no-match articles')
        ->assertSuccessful();

    // Six no-match articles, only the first five kept as samples.
    Log::shouldHaveReceived('notice')
        ->once()
        ->withArgs(fn (string $message) => str_contains($message, 'in 6 articles')
            && str_contains($message, 'w1 —')
            && str_contains($message, 'w5 —')
            && ! str_contains($message, 'w6'));

    // A profile that matches nothing never drops entries: everything the
    // generic heuristics find still imported.
    expect(Word::count())->toBe(6);
});

/*
|--------------------------------------------------------------------------
| Review fixes: stats accuracy and extraction edges
|--------------------------------------------------------------------------
*/

function goldendictSelfCandidateFixturePath(): string
{
    return __DIR__.'/../Fixtures/goldendict-self-candidate.jsonl';
}

function goldendictCyrillicTrimFixturePath(): string
{
    return __DIR__.'/../Fixtures/goldendict-cyrillic-trim.jsonl';
}

function goldendictEmptyMatchFixturePath(): string
{
    return __DIR__.'/../Fixtures/goldendict-profile-empty-match.jsonl';
}

it('counts translation candidates in real runs, not only dry runs', function () {
    createLanguages(['en', 'de']);

    // The empty target language leaves every candidate unmatched: counted
    // identically in the real and the dry run.
    $stats = (new GoldenDictParser('en', 500, false, 'de'))
        ->import(goldendictBilingualFixturePath());

    $dryStats = (new GoldenDictParser('en', 500, true, 'de'))
        ->import(goldendictBilingualFixturePath());

    expect($stats['translation_candidates'])->toBe(7)
        ->and($stats['translation_targets_unmatched'])->toBe(7)
        ->and($stats['translations_linked'])->toBe(0)
        ->and($dryStats['translation_candidates'])->toBe(7)
        ->and($dryStats['translation_targets_unmatched'])->toBe(7)
        ->and($dryStats['translations_linked'])->toBe(0);
});

it('reports zero new links on re-runs while the unmatched count repeats', function () {
    createLanguages(['en', 'de']);
    $de = Language::query()->where('code', 'de')->first();
    $noun = WordClass::query()->create(['language_id' => $de->id, 'slug' => 'noun', 'title' => 'noun']);
    foreach (['Katze', 'Kater'] as $word) {
        Word::query()->create(['word' => $word, 'l_word' => mb_strtolower($word), 'language_id' => $de->id, 'word_class_id' => $noun->id]);
    }

    $first = (new GoldenDictParser('en', 500, false, 'de'))
        ->import(goldendictBilingualFixturePath());
    expect($first['translations_linked'])->toBe(2)
        ->and($first['translation_targets_unmatched'])->toBe(5);

    // A re-import re-resolves every candidate; the existing pairs make it a
    // no-op for the link count, the missing de rows stay unmatched.
    $second = (new GoldenDictParser('en', 500, false, 'de'))
        ->import(goldendictBilingualFixturePath());

    expect($second['translations_linked'])->toBe(0)
        ->and($second['translation_targets_unmatched'])->toBe(5)
        // Candidates are re-extracted every run regardless.
        ->and($second['translation_candidates'])->toBe(7)
        ->and(Word::query()->where('language_id', $de->id)->count())->toBe(2);
});

it('makes the dry-run link tally honest against the database', function () {
    createLanguages(['en', 'de']);

    // Empty DB: every candidate is unmatched.
    $before = (new GoldenDictParser('en', 500, true, 'de'))
        ->import(goldendictBilingualFixturePath());
    expect($before['translations_linked'])->toBe(0)
        ->and($before['translation_targets_unmatched'])->toBe(7);

    // The target language's own import carries cat's equivalents.
    $de = Language::query()->where('code', 'de')->first();
    $noun = WordClass::query()->create(['language_id' => $de->id, 'slug' => 'noun', 'title' => 'noun']);
    foreach (['Katze', 'Kater'] as $word) {
        Word::query()->create(['word' => $word, 'l_word' => mb_strtolower($word), 'language_id' => $de->id, 'word_class_id' => $noun->id]);
    }

    // Same file against the now-populated DB: the preview reports exactly
    // the pairs that would link, and the dry run still writes nothing.
    $after = (new GoldenDictParser('en', 500, true, 'de'))
        ->import(goldendictBilingualFixturePath());

    expect($after['translations_linked'])->toBe(2)
        ->and($after['translation_targets_unmatched'])->toBe(5)
        ->and(Word::count())->toBe(2)
        ->and(WordTranslation::count())->toBe(0);
});

it('treats a profile match whose nodes hold only empty text as a miss', function () {
    Log::spy();
    createLanguages(['en', 'de']);
    $de = Language::query()->where('code', 'de')->first();

    // Every article's matched node holds only whitespace/brackets: the
    // definition and transcription kinds fall back to the heuristics —
    // never silently emptied — while the configured translations selector
    // vouches for its nodes, so a miss means no candidates rather than a
    // raw-block-text fallback.
    test()->artisan('goldendict:import', [
        'file' => goldendictEmptyMatchFixturePath(),
        '--lang' => 'en',
        '--target-lang' => 'de',
        '--profile' => 'example-classes',
    ])->assertSuccessful();

    // tree: the .sense div holds only whitespace — the definition comes from
    // the heuristic block segment.
    $tree = Word::query()->where('word', 'tree')->firstOrFail();
    expect($tree->definitions->pluck('definition')->all())->toBe(['Baum, Tanne']);

    // bird: the .ts span holds only empty brackets — the heuristic IPA from
    // the article body survives.
    $bird = Word::query()->where('word', 'bird')->firstOrFail();
    expect($bird->transcriptions->pluck('transcription')->all())->toBe(['vɔˈgəl']);

    // fish: the .tr span holds only whitespace — the vouched translations
    // selector missed, so no candidate is mined from the block text.
    $fish = Word::query()->where('word', 'fish')->firstOrFail();
    expect($fish->translations)->toBeNull();

    // No selector effectively matched anywhere, so all three articles count
    // (and sample) as profile misses for the author.
    Log::shouldHaveReceived('notice')
        ->once()
        ->withArgs(fn (string $message) => str_contains($message, 'in 3 articles'));

    // Link-only: nothing was staged for the missed articles and no de word
    // exists for any would-be candidate.
    expect(Word::query()->where('language_id', $de->id)->count())->toBe(0)
        ->and(Word::query()->where('word', 'Baum')->exists())->toBeFalse()
        ->and(Word::query()->where('word', 'Tanne')->exists())->toBeFalse()
        ->and(Word::query()->where('word', 'Vogel')->exists())->toBeFalse()
        ->and(Word::query()->where('word', 'Fisch')->exists())->toBeFalse();
});

it('drops translation candidates equal to the headword itself', function () {
    createLanguages(['en', 'de']);
    $en = Language::query()->where('code', 'en')->first();
    $de = Language::query()->where('code', 'de')->first();

    test()->artisan('goldendict:import', [
        'file' => goldendictSelfCandidateFixturePath(),
        '--lang' => 'en',
        '--target-lang' => 'de',
    ])->assertSuccessful();

    // "cat" is cat's own headword and "HOUSE" is house's in another case:
    // neither may be staged or linked as the word's own translation.
    expect(Word::query()->where('word', 'cat')->count())->toBe(1)
        ->and(Word::query()->where('word', 'HOUSE')->exists())->toBeFalse()
        ->and(Word::query()->where('language_id', $de->id)->count())->toBe(0)
        ->and(WordTranslation::count())->toBe(0);

    $cat = Word::query()->where('language_id', $en->id)->where('word', 'cat')->firstOrFail();
    $house = Word::query()->where('language_id', $en->id)->where('word', 'house')->firstOrFail();
    expect($cat->translations)->toBe(['Katze'])
        ->and($house->translations)->toBe(['Haus']);
});

it('trims quote marks off Cyrillic candidates without cutting letters in half', function () {
    createLanguages(['en', 'ru']);
    $ru = Language::query()->where('code', 'ru')->first();

    test()->artisan('goldendict:import', [
        'file' => goldendictCyrillicTrimFixturePath(),
        '--lang' => 'en',
        '--target-lang' => 'ru',
    ])->assertSuccessful();

    // "зал" ends in the same byte as "»" and "пар" in the same byte as "…":
    // a byte-wise trim would leave an invalid half character behind and the
    // staged string would be junk. The guillemet/schwabian quotes around
    // "золото" must still come off, and the staged strings are plain letters
    // (the ru target strips stress marks).
    $hall = Word::query()->where('word', 'hall')->firstOrFail();
    $steam = Word::query()->where('word', 'steam')->firstOrFail();
    $gold = Word::query()->where('word', 'gold')->firstOrFail();

    $words = collect([...$hall->translations, ...$steam->translations, ...$gold->translations])
        ->sort()
        ->values()
        ->all();
    expect($words)->toBe(['зал', 'золото', 'пар', 'пароход']);

    foreach ($words as $word) {
        expect(mb_check_encoding($word, 'UTF-8'))->toBeTrue();
    }

    // Link-only: the empty ru dictionary gains no rows.
    expect(Word::query()->where('language_id', $ru->id)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Oxford fix regression: pos/strip selectors, candidate guards, l_word
| target-word resolution
|--------------------------------------------------------------------------
*/

function goldendictPosStripFixturePath(): string
{
    return __DIR__.'/../Fixtures/goldendict-pos-strip.jsonl';
}

function goldendictCandidateGuardsFixturePath(): string
{
    return __DIR__.'/../Fixtures/goldendict-candidate-guards.jsonl';
}

function goldendictLwordReuseFixturePath(): string
{
    return __DIR__.'/../Fixtures/goldendict-lword-reuse.jsonl';
}

function goldendictLwordDedupeFixturePath(): string
{
    return __DIR__.'/../Fixtures/goldendict-lword-dedupe.jsonl';
}

it('assigns the word class from the profile pos selector with heuristic fallback', function () {
    createLanguages(['en', 'ru']);
    $en = Language::query()->where('code', 'en')->first();

    test()->artisan('goldendict:import', [
        'file' => goldendictPosStripFixturePath(),
        '--lang' => 'en',
        '--target-lang' => 'ru',
        '--profile' => 'test-pos-strip',
    ])->assertSuccessful();

    // Oxford's <div class="partOfSpeech">transitive verb</div> (17 chars)
    // maps through the raised length guard + POS dictionary to the verb
    // class — the single-word headword is NOT in unknown.
    $carry = Word::query()->where('language_id', $en->id)->where('word', 'carry')->firstOrFail();
    expect($carry->wordClass->slug)->toBe('verb');

    // Multi-word headword precedence is preserved: the pos selector says
    // "noun", but a two-word headword stays a phrase (ADR 0059).
    $zoomPast = Word::query()->where('language_id', $en->id)->where('word', 'zoom past')->firstOrFail();
    expect($zoomPast->wordClass->slug)->toBe('phrase');

    // An unmappable pos text ("usually singular") is a miss for the kind:
    // the heuristic pos hints decide, and they find nothing here.
    $fetch = Word::query()->where('language_id', $en->id)->where('word', 'fetch')->firstOrFail();
    expect($fetch->wordClass->slug)->toBe('unknown');
});

it('removes strip-selector nodes from every extraction before matching', function () {
    Log::spy();
    createLanguages(['en', 'ru']);
    $en = Language::query()->where('code', 'en')->first();

    test()->artisan('goldendict:import', [
        'file' => goldendictPosStripFixturePath(),
        '--lang' => 'en',
        '--target-lang' => 'ru',
        '--profile' => 'test-pos-strip',
    ])->assertSuccessful();

    // The exampleBlock's translation cell and sense block are gone from the
    // DOM before any extraction: no glued candidate, no junk definition,
    // while the content outside the block extracts normally.
    $glued = Word::query()->where('language_id', $en->id)->where('word', 'glued')->firstOrFail();
    expect($glued->translations)->toBe(['пронестись'])
        ->and($glued->definitions->pluck('definition')->all())->toBe(['a real definition']);
    expect(Word::query()->where('word', 'машины проносились мимо')->exists())->toBeFalse();

    // The headword of an article whose ONLY content was inside the stripped
    // block still imports (entries are never dropped) — with nothing else.
    $empty = Word::query()->where('word', 'empty')->firstOrFail();
    expect($empty->definitions)->toBeEmpty()
        ->and($empty->translations)->toBeNull();

    // Strip is subtraction, never a match: the empty article's strip match
    // does not count, so exactly that one article is a no-match article.
    Log::shouldHaveReceived('notice')
        ->once()
        ->withArgs(fn (string $message) => str_contains($message, 'in 1 article'));
});

it('rejects mixed-script and over-long translation candidates but keeps 4-token phrases', function () {
    createLanguages(['en', 'ru']);
    $ru = Language::query()->where('code', 'ru')->first();

    test()->artisan('goldendict:import', [
        'file' => goldendictCandidateGuardsFixturePath(),
        '--lang' => 'en',
        '--target-lang' => 'ru',
    ])->assertSuccessful();

    // "cars проносились" (glued example text: Latin + Cyrillic) and the
    // 6-token clause are rejected; the 3-token phrase is staged (plain
    // letters), and the 4-token phrase stays.
    $zoom = Word::query()->where('word', 'zoom')->firstOrFail();
    $paste = Word::query()->where('word', 'paste')->firstOrFail();
    expect($zoom->translations)->toBe(['один два три'])
        ->and($paste->translations)->toBe(['клейкая паста для бумаги'])
        // Link-only: the empty ru dictionary gains no rows.
        ->and(Word::query()->where('language_id', $ru->id)->count())->toBe(0);
});

it('links candidates to the existing target word by l_word, staged strings plain', function () {
    createLanguages(['en', 'ru']);
    $ru = Language::query()->where('code', 'ru')->first();

    // The target language already carries the unstressed row.
    $noun = WordClass::query()->create(['language_id' => $ru->id, 'slug' => 'noun', 'title' => 'noun']);
    $existing = Word::query()->create([
        'word' => 'машины',
        'l_word' => 'машины',
        'language_id' => $ru->id,
        'word_class_id' => $noun->id,
        'frequency' => Word::FREQUENCY_UNRANKED,
    ]);

    test()->artisan('goldendict:import', [
        'file' => goldendictLwordReuseFixturePath(),
        '--lang' => 'en',
        '--target-lang' => 'ru',
    ])->assertSuccessful();

    // The stressed candidate "маши́ны" normalizes to the existing l_word:
    // no new ru row, the existing row keeps its display form and class.
    expect(Word::query()->where('language_id', $ru->id)->count())->toBe(1);
    expect($existing->refresh()->word)->toBe('машины')
        ->and($existing->wordClass->slug)->toBe('noun');

    // The link points at the existing row's id; the candidate (plain
    // letters — the combining acute left it at staging) resolved, so the
    // trailing pass linked it and pruned it from the staged JSON.
    $zoom = Word::query()->where('word', 'zoom')->firstOrFail();
    expect(WordTranslation::isLinked($zoom->id, $existing->id))->toBeTrue()
        ->and($zoom->translations)->toBeNull();
});

it('dedupes candidate links by l_word against one existing row and reports zeros on re-run', function () {
    createLanguages(['en', 'ru']);
    $ru = Language::query()->where('code', 'ru')->first();

    // "машины" (car) and "маши́ны" (cars) normalize to one l_word: both
    // candidates link the single row the ru import carries.
    $noun = WordClass::query()->create(['language_id' => $ru->id, 'slug' => 'noun', 'title' => 'noun']);
    $existing = Word::query()->create([
        'word' => 'машины',
        'l_word' => 'машины',
        'language_id' => $ru->id,
        'word_class_id' => $noun->id,
        'frequency' => Word::FREQUENCY_UNRANKED,
    ]);

    $first = (new GoldenDictParser('en', 500, false, 'ru'))
        ->import(goldendictLwordDedupeFixturePath());
    expect($first['translations_linked'])->toBe(2)
        ->and($first['translation_targets_unmatched'])->toBe(0)
        ->and(Word::query()->where('language_id', $ru->id)->count())->toBe(1);

    $car = Word::query()->where('word', 'car')->firstOrFail();
    $cars = Word::query()->where('word', 'cars')->firstOrFail();
    expect(WordTranslation::isLinked($car->id, $existing->id))->toBeTrue()
        ->and(WordTranslation::isLinked($cars->id, $existing->id))->toBeTrue()
        ->and($car->translations)->toBe(['машины'])
        ->and($cars->translations)->toBe(['машины']);

    $second = (new GoldenDictParser('en', 500, false, 'ru'))
        ->import(goldendictLwordDedupeFixturePath());

    // The idempotent re-run reports zeros for linked and unmatched.
    expect($second['translations_linked'])->toBe(0)
        ->and($second['translation_targets_unmatched'])->toBe(0)
        ->and(Word::query()->where('language_id', $ru->id)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Stress-mark stripping, headword guards and the forms selector
|--------------------------------------------------------------------------
*/

function goldendictHeadwordGuardsFixturePath(): string
{
    return __DIR__.'/../Fixtures/goldendict-headword-guards.jsonl';
}

function goldendictStressStripFixturePath(): string
{
    return __DIR__.'/../Fixtures/goldendict-stress-strip.jsonl';
}

function goldendictFormsFixturePath(): string
{
    return __DIR__.'/../Fixtures/goldendict-forms.jsonl';
}

it('rejects junk multi-word headwords but keeps dictionary phrases', function () {
    createLanguages(['ru']);
    $ru = Language::query()->where('code', 'ru')->first();

    $stats = (new GoldenDictParser('ru'))
        ->import(goldendictHeadwordGuardsFixturePath());

    // The comma-split name list and the 4-token clause are article junk the
    // converter picked up; the 2- and 2-token phrases stay.
    expect($stats['headwords_rejected'])->toBe(2)
        ->and($stats['words_imported'])->toBe(2);

    expect(Word::query()->where('language_id', $ru->id)->pluck('word')->sort()->values()->all())
        ->toBe(['give up', 'до свидания'])
        ->and(Word::query()->where('language_id', $ru->id)->where('word', 'до свидания')->firstOrFail()->wordClass->slug)->toBe('phrase')
        ->and(Word::query()->where('language_id', $ru->id)->where('word', 'give up')->firstOrFail()->wordClass->slug)->toBe('phrase');
});

it('stores ru headwords and forms as plain letters and collapses stressed variants', function () {
    createLanguages(['ru']);
    $ru = Language::query()->where('code', 'ru')->first();

    test()->artisan('goldendict:import', [
        'file' => goldendictStressStripFixturePath(),
        '--lang' => 'ru',
    ])->assertSuccessful();

    // "приве́т" and "привет" collapse into one row: the stressed headword is
    // stripped to the plain letters before the unique triple is formed, and
    // the alt form loses its acute too.
    expect(Word::query()->where('language_id', $ru->id)->count())->toBe(1);

    $privet = Word::query()->where('language_id', $ru->id)->firstOrFail();
    expect($privet->word)->toBe('привет')
        ->and($privet->l_word)->toBe('привет')
        // Both collapsed articles' own-language definitions survive the
        // majority-script guard on the one plain row.
        ->and($privet->definitions->pluck('definition')->all())->toBe(['приветствие одно', 'приветствие два']);

    expect(Form::query()->where('word_id', $privet->id)->pluck('form')->all())->toBe(['привет', 'приветик']);
});

it('extracts forms from the profile forms selector and merges them with the alts', function () {
    createLanguages(['en']);

    test()->artisan('goldendict:import', [
        'file' => goldendictFormsFixturePath(),
        '--lang' => 'en',
        '--profile' => 'test-forms',
    ])->assertSuccessful();

    // The fms span splits on commas/semicolons/slashes into five forms,
    // beside each word's own lemma form.
    $be = Word::query()->where('word', 'be')->firstOrFail();
    expect($be->forms->pluck('form')->all())->toBe(['be', 'am', 'is', 'are', 'was', 'were'])
        ->and($be->definitions->pluck('definition')->all())->toBe(['to exist']);

    // The alts merge with the selector's forms instead of being replaced.
    $run = Word::query()->where('word', 'run')->firstOrFail();
    expect($run->forms->pluck('form')->all())->toBe(['run', 'running', 'runs', 'ran']);
});

/*
|--------------------------------------------------------------------------
| Correctness fixes: profile-forms strip, source-side segment strip,
| empty-after-strip guards
|--------------------------------------------------------------------------
*/

function goldendictProfileStressFormsFixturePath(): string
{
    return __DIR__.'/../Fixtures/goldendict-profile-stress-forms.jsonl';
}

it('stress-strips the profile-extracted forms of a stress-mark source', function () {
    createLanguages(['ru']);
    $ru = Language::query()->where('code', 'ru')->first();

    test()->artisan('goldendict:import', [
        'file' => goldendictProfileStressFormsFixturePath(),
        '--lang' => 'ru',
        '--profile' => 'test-forms',
    ])->assertSuccessful();

    // The alt and the selector's forms store as plain letters: the merge
    // must not append the selector's forms raw beside the stripped alts,
    // and the marks-only selector piece (a bare combining acute) stores
    // nothing rather than an empty form.
    $house = Word::query()->where('language_id', $ru->id)->where('word', 'дом')->firstOrFail();
    expect($house->forms->pluck('form')->all())->toBe(['дом', 'домище', 'дома', 'домов'])
        ->and(Form::query()->where('form', '')->count())->toBe(0);
});

function goldendictSourceEchoFixturePath(): string
{
    return __DIR__.'/../Fixtures/goldendict-source-echo.jsonl';
}

it('removes a leading stressed headword echo with no profile (source-side segment strip)', function () {
    createLanguages(['ru', 'en']);
    $en = Language::query()->where('code', 'en')->first();

    test()->artisan('goldendict:import', [
        'file' => goldendictSourceEchoFixturePath(),
        '--lang' => 'ru',
        '--target-lang' => 'en',
    ])->assertSuccessful();

    // The article opens with its own stressed headword ("до́м n. house"):
    // the segment strips first (the SOURCE is a stress-mark language), so
    // the echo matches the already-stripped headword and the glued
    // equivalent "house" is not lost to the mixed-script guard — while the
    // plain echo article ("коза n. goat") behaves as before.
    $home = Word::query()->where('word', 'дом')->firstOrFail();
    $goat = Word::query()->where('word', 'коза')->firstOrFail();
    expect($home->translations)->toBe(['house'])
        ->and($goat->translations)->toBe(['goat'])
        // Link-only: the empty en dictionary gains no rows or links.
        ->and(Word::query()->where('language_id', $en->id)->count())->toBe(0)
        ->and(WordTranslation::count())->toBe(0);
});

function goldendictEmptyStripFixturePath(): string
{
    return __DIR__.'/../Fixtures/goldendict-empty-strip.jsonl';
}

it('skips and counts a headword or alt that strips to the empty string', function () {
    createLanguages(['ru']);
    $ru = Language::query()->where('code', 'ru')->first();

    $stats = (new GoldenDictParser('ru'))
        ->import(goldendictEmptyStripFixturePath());

    // The marks-only record (a bare combining acute as the headword) is
    // rejected junk, counted before the article parses; the house record
    // imports with its marks-only alt skipped, never stored as ''.
    expect($stats['headwords_rejected'])->toBe(1)
        ->and($stats['words_imported'])->toBe(1)
        ->and(Word::query()->where('word', '')->count())->toBe(0)
        ->and(Word::query()->where('l_word', '')->count())->toBe(0)
        ->and(Form::query()->where('form', '')->count())->toBe(0)
        ->and(Form::query()->where('l_word', '')->count())->toBe(0);

    $house = Word::query()->where('language_id', $ru->id)->where('word', 'дом')->firstOrFail();
    expect($house->forms->pluck('form')->all())->toBe(['дом', 'домище']);
});

/*
|--------------------------------------------------------------------------
| Majority-script definition guard
|--------------------------------------------------------------------------
*/

function goldendictDefLangRuFixturePath(): string
{
    return __DIR__.'/../Fixtures/goldendict-deflang-ru.jsonl';
}

function goldendictDefLangEnFixturePath(): string
{
    return __DIR__.'/../Fixtures/goldendict-deflang-en.jsonl';
}

it('drops other-language definition segments by the majority-script guard with the stat', function () {
    createLanguages(['en', 'ru']);
    $ru = Language::query()->where('code', 'ru')->first();
    $en = Language::query()->where('code', 'en')->first();

    // The Mostitsky ru-en shape: a ru article whose English prose segments
    // are the other language's article text, not ru definitions — dropped;
    // the word's own language segment stores.
    $ruStats = (new GoldenDictParser('ru'))->import(goldendictDefLangRuFixturePath());

    $home = Word::query()->where('language_id', $ru->id)->where('word', 'дом')->firstOrFail();
    expect($home->definitions->pluck('definition')->all())->toBe(['жилое здание'])
        ->and($ruStats['definitions_skipped_foreign'])->toBe(1);

    // The surviving segments still truncate to the 500-char column cap.
    $long = Word::query()->where('language_id', $ru->id)->where('word', 'длинный')->firstOrFail();
    expect($long->definitions)->toHaveCount(1)
        ->and(mb_strlen($long->definitions->first()->definition))->toBe(500);

    // The Oxford en-ru shape, symmetric: a Cyrillic prose segment on an en
    // word drops, the English one stays.
    $enStats = (new GoldenDictParser('en'))->import(goldendictDefLangEnFixturePath());

    $dog = Word::query()->where('language_id', $en->id)->where('word', 'dog')->firstOrFail();
    expect($dog->definitions->pluck('definition')->all())->toBe(['a domesticated canid'])
        ->and($enStats['definitions_skipped_foreign'])->toBe(1);
});
