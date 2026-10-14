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
    expect($cat->definitions->pluck('definition')->implode(' '))->toContain('feline');
    expect($cat->transcriptions->count())->toBe(1);
    expect($cat->transcriptions->first()->transcription)->toBe('kæt');
    expect($cat->transcriptions->first()->transcriptionType->slug)->toBe('ipa');

    // Slash IPA, several sense segments, and the alternate headword as a form.
    $hello = Word::query()->where('language_id', $be->id)->where('word', 'hello')->first();
    expect($hello->wordClass->slug)->toBe('interjection');
    expect($hello->definitions->count())->toBe(3);
    expect($hello->transcriptions->first()->transcription)->toBe('həˈləʊ');
    expect(Form::query()->where('word_id', $hello->id)->pluck('form')->all())->toBe(['hi']);

    // Multi-word headwords go to the phrase class despite the POS hint (ADR 0059).
    $giveUp = Word::query()->where('language_id', $be->id)->where('word', 'give up')->first();
    expect($giveUp->wordClass->slug)->toBe('phrase');

    // Cyrillic stress marks: display word keeps them, l_word strips them.
    $privet = Word::query()->where('language_id', $be->id)->where('word', 'приве́т')->first();
    expect($privet)->not->toBeNull();
    expect($privet->l_word)->toBe('привет');
    expect($privet->wordClass->slug)->toBe('unknown');
    expect($privet->definitions->first()->definition)->toContain('здравствуйте');

    // Definitions truncate to the 500-char column cap.
    $long = Word::query()->where('language_id', $be->id)->where('word', 'long')->first();
    expect(mb_strlen($long->definitions->first()->definition))->toBe(500);

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
    expect(Definition::count())->toBe(7);
    expect(Transcription::count())->toBe(2);
    expect(Form::count())->toBe(1);

    // A frequency rank curated after the first import survives a re-import:
    // the upsert refreshes l_word only, never the sentinel onto ranked words.
    $cat = Word::query()->where('word', 'cat')->first();
    $cat->forceFill(['frequency' => 500.5])->save();
    $import();

    expect((float) $cat->refresh()->frequency)->toBe(500.5);
});

