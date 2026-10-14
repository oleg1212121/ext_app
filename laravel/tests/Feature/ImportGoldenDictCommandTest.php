<?php

use App\Models\Definition;
use App\Models\Form;
use App\Models\Language;
use App\Models\Transcription;
use App\Models\TranscriptionType;
use App\Models\Word;
use App\Models\WordClass;
use App\Models\WordTranslation;

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

it('accepts the inert --target-lang, --profile and --no-link flags without side effects', function () {
    $this->artisan('goldendict:import', [
        'file' => goldendictFixturePath(),
        '--lang' => 'be',
        '--target-lang' => 'ru',
        '--profile' => 'sample',
        '--no-link' => true,
    ])->assertSuccessful();

    expect(WordTranslation::count())->toBe(0);
    expect(Word::query()->whereNotNull('translations')->count())->toBe(0);
});
