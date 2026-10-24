<?php

use App\Models\EntityWord;
use App\Models\Form;
use App\Models\Tag;
use App\Models\TranscriptionType;
use App\Models\UserWord;
use App\Models\Word;
use App\Models\WordClass;
use App\Models\WordTranslation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

if (! defined('ACUTE')) {
    define('ACUTE', "\u{0301}"); // combining acute — the Cyrillic stress mark
}
if (! defined('GRAVE')) {
    define('GRAVE', "\u{0300}"); // combining grave — a second combining-mark flavor
}

/**
 * The pre-migrate dev-DB shape, seeded under the OLD schema: the ticket-04
 * migration is rolled back first (its down() restores the display-word
 * unique), because same-key duplicate rows cannot even be inserted under
 * the (l_word, language_id, word_class_id) unique. Everything seeds through
 * createWord — the OLD display unique is exactly what allowed these pairs.
 *
 * The pairs: a ёлка/елка spelling pair with stale old-formula keys (the
 * recompute folds both to елка), case twins sharing one stored key
 * (Дом/дом, кот/Кот), the stressed twin (весна/ве́сна) hanging the phase-1
 * merge machinery, and stale/NULL l_word rows for the recompute.
 */
function seedMergePhaseFixture(): array
{
    moveWordUniquenessMigration()->down();

    $languages = createLanguages();
    $en = $languages['en']->id;
    $ru = $languages['ru']->id;

    $ruIpa = TranscriptionType::query()->firstOrCreate(
        ['language_id' => $ru, 'slug' => 'ipa'],
        ['title' => 'IPA'],
    );

    $define = fn (Word $word, string $text) => DB::table('definitions')->insert([
        'definition' => $text,
        'word_id' => $word->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $form = fn (Word $word, string $value) => Form::query()->create([
        'word_id' => $word->id,
        'form' => $value,
        'l_word' => mb_strtolower($value), // the stale pre-fold shape on purpose
    ]);

    $transcribe = fn (int $wordId, int $typeId, string $ipa) => DB::table('transcriptions')->insert([
        'transcription' => $ipa,
        'word_id' => $wordId,
        'transcription_type_id' => $typeId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // --- the ё pair: stale old-formula keys; the recompute folds both to елка

    $yolka = createWord('ru', 'ёлка', 'noun', ['frequency' => 100]); // l_word 'ёлка'
    $define($yolka, 'хвойное дерево');
    $form($yolka, 'ёлка');
    $elka = createWord('ru', 'елка', 'noun', ['frequency' => 50, 'translations' => ['сосна']]);
    $define($elka, 'вечнозелёное дерево');
    $define($elka, 'лес');
    $form($elka, 'елка');
    $form($elka, 'ёлки');
    $transcribe($elka->id, $ruIpa->id, '/jolkə/');

    // --- case twins sharing one stored key (the display unique allowed them)

    $dom = createWord('ru', 'дом', 'noun'); // id lower, no definitions
    $domUpper = createWord('ru', 'Дом', 'noun'); // id higher — carries the definition
    $define($domUpper, 'жилое здание');

    $kot = createWord('ru', 'кот', 'noun'); // id lower
    $kotUpper = createWord('ru', 'Кот', 'noun'); // id higher, no definitions either

    // --- the stressed twin and the full phase-1 merge machinery ------------

    $home = createWord('en', 'home', 'noun');
    $sobaka = createWord('ru', 'собака', 'noun');
    $tree = createWord('en', 'tree', 'noun');

    $vesna = createWord('ru', 'весна', 'noun', ['frequency' => 100]);
    $define($vesna, 'plain gloss');
    $vesnaStressed = createWord('ru', 'ве'.ACUTE.'сна', 'noun', ['frequency' => 50, 'translations' => ['spring']]);
    $define($vesnaStressed, 'stressed gloss');
    $form($vesnaStressed, 'ве'.ACUTE.'сны'); // two stressed variants of one plain
    $form($vesnaStressed, 'ве'.GRAVE.'сны'); // ... string: lowest form id survives
    $transcribe($vesnaStressed->id, $ruIpa->id, '/vʲesna/');

    // --- merge machinery hung on the ё pair (the new phase merges it) ------

    WordTranslation::link($elka->id, $tree->id); // absorbed a-arm

    $entity = createEntity('ru');
    EntityWord::query()->create([
        'entity_id' => $entity->id,
        'word_id' => $elka->id,
        'l_word' => 'елка',
        'token' => 'елка',
    ]);
    $vesnaEntity = createEntity('ru');
    EntityWord::query()->create([
        'entity_id' => $vesnaEntity->id,
        'word_id' => $vesnaStressed->id,
        'l_word' => 'весна',
        'token' => 'ве'.ACUTE.'сна',
    ]);

    $userA = approvedUser();
    $userB = approvedUser();
    $knows = fn (int $userId, Word $word, int $familiarity) => UserWord::query()->create([
        'user_id' => $userId, 'word_id' => $word->id, 'familiarity' => $familiarity,
    ]);
    $knows($userA->id, $yolka, 70); // collision: the survivor's row wins
    $knows($userA->id, $elka, 40); // deduped away
    $knows($userB->id, $elka, 90); // repointed

    $event = fn (int $userId, Word $word, string $rowKey) => DB::table('user_word_event')->insert([
        'user_id' => $userId, 'word_id' => $word->id, 'row_key' => $rowKey,
        'kind' => 'lookup', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $event($userA->id, $yolka, 'es:1'); // collision: survivor's row wins
    $event($userA->id, $elka, 'es:1'); // deduped away
    $event($userB->id, $elka, 'es:2'); // repointed

    $tag = Tag::query()->create(['slug' => 'core', 'name' => 'Core']);
    foreach ([$yolka, $elka] as $word) {
        DB::table('tagged_words')->insert([
            'word_id' => $word->id, 'tag_id' => $tag->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    foreach ([$yolka, $elka] as $word) {
        DB::table('word_translation_fetches')->insert([
            'word_id' => $word->id, 'target_language_id' => $ru, 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // --- recompute-only rows: stale en key and a NULL key ------------------

    $cafe = createWord('en', 'cafe'.ACUTE, 'noun', ['l_word' => 'cafe'.ACUTE]);
    $form($cafe, 'cafe'.ACUTE); // legitimate en diacritic — display never touched
    $odd = createWord('en', 'Odd', 'noun', ['l_word' => null]); // the key is backfilled

    $lozhka = createWord('ru', 'ложка', 'noun'); // control: no duplicate, untouched

    return compact('en', 'ru', 'yolka', 'elka', 'dom', 'domUpper', 'kot', 'kotUpper', 'home', 'sobaka', 'tree', 'vesna', 'vesnaStressed', 'entity', 'vesnaEntity', 'userA', 'userB', 'tag', 'cafe', 'odd', 'lozhka');
}

/**
 * The full observable state, for dry-run / re-run equality snapshots.
 */
function mergePhaseSnapshot(): string
{
    return json_encode([
        'words' => Word::query()->orderBy('id')->get(['id', 'word', 'l_word', 'word_class_id', 'frequency', 'translations'])->toArray(),
        'forms' => DB::table('forms')->orderBy('id')->get(['id', 'word_id', 'form', 'l_word'])->toArray(),
        'transcriptions' => DB::table('transcriptions')->orderBy('id')->get(['id', 'word_id', 'transcription'])->toArray(),
        'definitions' => DB::table('definitions')->orderBy('id')->get(['id', 'word_id', 'definition'])->toArray(),
        'links' => DB::table('word_translations')->orderBy('id')->get(['id', 'word_a_id', 'word_b_id'])->toArray(),
        'entity_words' => DB::table('entity_words')->orderBy('id')->get(['id', 'word_id', 'l_word', 'count'])->toArray(),
        'user_word' => DB::table('user_word')->orderBy('id')->get(['id', 'user_id', 'word_id', 'familiarity'])->toArray(),
        'user_word_event' => DB::table('user_word_event')->orderBy('id')->get(['id', 'user_id', 'word_id', 'row_key', 'kind'])->toArray(),
        'tagged_words' => DB::table('tagged_words')->orderBy('id')->get(['id', 'word_id', 'tag_id'])->toArray(),
        'fetches' => DB::table('word_translation_fetches')->orderBy('id')->get(['id', 'word_id', 'target_language_id'])->toArray(),
    ]);
}

/**
 * The count cell of one summary-table row in the given Artisan output.
 * (Artisan::output() drains its buffer on fetch — capture it once, pass it.)
 */
function normalizeTableRow(string $label, string $output): ?int
{
    $line = collect(explode("\n", $output))
        ->first(fn (string $row): bool => str_contains($row, $label));
    if ($line === null || ($end = strrpos($line, '|')) === false) {
        return null;
    }

    // The count sits between the row's last two pipes.
    $start = strrpos(substr($line, 0, $end), '|');
    if ($start === false) {
        return null;
    }
    $cell = trim(substr($line, $start + 1, $end - $start - 1));

    return is_numeric($cell) ? (int) $cell : null;
}

function moveWordUniquenessMigration(): object
{
    return require database_path('migrations/2026_10_19_000001_move_word_uniqueness_to_l_word.php');
}

function runMergeNormalize(array $options = []): void
{
    test()->artisan('words:normalize', $options)->assertSuccessful();
}

function mergePhaseDuplicateGroups(): int
{
    return (int) DB::selectOne(
        'select count(*) as n from ('
        .' select language_id, l_word, word_class_id from words where l_word is not null'
        .' group by language_id, l_word, word_class_id having count(*) > 1'
        .' ) g',
    )->n;
}

beforeEach(function () {
    $this->fixture = seedMergePhaseFixture();
});

it('recomputes stale l_word keys and fills the missing ones without touching display text', function () {
    extract($this->fixture, EXTR_SKIP);

    runMergeNormalize();

    // The ё-fold: ёлка's stale old-formula key folds to елка — the display
    // spelling and the en diacritic never change.
    expect($yolka->fresh()->l_word)->toBe('елка')
        ->and($yolka->fresh()->word)->toBe('ёлка')
        ->and($cafe->fresh()->l_word)->toBe('cafe')
        ->and($cafe->fresh()->word)->toBe('cafe'.ACUTE)
        // The stale keys on forms recompute the same way.
        ->and(Form::query()->where('word_id', $yolka->id)->where('form', 'ёлка')->value('l_word'))->toBe('елка')
        ->and(Form::query()->where('word_id', $cafe->id)->value('l_word'))->toBe('cafe')
        // A NULL key is backfilled from the display word.
        ->and($odd->fresh()->l_word)->toBe('odd')
        // An already-correct key updates nothing.
        ->and($lozhka->fresh()->l_word)->toBe('ложка');
});

it('merges the ёлка/елка duplicate pair onto the ё-display survivor and moves every child', function () {
    extract($this->fixture, EXTR_SKIP);

    runMergeNormalize();

    // ONE ru-noun row survives — the ё spelling, despite carrying fewer
    // definitions than the елка twin it absorbed.
    expect(Word::query()->where('l_word', 'елка')->where('language_id', $ru)->count())->toBe(1)
        ->and((int) Word::query()->where('l_word', 'елка')->where('language_id', $ru)->value('id'))->toBe($yolka->id)
        ->and($yolka->fresh()->word)->toBe('ёлка')
        ->and($elka->fresh())->toBeNull()
        // Definitions moved: all three on the survivor.
        ->and(DB::table('definitions')->where('word_id', $yolka->id)->pluck('definition')->sort()->values()->all())
        ->toBe(['вечнозелёное дерево', 'лес', 'хвойное дерево'])
        // Forms moved (the survivor's own lemma form not duplicated) ...
        ->and(Form::query()->where('word_id', $yolka->id)->pluck('form')->sort()->values()->all())
        ->toBe(['елка', 'ёлка', 'ёлки'])
        // ... the transcription moved ...
        ->and(DB::table('transcriptions')->where('word_id', $yolka->id)->pluck('transcription')->all())
        ->toBe(['/jolkə/'])
        // ... the link re-pointed canonically without a duplicate pair ...
        ->and(WordTranslation::query()->count())->toBe(1)
        ->and(WordTranslation::query()->value('word_a_id'))->toBe(min($yolka->id, $tree->id))
        ->and(WordTranslation::query()->value('word_b_id'))->toBe(max($yolka->id, $tree->id))
        // ... entity_words re-pointed ...
        ->and(EntityWord::query()->where('l_word', 'елка')->where('entity_id', $entity->id)->value('word_id'))->toBe($yolka->id)
        // ... user_word rows re-pointed: the collision kept the survivor's
        // familiarity, the lone twin's row moved ...
        ->and(DB::table('user_word')->where('user_id', $userA->id)->where('word_id', $yolka->id)->value('familiarity'))->toBe(70)
        ->and(DB::table('user_word')->where('user_id', $userB->id)->where('word_id', $yolka->id)->value('familiarity'))->toBe(90)
        ->and(DB::table('user_word')->where('word_id', $elka->id)->count())->toBe(0)
        // ... events deduped onto the survivor's rows, the lone one moved ...
        ->and(DB::table('user_word_event')->where('word_id', $yolka->id)->count())->toBe(2)
        ->and(DB::table('user_word_event')->where('word_id', $elka->id)->count())->toBe(0)
        // ... tag collisions keep the survivor's row ...
        ->and(DB::table('tagged_words')->where('tag_id', $tag->id)->pluck('word_id')->unique()->values()->all())
        ->toBe([$yolka->id])
        // ... the fetch ledger re-pointed ...
        ->and(DB::table('word_translation_fetches')->where('target_language_id', $ru)->pluck('word_id')->unique()->values()->all())
        ->toBe([$yolka->id])
        // ... the staged JSON unioned and the frequency folded as LEAST.
        ->and($yolka->fresh()->translations)->toBe(['сосна'])
        ->and((float) $yolka->fresh()->frequency)->toBe(50.0);
});

it('breaks survivor ties by most definitions, then the lowest id', function () {
    extract($this->fixture, EXTR_SKIP);

    runMergeNormalize();

    // Neither display carries ё: the more-defined row wins over the lower id...
    expect($domUpper->fresh()->word)->toBe('Дом')
        ->and($dom->fresh())->toBeNull()
        ->and(DB::table('definitions')->where('word_id', $domUpper->id)->pluck('definition')->all())
        ->toBe(['жилое здание'])
        // ... and with no ё and no definitions, the lowest id survives.
        ->and($kot->fresh()->word)->toBe('кот')
        ->and($kotUpper->fresh())->toBeNull()
        // A word without a duplicate is untouched.
        ->and($lozhka->fresh())->not->toBeNull();
});

it('merges the stressed twin into its plain twin with the children and the better rank', function () {
    extract($this->fixture, EXTR_SKIP);

    runMergeNormalize();

    // The stressed display merged into the stored plain twin; children moved.
    expect($vesnaStressed->fresh())->toBeNull()
        ->and($vesna->fresh()->word)->toBe('весна')
        ->and(DB::table('definitions')->where('word_id', $vesna->id)->pluck('definition')->sort()->values()->all())
        ->toBe(['plain gloss', 'stressed gloss'])
        // The two stressed form variants stripped to one plain string: the
        // lowest form id survives, the other deduped away.
        ->and(Form::query()->where('word_id', $vesna->id)->where('form', 'весны')->count())->toBe(1)
        ->and(DB::table('transcriptions')->where('word_id', $vesna->id)->pluck('transcription')->all())
        ->toBe(['/vʲesna/'])
        // The staged JSON unioned, the rank folded as LEAST (50 beats 100).
        ->and($vesna->fresh()->translations)->toBe(['spring'])
        ->and((float) $vesna->fresh()->frequency)->toBe(50.0)
        // The entity stamp re-pointed onto the survivor.
        ->and(EntityWord::query()->where('entity_id', $vesnaEntity->id)->value('word_id'))->toBe($vesna->id);
});

it('leaves zero duplicate groups so the uniqueness migration applies (normalize → migrate)', function () {
    extract($this->fixture, EXTR_SKIP);

    runMergeNormalize();

    // The gate ticket 06 runs before migrate: no (l_word, language, class)
    // group holds more than one row.
    expect(mergePhaseDuplicateGroups())->toBe(0);

    // The ticket-04 migration is duplicate-intolerant: on the merged data it
    // applies cleanly — the OLD schema here started from its down().
    moveWordUniquenessMigration()->up();

    // Under the restored unique the same-key pair is impossible again, and
    // the command still runs green on the migrated schema. The violating
    // insert runs in a savepoint so the test transaction survives it.
    $nounId = WordClass::query()->where('language_id', $ru)->where('slug', 'noun')->value('id');
    expect(fn () => DB::transaction(fn () => Word::query()->create([
        'word' => 'ёлка', 'l_word' => 'елка', 'language_id' => $ru, 'word_class_id' => $nounId,
    ])))->toThrow(UniqueConstraintViolationException::class);

    runMergeNormalize();

    expect($yolka->fresh()->word)->toBe('ёлка')
        ->and(mergePhaseDuplicateGroups())->toBe(0);
});

it('recomputes entity stamps with the new formula, folding same-key collisions', function () {
    extract($this->fixture, EXTR_SKIP);

    // Two stamps of a fresh entity whose keys fold together: the ёлка stamp
    // is the tokenizer's old-formula residue, the елка stamp already carries
    // the folded key and is linked.
    $stampEntity = createEntity('ru');
    $stale = EntityWord::query()->create([
        'entity_id' => $stampEntity->id, 'word_id' => null,
        'l_word' => 'ёлка', 'token' => 'Ёлка', 'count' => 2,
    ]);
    $holder = EntityWord::query()->create([
        'entity_id' => $stampEntity->id, 'word_id' => $yolka->id,
        'l_word' => 'елка', 'token' => 'елка', 'count' => 3,
    ]);

    runMergeNormalize();

    // The already-correct stamp survives: the stale one's count folds in and
    // the surviving stamp carries the folded key.
    $stamps = EntityWord::query()->where('entity_id', $stampEntity->id)->get();
    expect($stamps)->toHaveCount(1)
        ->and((int) $stamps[0]->id)->toBe($holder->id)
        ->and($stamps[0]->l_word)->toBe('елка')
        ->and($stamps[0]->count)->toBe(5)
        ->and((int) $stamps[0]->word_id)->toBe($yolka->id)
        ->and($stale->fresh())->toBeNull();
});

it('is idempotent — the second run merges and recomputes nothing', function () {
    runMergeNormalize();

    $afterFirst = mergePhaseSnapshot();

    expect(Artisan::call('words:normalize'))->toBe(0);
    $output = Artisan::output();

    expect(mergePhaseSnapshot())->toBe($afterFirst)
        // The summary reports the zero work: no merges, no recomputes.
        ->and(normalizeTableRow('duplicate rows merged per (l_word, language, class)', $output))->toBe(0)
        ->and(normalizeTableRow('words.l_word keys recomputed', $output))->toBe(0)
        ->and(normalizeTableRow('forms.l_word keys recomputed', $output))->toBe(0);
});

it('reports the would-be merges dry without writing anything', function () {
    $before = mergePhaseSnapshot();

    expect(Artisan::call('words:normalize', ['--dry' => true]))->toBe(0);
    $output = Artisan::output();

    expect(mergePhaseSnapshot())->toBe($before)
        // The duplicates, the stale keys: all still there.
        ->and($this->fixture['elka']->fresh())->not->toBeNull()
        ->and($this->fixture['dom']->fresh())->not->toBeNull()
        ->and($this->fixture['kotUpper']->fresh())->not->toBeNull()
        ->and($this->fixture['yolka']->fresh()->l_word)->toBe('ёлка')
        // The dry run counted the would-be work: three duplicate rows to
        // absorb (ёлка/елка, дом/Дом, кот/Кот) and the stale keys on three
        // words and three forms — exactly what the real run reports.
        ->and(normalizeTableRow('duplicate rows merged per (l_word, language, class)', $output))->toBe(3)
        ->and(normalizeTableRow('words.l_word keys recomputed', $output))->toBe(3)
        ->and(normalizeTableRow('forms.l_word keys recomputed', $output))->toBe(3);
});

it('reaches the same merged state through tiny chunks', function () {
    extract($this->fixture, EXTR_SKIP);

    // --chunk=2 forces multiple scan passes and chunked temp-map inserts,
    // with the duplicate pairs landing in different passes.
    runMergeNormalize(['--chunk' => '2']);

    expect($yolka->fresh()->word)->toBe('ёлка')
        ->and(Word::query()->where('l_word', 'елка')->where('language_id', $ru)->count())->toBe(1)
        ->and(DB::table('definitions')->where('word_id', $yolka->id)->count())->toBe(3)
        ->and($domUpper->fresh()->word)->toBe('Дом')
        ->and($kot->fresh()->word)->toBe('кот')
        ->and($vesnaStressed->fresh())->toBeNull()
        ->and(mergePhaseDuplicateGroups())->toBe(0);
});