it('imports monolingually with an extraction profile and writes no links', function () {
    // A profile without --target-lang tunes definitions/transcriptions only;
    // the bilingual machinery stays dormant.
    $this->artisan('goldendict:import', [
        'file' => goldendictProfileFixturePath(),
        '--lang' => 'be',
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

it('imports a bilingual fixture end-to-end into a brand-new target language', function () {
    createLanguages(['en', 'de']);
    $en = Language::query()->where('code', 'en')->first();
    $de = Language::query()->where('code', 'de')->first();

    importBilingual()
        ->expectsOutputToContain('Import completed!')
        ->assertSuccessful();

    // The target dictionary grew: seven target words from the three real
    // articles (the junk article contributes none).
    expect(Word::query()->where('language_id', $de->id)->count())->toBe(7);

    // Display form preserved, lookup key normalized, source class mapped
    // into the target language, unranked frequency sentinel applied.
    $katze = Word::query()->where('language_id', $de->id)->where('word', 'Katze')->firstOrFail();
    expect($katze->l_word)->toBe('katze')
        ->and($katze->wordClass->slug)->toBe('noun')
        ->and((float) $katze->frequency)->toBe((float) Word::FREQUENCY_UNRANKED);

    // Multi-word candidates go to the phrase class (ADR 0059) regardless of
    // the source word's own class.
    $aufhoeren = Word::query()->where('language_id', $de->id)->where('word', 'aufhören mit')->firstOrFail();
    expect($aufhoeren->wordClass->slug)->toBe('phrase');

    // The brand-new target language's word classes auto-created with the
    // slug as placeholder title (ensureWordClass convention).
    $slugs = WordClass::query()->where('language_id', $de->id)->pluck('title', 'slug');
    expect($slugs->keys()->sort()->values()->all())->toBe(['interjection', 'noun', 'phrase']);
    expect($slugs['noun'])->toBe('noun');

    // Canonical word_translations links (ADR 0020): source ↔ each target word.
    $cat = Word::query()->where('language_id', $en->id)->where('word', 'cat')->firstOrFail();
    $kater = Word::query()->where('language_id', $de->id)->where('word', 'Kater')->firstOrFail();
    expect(WordTranslation::isLinked($cat->id, $katze->id))->toBeTrue()
        ->and(WordTranslation::isLinked($cat->id, $kater->id))->toBeTrue()
        ->and(WordTranslation::count())->toBe(7);

    // Raw translation strings staged into words.translations JSON like the
    // Wiktionary import (flat, deduplicated list).
    expect($cat->translations)->toBe(['Katze', 'Kater']);

    // The monolingual side is untouched by bilingual mode: definitions and
    // transcriptions still land.
    expect($cat->definitions->count())->toBe(1)
        ->and($cat->transcriptions->first()->transcription)->toBe('kæt');
});

it('shows the imported translation in the word popup payload', function () {
    createLanguages(['en', 'de']);
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
    // imported source word's id: the link must be canonicalized (swapped),
    // and the existing row must be reused (firstOrCreate semantics).
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

    importBilingual()->assertSuccessful();
    importBilingual()->assertSuccessful();

    expect(Word::query()->where('language_id', $de->id)->count())->toBe(7)
        ->and(WordTranslation::count())->toBe(7)
        ->and(Definition::count())->toBe(6);

    $cat = Word::query()->where('word', 'cat')->firstOrFail();
    expect($cat->translations)->toBe(['Katze', 'Kater']);

    // Translations staged by another source survive a re-import: the merge
    // pass unions the JSON, the upsert never replaces it.
    $cat->forceFill(['translations' => ['Katze', 'Kater', 'Kätzchen']])->save();
    importBilingual()->assertSuccessful();

    expect($cat->refresh()->translations)->toBe(['Katze', 'Kater', 'Kätzchen'])
        ->and(WordTranslation::count())->toBe(7);
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

it('keeps stress marks in the target display word and strips them from l_word', function () {
    createLanguages(['en', 'ru']);
    $ru = Language::query()->where('code', 'ru')->first();

    test()->artisan('goldendict:import', [
        'file' => goldendictRussianFixturePath(),
        '--lang' => 'en',
        '--target-lang' => 'ru',
    ])->assertSuccessful();

    // Display form keeps the combining acute; the lookup key strips it
    // (StressMarks rule for Cyrillic targets).
    $koshka = Word::query()->where('language_id', $ru->id)->where('word', "ко\u{0301}шка")->firstOrFail();
    expect($koshka->l_word)->toBe('кошка');

    // Headword and IPA stripped out of the article line before extraction.
    $sobaka = Word::query()->where('language_id', $ru->id)->where('word', "соба\u{0301}ка")->firstOrFail();
    expect($sobaka->l_word)->toBe('собака');

    $dog = Word::query()->where('word', 'dog')->firstOrFail();
    expect(WordTranslation::isLinked($dog->id, $sobaka->id))->toBeTrue()
        ->and($dog->translations)->toBe(["соба\u{0301}ка"]);
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
    // heuristic would never find it), translations only from .tr nodes.
    $tree = Word::query()->where('language_id', $en->id)->where('word', 'tree')->firstOrFail();
    expect($tree->definitions->pluck('definition')->all())->toBe(['a tall plant with a woody trunk'])
        ->and($tree->transcriptions->pluck('transcription')->all())->toBe(['tri:'])
        ->and($tree->translations)->toBe(['Baum'])
        ->and(Word::query()->where('language_id', $de->id)->where('word', 'Baum')->exists())->toBeTrue()
        ->and(Word::query()->where('language_id', $de->id)->where('word', 'a tall plant with a woody trunk')->exists())->toBeFalse();

    // Per-selector fallback: bird's .tr2 class matches nothing, so its
    // translations come from the heuristic segments while its definitions
    // still come from the profile's .sense selector.
    $bird = Word::query()->where('language_id', $en->id)->where('word', 'bird')->firstOrFail();
    expect($bird->definitions->pluck('definition')->all())->toBe(['animal with feathers'])
        ->and(Word::query()->where('language_id', $de->id)->where('word', 'Vogel')->exists())->toBeTrue()
        ->and(Word::query()->where('language_id', $de->id)->where('word', 'animal with feathers')->exists())->toBeTrue();

    // Plain-text article (StarDict sametypesequence=m shape): every selector
    // misses, the generic heuristics handle the whole entry — nothing drops.
    $dog = Word::query()->where('language_id', $en->id)->where('word', 'dog')->firstOrFail();
    expect($dog->definitions->pluck('definition')->all())->toBe(['dog [dɔg] n. Hund, Köter'])
        ->and($dog->transcriptions->pluck('transcription')->all())->toBe(['dɔg'])
        ->and(Word::query()->where('language_id', $de->id)->where('word', 'Hund')->exists())->toBeTrue()
        ->and(Word::query()->where('language_id', $de->id)->where('word', 'Köter')->exists())->toBeTrue();

    // The demo: the SAME jsonl again without the profile visibly changes what
    // is extracted — the heuristic now stages the phrase candidate and the raw
    // block definition the profile had excluded.
    $import()->assertSuccessful();

    expect(Word::query()->where('language_id', $de->id)->where('word', 'a tall plant with a woody trunk')->exists())->toBeTrue()
        ->and($tree->refresh()->definitions->count())->toBe(2)
        ->and($tree->translations)->toBe(['Baum', 'a tall plant with a woody trunk']);
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

function goldendictEmptyMatchFixturePath(): string
{
    return __DIR__.'/../Fixtures/goldendict-profile-empty-match.jsonl';
}

it('counts translation candidates in real runs, not only dry runs', function () {
    createLanguages(['en', 'de']);

    $stats = (new GoldenDictParser('en', 500, false, 'de'))
        ->import(goldendictBilingualFixturePath());

    // The four articles stage 7 candidates (cat 2, hello 3, give up 2; the
    // junk article none) — the same number the dry-run tally reports.
    $dryStats = (new GoldenDictParser('en', 500, true, 'de'))
        ->import(goldendictBilingualFixturePath());

    expect($stats['translation_candidates'])->toBe(7)
        ->and($stats['target_words_created'])->toBe(7)
        ->and($stats['translations_linked'])->toBe(7)
        ->and($dryStats['translation_candidates'])->toBe(7);
});

it('counts only genuinely created target words on re-imports', function () {
    createLanguages(['en', 'de']);

    $first = (new GoldenDictParser('en', 500, false, 'de'))
        ->import(goldendictBilingualFixturePath());
    expect($first['target_words_created'])->toBe(7);

    // A re-import upserts the same 7 rows — none of them is a creation.
    $second = (new GoldenDictParser('en', 500, false, 'de'))
        ->import(goldendictBilingualFixturePath());

    expect($second['target_words_created'])->toBe(0)
        ->and($second['translations_linked'])->toBe(0)
        // Candidates are re-extracted every run regardless.
        ->and($second['translation_candidates'])->toBe(7)
        ->and(Word::query()->where('word', 'Katze')->count())->toBe(1);
});

it('makes the dry-run target-word tally honest against the database', function () {
    createLanguages(['en', 'de']);

    // Empty DB: every candidate would be a creation.
    $before = (new GoldenDictParser('en', 500, true, 'de'))
        ->import(goldendictBilingualFixturePath());
    expect($before['target_words_created'])->toBe(7);

    (new GoldenDictParser('en', 500, false, 'de'))
        ->import(goldendictBilingualFixturePath());

    // Same file against the now-populated DB: the preview must not overstate
    // would-be creations, and the existing target lookups must not count as
    // would-be-created either.
    $after = (new GoldenDictParser('en', 500, true, 'de'))
        ->import(goldendictBilingualFixturePath());

    expect($after['target_words_created'])->toBe(0)
        ->and($after['lookups_created'])->toBe(0)
        ->and($after['translation_candidates'])->toBe(7);
});

it('treats a profile match whose nodes hold only empty text as a miss', function () {
    Log::spy();
    createLanguages(['en', 'de']);

    // Every article's matched node holds only whitespace/brackets: the
    // profile kind is a miss and must fall back to the heuristics — never
    // silently empty that extraction.
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

    // fish: the .tr span holds only whitespace — the candidate comes from
    // the heuristic segments.
    $fish = Word::query()->where('word', 'fish')->firstOrFail();
    expect($fish->translations)->toBe(['Fisch']);

    // No selector effectively matched anywhere, so all three articles count
    // (and sample) as profile misses for the author.
    Log::shouldHaveReceived('notice')
        ->once()
        ->withArgs(fn (string $message) => str_contains($message, 'in 3 articles'));

    expect(Word::query()->where('word', 'Baum')->exists())->toBeTrue()
        ->and(Word::query()->where('word', 'Tanne')->exists())->toBeTrue()
        ->and(Word::query()->where('word', 'Vogel')->exists())->toBeTrue()
        ->and(Word::query()->where('word', 'Fisch')->exists())->toBeTrue();
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
    // neither may become a junk target word linking the word to itself.
    expect(Word::query()->where('word', 'cat')->count())->toBe(1)
        ->and(Word::query()->where('word', 'HOUSE')->exists())->toBeFalse()
        ->and(Word::query()->where('language_id', $de->id)->pluck('word')->sort()->values()->all())
        ->toBe(['Haus', 'Katze'])
        ->and(WordTranslation::count())->toBe(2);

    $cat = Word::query()->where('language_id', $en->id)->where('word', 'cat')->firstOrFail();
    $house = Word::query()->where('language_id', $en->id)->where('word', 'house')->firstOrFail();
    expect($cat->translations)->toBe(['Katze'])
        ->and($house->translations)->toBe(['Haus']);
});
