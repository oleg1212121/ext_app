<?php

use App\Models\EntityWord;
use App\Models\Form;
use App\Models\Tag;
use App\Models\TranscriptionType;
use App\Models\UserWord;
use App\Models\Word;
use App\Models\WordClass;
use App\Models\WordTranslation;
use Illuminate\Support\Facades\DB;

const ACUTE = "\u{0301}"; // combining acute — the Cyrillic stress mark

/**
 * The mini-DB mirroring the repairable state: an untwinned stressed ru
 * display word, unknown-class twins (one behind a ˈ-marked IPA survivor)
 * carrying the full merge machinery — links, users, events, tags, ledger —
 * the adj/adjective split with case-variant twins (legal cross-class, one
 * l_word), and words missing their lemma form — plus the en diacritic rows
 * the strip must NOT touch. Same-class same-l_word pairs are impossible
 * under the (l_word, language, class) unique, so the stressed-twin merge
 * coverage lives in the words:normalize l_word merge phase tests.
 */
function seedNormalizeFixture(): array
{
    $languages = createLanguages();
    $en = $languages['en']->id;
    $ru = $languages['ru']->id;

    $enIpa = TranscriptionType::query()->firstOrCreate(
        ['language_id' => $en, 'slug' => 'ipa'],
        ['title' => 'IPA'],
    );
    $ruIpa = TranscriptionType::query()->firstOrCreate(
        ['language_id' => $ru, 'slug' => 'ipa'],
        ['title' => 'IPA'],
    );

    $transcribe = fn (int $wordId, int $typeId, string $ipa) => DB::table('transcriptions')->insert([
        'transcription' => $ipa,
        'word_id' => $wordId,
        'transcription_type_id' => $typeId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $define = fn (Word $word, string $text) => DB::table('definitions')->insert([
        'definition' => $text,
        'word_id' => $word->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $form = fn (Word $word, string $value) => Form::query()->create([
        'word_id' => $word->id,
        'form' => $value,
        'l_word' => mb_strtolower($value),
    ]);

    // --- Phase 1: stressed ru display words/forms -------------------------

    $home = createWord('en', 'home', 'noun');
    $sobaka = createWord('ru', 'собака', 'noun');
    $voda = createWord('ru', 'во'.ACUTE.'да', 'noun', ['l_word' => 'вода']);
    $cafe = createWord('en', 'cafe'.ACUTE, 'noun', ['l_word' => 'cafe'.ACUTE]);
    $form($cafe, 'cafe'.ACUTE); // legitimate en diacritic — never touched

    // --- Phase 2: unknown-class twins (they carry the merge machinery) ----

    $die = createWord('en', 'die', 'noun', ['frequency' => 100]);
    $define($die, 'a cube');
    $transcribe($die->id, $enIpa->id, '/daɪ/');
    $dieUnknown = createWord('en', 'die', 'unknown', ['frequency' => 50, 'translations' => ['sterben']]);
    $define($dieUnknown, 'unknown-entry gloss');
    $form($dieUnknown, 'die');
    $transcribe($dieUnknown->id, $enIpa->id, '/daɪ/'); // dedupes onto the survivor's row
    $transcribe($dieUnknown->id, $enIpa->id, '/daɪɹ/'); // moves

    $cat = createWord('en', 'cat', 'noun');
    $transcribe($cat->id, $enIpa->id, '/ˈkæt/'); // stressed IPA — the survivor wins
    $catUnknown = createWord('en', 'cat', 'unknown');
    $transcribe($catUnknown->id, $enIpa->id, '/kæt/'); // gate: dropped, not stacked

    $zilch = createWord('en', 'zilch', 'unknown'); // no classed twin — stays

    // --- Phase 3: adj → adjective -----------------------------------------

    $adjective = WordClass::query()->updateOrCreate(
        ['language_id' => $en, 'slug' => 'adjective'],
        ['title' => 'Adjective'],
    );
    $adj = WordClass::query()->updateOrCreate(
        ['language_id' => $en, 'slug' => 'adj'],
        ['title' => 'Adj'],
    );
    $wordIn = fn (string $word, int $classId) => Word::query()->create([
        'word' => $word,
        'l_word' => mb_strtolower($word),
        'language_id' => $en,
        'word_class_id' => $classId,
    ]);
    $smallCanon = $wordIn('small', $adjective->id);
    $smallAdj = $wordIn('small', $adj->id);
    $define($smallAdj, 'covering little space');
    $form($smallAdj, 'small');
    $form($smallCanon, 'small'); // the survivor's own — the moved form dedupes
    // Case-variant twins: cross-class rows are legal under the unique, but
    // the l_word join must still fold "Big" into "big" — a display-exact
    // join would leave it twinless and the reclass would collide.
    $bigCanon = $wordIn('big', $adjective->id);
    $bigAdj = $wordIn('Big', $adj->id);
    $define($bigAdj, 'of considerable size');

    // --- Phase 4: missing lemma forms --------------------------------------

    $march = createWord('en', 'March', 'noun', ['l_word' => 'march']); // no forms

    // --- Shared merge machinery: links, users, ledger -----------------------

    WordTranslation::link($dieUnknown->id, $home->id); // absorbed a-arm
    WordTranslation::link($sobaka->id, $dieUnknown->id); // absorbed b-arm

    $entity = createEntity('ru');
    EntityWord::query()->create([
        'entity_id' => $entity->id,
        'word_id' => $dieUnknown->id,
        'l_word' => 'die',
        'token' => 'die',
    ]);

    $userA = approvedUser();
    $userB = approvedUser();
    $knows = fn (int $userId, Word $word, int $familiarity) => UserWord::query()->create([
        'user_id' => $userId, 'word_id' => $word->id, 'familiarity' => $familiarity,
    ]);
    $knows($userA->id, $die, 70); // collision: the survivor's row wins
    $knows($userA->id, $dieUnknown, 40); // deduped away
    $knows($userB->id, $dieUnknown, 90); // repointed

    $event = fn (int $userId, Word $word, string $rowKey) => DB::table('user_word_event')->insert([
        'user_id' => $userId, 'word_id' => $word->id, 'row_key' => $rowKey,
        'kind' => 'lookup', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $event($userA->id, $die, 'es:1'); // collision: survivor's row wins
    $event($userA->id, $dieUnknown, 'es:1'); // deduped away
    $event($userB->id, $dieUnknown, 'es:2'); // repointed

    $tag = Tag::query()->create(['slug' => 'core', 'name' => 'Core']);
    foreach ([$die, $dieUnknown] as $word) {
        DB::table('tagged_words')->insert([
            'word_id' => $word->id, 'tag_id' => $tag->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    foreach ([$die, $dieUnknown] as $word) {
        DB::table('word_translation_fetches')->insert([
            'word_id' => $word->id, 'target_language_id' => $ru, 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return compact('en', 'ru', 'home', 'sobaka', 'voda', 'cafe', 'die', 'dieUnknown', 'cat', 'catUnknown', 'zilch', 'adjective', 'adj', 'smallCanon', 'smallAdj', 'bigCanon', 'bigAdj', 'march', 'userA', 'userB', 'tag');
}

/**
 * The full observable state, for dry-run / re-run equality snapshots.
 */
function normalizeSnapshot(): string
{
    return json_encode([
        'words' => Word::query()->orderBy('id')->get(['id', 'word', 'l_word', 'word_class_id', 'frequency', 'translations'])->toArray(),
        'forms' => DB::table('forms')->orderBy('id')->get(['id', 'word_id', 'form', 'l_word'])->toArray(),
        'transcriptions' => DB::table('transcriptions')->orderBy('id')->get(['id', 'word_id', 'transcription'])->toArray(),
        'definitions' => DB::table('definitions')->orderBy('id')->get(['id', 'word_id', 'definition'])->toArray(),
        'links' => DB::table('word_translations')->orderBy('id')->get(['id', 'word_a_id', 'word_b_id'])->toArray(),
        'entity_words' => DB::table('entity_words')->orderBy('id')->get(['id', 'word_id'])->toArray(),
        'user_word' => DB::table('user_word')->orderBy('id')->get(['id', 'user_id', 'word_id', 'familiarity'])->toArray(),
        'user_word_event' => DB::table('user_word_event')->orderBy('id')->get(['id', 'user_id', 'word_id', 'row_key', 'kind'])->toArray(),
        'tagged_words' => DB::table('tagged_words')->orderBy('id')->get(['id', 'word_id', 'tag_id'])->toArray(),
        'fetches' => DB::table('word_translation_fetches')->orderBy('id')->get(['id', 'word_id', 'target_language_id'])->toArray(),
        'classes' => DB::table('word_classes')->orderBy('id')->get(['id', 'language_id', 'slug'])->toArray(),
    ]);
}

function runNormalize(array $options = []): void
{
    test()->artisan('words:normalize', $options)->assertSuccessful();
}

beforeEach(function () {
    $this->fixture = seedNormalizeFixture();
});

it('strips an untwinned stressed ru display word in place and leaves en diacritics alone', function () {
    extract($this->fixture, EXTR_SKIP);

    runNormalize();

    // The stressed word had no twin (same-class same-key pairs cannot even
    // exist under the unique key): it strips in place, key and display.
    expect($voda->fresh()->word)->toBe('вода')
        ->and($voda->fresh()->l_word)->toBe('вода');

    // en diacritics are legitimate spelling — untouched.
    expect($cafe->fresh()->word)->toBe('cafe'.ACUTE)
        ->and(Form::query()->where('word_id', $cafe->id)->value('form'))->toBe('cafe'.ACUTE);
});

it('merges unknown-class twins into their classed l_word rows, moving children, links, users, ledger and the better rank', function () {
    extract($this->fixture, EXTR_SKIP);

    runNormalize();

    // The die twins merged: children moved, the duplicate transcription
    // deduped onto the survivor's existing row, the distinct one moved.
    expect(Word::query()->where('word', 'die')->count())->toBe(1)
        ->and((int) Word::query()->where('word', 'die')->value('id'))->toBe($die->id)
        ->and(DB::table('transcriptions')->where('word_id', $die->id)->pluck('transcription')->sort()->values()->all())
        ->toBe(['/daɪ/', '/daɪɹ/'])
        ->and(DB::table('definitions')->where('word_id', $die->id)->pluck('definition')->sort()->values()->all())
        ->toBe(['a cube', 'unknown-entry gloss'])
        ->and($die->fresh()->translations)->toBe(['sterben'])
        ->and(Form::query()->where('word_id', $die->id)->pluck('form')->all())->toBe(['die']);

    // cat: the survivor already carries a ˈ-marked IPA — the twin's
    // transcription is dropped (cmudict never stacks on Kaikki stress data).
    expect(Word::query()->where('word', 'cat')->count())->toBe(1)
        ->and((int) Word::query()->where('word', 'cat')->value('id'))->toBe($cat->id)
        ->and(DB::table('transcriptions')->where('word_id', $cat->id)->pluck('transcription')->all())
        ->toBe(['/ˈkæt/']);

    // An unknown row with no classed twin survives untouched.
    expect($zilch->fresh())->not->toBeNull()
        ->and($zilch->fresh()->wordClass->slug)->toBe('unknown');

    // Links re-pointed through both arms; two seeds remain, canonical.
    $linked = fn (int $one, int $other) => WordTranslation::query()
        ->where('word_a_id', min($one, $other))
        ->where('word_b_id', max($one, $other))
        ->exists();
    expect(WordTranslation::query()->count())->toBe(2)
        ->and($linked($die->id, $home->id))->toBeTrue()
        ->and($linked($sobaka->id, $die->id))->toBeTrue();

    // entity_words re-pointed.
    expect(EntityWord::query()->where('l_word', 'die')->value('word_id'))->toBe($die->id);

    // user_word rows repointed: the collision kept the survivor's familiarity,
    // the lone twin's row moved.
    expect(DB::table('user_word')->where('user_id', $userA->id)->where('word_id', $die->id)->value('familiarity'))->toBe(70)
        ->and(DB::table('user_word')->where('user_id', $userB->id)->where('word_id', $die->id)->value('familiarity'))->toBe(90)
        ->and(DB::table('user_word')->where('word_id', $dieUnknown->id)->count())->toBe(0);

    // Events dedupe onto the survivor's rows; the lone one moves.
    expect(DB::table('user_word_event')->where('word_id', $die->id)->count())->toBe(2)
        ->and(DB::table('user_word_event')->where('word_id', $dieUnknown->id)->count())->toBe(0);

    // Tag and fetch collisions keep the survivor's rows.
    expect(DB::table('tagged_words')->where('tag_id', $tag->id)->pluck('word_id')->unique()->values()->all())
        ->toBe([$die->id])
        ->and(DB::table('word_translation_fetches')->where('target_language_id', $ru)->pluck('word_id')->unique()->values()->all())
        ->toBe([$die->id]);

    // Frequency folds as LEAST — the better rank (50) lands on the survivor.
    expect((float) $die->fresh()->frequency)->toBe(50.0);
});

it('merges the adj alias class into adjective, folding case-variant twins and reclassing the leftovers', function () {
    extract($this->fixture, EXTR_SKIP);

    runNormalize();

    // 'small' merged onto the canonical row: children moved, the moved form
    // deduped onto the survivor's own.
    expect(Word::query()->where('word', 'small')->count())->toBe(1)
        ->and((int) Word::query()->where('word', 'small')->value('id'))->toBe($smallCanon->id)
        ->and((int) $smallCanon->fresh()->word_class_id)->toBe($adjective->id)
        ->and(DB::table('definitions')->where('word_id', $smallCanon->id)->pluck('definition')->all())
        ->toBe(['covering little space'])
        ->and(Form::query()->where('word_id', $smallCanon->id)->count())->toBe(1);

    // The case-variant alias row "Big" folds into the canonical "big" row —
    // the l_word join finds what a display-exact join would miss (and the
    // reclass would collide with the unique key).
    expect(Word::query()->where('word', 'Big')->count())->toBe(0)
        ->and(DB::table('definitions')->where('word_id', $bigCanon->id)->pluck('definition')->all())
        ->toBe(['of considerable size'])
        ->and((int) $bigCanon->fresh()->word_class_id)->toBe($adjective->id);

    // The orphan alias class is gone; the canonical class survives.
    expect(WordClass::query()->where('slug', 'adj')->count())->toBe(0)
        ->and($adjective->fresh())->not->toBeNull();
});

it('backfills the lemma form until every en+ru word carries itself', function () {
    extract($this->fixture, EXTR_SKIP);

    runNormalize();

    // March gained its own form (display casing kept, key lowercased).
    expect(Form::query()->where('word_id', $march->id)->orderBy('id')->get(['form', 'l_word'])->map(
        fn ($row) => [$row->form, $row->l_word],
    )->all())->toBe([['March', 'march']])
        // The survivor's pre-existing lemma form was not duplicated.
        ->and(Form::query()->where('word_id', $die->id)->where('form', 'die')->count())->toBe(1)
        // Insert-new-only: en + ru end with zero words missing their lemma form.
        ->and(Word::query()
            ->whereIn('language_id', [$en, $ru])
            ->whereNotExists(fn ($query) => $query
                ->selectRaw(1)
                ->from('forms')
                ->whereColumn('forms.word_id', 'words.id')
                ->whereColumn('forms.form', 'words.word'))
            ->count())->toBe(0);
});

it('is idempotent — the second run changes nothing', function () {
    runNormalize();

    $afterFirst = normalizeSnapshot();

    runNormalize();

    expect(normalizeSnapshot())->toBe($afterFirst);
});

it('reaches the same repaired state through tiny chunks', function () {
    extract($this->fixture, EXTR_SKIP);

    // --chunk=2 forces multiple scan passes, chunked temp-map inserts and
    // values-join updates, and merges landing in different passes.
    test()->artisan('words:normalize', ['--chunk' => '2'])->assertSuccessful();

    expect($voda->fresh()->word)->toBe('вода')
        ->and(Word::query()->where('word', 'die')->count())->toBe(1)
        ->and(Form::query()->where('word_id', $die->id)->pluck('form')->sort()->values()->all())
        ->toBe(['die'])
        ->and(Word::query()->where('word', 'Big')->count())->toBe(0)
        ->and(WordClass::query()->where('slug', 'adj')->count())->toBe(0)
        ->and(Word::query()
            ->whereIn('language_id', [$en, $ru])
            ->whereNotExists(fn ($query) => $query
                ->selectRaw(1)
                ->from('forms')
                ->whereColumn('forms.word_id', 'words.id')
                ->whereColumn('forms.form', 'words.word'))
            ->count())->toBe(0);
});

it('reports the phases dry without writing anything', function () {
    $before = normalizeSnapshot();

    test()->artisan('words:normalize', ['--dry' => true])
        ->expectsOutputToContain('DRY RUN')
        ->assertSuccessful();

    expect(normalizeSnapshot())->toBe($before)
        // Still there: the stressed rows, the unknown twins, the alias class.
        ->and($this->fixture['voda']->fresh()->word)->toBe('во'.ACUTE.'да')
        ->and(Word::query()->where('word', 'die')->count())->toBe(2)
        ->and(Word::query()->where('word', 'Big')->exists())->toBeTrue()
        ->and(WordClass::query()->where('slug', 'adj')->exists())->toBeTrue()
        ->and(Form::query()->where('word_id', $this->fixture['march']->id)->count())->toBe(0);
});

it('raises the memory ceiling above the CLI default before any phase runs', function () {
    // The alias-fold group map and the twin merge maps hold real-data sized
    // row sets in memory, so the command must lift the 128M CLI default
    // itself — the demote / raw-wiktextract importer precedent.
    if (memory_get_usage() > 128 * 1024 * 1024) {
        $this->markTestSkipped('Worker already above the 128M CLI default — lift cannot be probed here');
    }

    $original = ini_get('memory_limit');
    ini_set('memory_limit', '128M');

    try {
        test()->artisan('words:normalize', ['--dry' => true])
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        expect(ini_get('memory_limit'))->toBe('2G');
    } finally {
        ini_set('memory_limit', $original);
    }
});
