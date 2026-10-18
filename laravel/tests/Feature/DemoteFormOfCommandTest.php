<?php

use App\Models\EntityWord;
use App\Models\Form;
use App\Models\Word;
use App\Models\WordTranslation;

/**
 * The mini-DB mirroring the pre-demote dev-DB state: relay word rows with
 * their own form rows, links, entity_words and staged translations JSON,
 * plus the bases — one of which already claims a relay surface as its form.
 */
function seedDemoteFixture(): array
{
    $allFours = createWord('en', 'on all fours', 'phrase');
    $dog = createWord('en', 'dog', 'noun');

    $sobaka = createWord('ru', 'собака', 'noun', ['l_word' => 'собака', 'frequency' => 100]);
    $sobak = createWord('ru', 'собак', 'noun', ['l_word' => 'собак', 'frequency' => 50]);
    $chetverenki = createWord('ru', 'четвереньки', 'noun', ['l_word' => 'четвереньки', 'frequency' => 11314]);
    $chetverenkah = createWord('ru', 'четвереньках', 'noun', [
        'l_word' => 'четвереньках', 'frequency' => 1100000, 'translations' => ['во весь дух'],
    ]);
    $chetverenke = createWord('ru', 'четвереньке', 'noun', [
        'l_word' => 'четвереньке', 'frequency' => 999, 'translations' => ['бегом'],
    ]);
    $horoshy = createWord('ru', 'хороший', 'noun', ['l_word' => 'хороший', 'frequency' => 200]);
    $luchshe = createWord('ru', 'лучше', 'noun', ['l_word' => 'лучше', 'frequency' => 300]);
    $luchshemu = createWord('ru', 'лучшему', 'noun', ['l_word' => 'лучшему', 'frequency' => 400]);
    $pech = createWord('ru', 'печь', 'verb', ['l_word' => 'печь']);
    $pechka = createWord('ru', 'печка', 'noun', ['l_word' => 'печка', 'frequency' => 3000]);
    $baglonie = createWord('ru', 'баглонье', 'noun', ['l_word' => 'баглонье']);
    $privet = createWord('ru', 'привет', 'noun', ['l_word' => 'привет']);

    $form = fn (Word $word, string $form): Form => Form::query()->create([
        'word_id' => $word->id,
        'form' => $form,
        'l_word' => mb_strtolower($form),
    ]);

    $form($sobaka, 'собака');
    $form($sobaka, 'sobaka'); // romanization junk from the pre-ticket-02 import
    $form($chetverenki, 'четвереньки');
    $form($chetverenki, 'четвереньках'); // the base already claims the relay surface
    $form($chetverenkah, 'четвереньках'); // the relay's self-referential form
    $form($privet, 'privet'); // romanization junk on a word no relay touches

    // Links the demote re-points: relay → other-language word, relay → its own
    // base (self-pair collapse), relay → another relay of the same base, and
    // relay → relay with different bases (becomes base → base).
    WordTranslation::link($chetverenkah->id, $allFours->id);
    WordTranslation::link($sobak->id, $dog->id);
    WordTranslation::link($chetverenkah->id, $chetverenki->id);
    WordTranslation::link($chetverenkah->id, $chetverenke->id);
    WordTranslation::link($sobak->id, $chetverenkah->id);

    $entity = createEntity('ru');
    EntityWord::query()->create([
        'entity_id' => $entity->id,
        'word_id' => $chetverenkah->id,
        'l_word' => 'четвереньках',
        'token' => 'четвереньках',
    ]);
    EntityWord::query()->create([
        'entity_id' => $entity->id,
        'word_id' => null,
        'l_word' => 'лучшему',
        'token' => 'лучшему',
    ]);

    return compact('allFours', 'dog', 'sobaka', 'sobak', 'chetverenki', 'chetverenkah', 'chetverenke', 'horoshy', 'luchshe', 'luchshemu', 'pech', 'pechka', 'baglonie', 'privet');
}

/**
 * The full observable state, for dry-run / re-run equality snapshots.
 */
function demoteSnapshot(): string
{
    return json_encode([
        'words' => Word::query()->orderBy('id')->get(['id', 'word', 'l_word', 'frequency', 'translations'])->toArray(),
        'forms' => Form::query()->orderBy('id')->get(['word_id', 'form'])->toArray(),
        'links' => WordTranslation::query()->orderBy('id')->get(['word_a_id', 'word_b_id'])->toArray(),
        'entity_words' => EntityWord::query()->orderBy('id')->get(['l_word', 'token', 'word_id'])->toArray(),
    ]);
}

beforeEach(function () {
    $this->fixture = seedDemoteFixture();
});

it('demotes relay rows onto their bases — forms, links, entity words, staged JSON, folded ranks', function () {
    extract($this->fixture, EXTR_SKIP);

    $this->artisan('wiktionary:demote-form-of', [
        '--lang' => 'ru',
        '--file' => __DIR__.'/../Fixtures/kaikki-demote-form-of.jsonl',
        '--chunk' => '2', // forces the cross-chunk link and staged-JSON paths
    ])
        ->expectsOutputToContain('баглонье') // the missing-base sample the ticket wants reported
        ->assertSuccessful();

    // Relay word rows are gone — the chain (лучшему → лучше → хороший) too.
    foreach (['собак', 'четвереньках', 'четвереньке', 'лучше', 'лучшему'] as $gone) {
        expect(Word::query()->where('word', $gone)->count())->toBe(0);
    }

    // A surface with a real entry (печь verb) and a baseless relay survive.
    expect(Word::query()->where('l_word', 'печь')->count())->toBe(1)
        ->and(Word::query()->where('l_word', 'баглонье')->exists())->toBeTrue();

    // The relay surfaces are forms of their bases; the base that already
    // claimed четвереньках keeps exactly one row; romanization junk is gone;
    // the subtracted печь never lands on печка.
    $formsOf = fn (Word $word) => Form::query()->where('word_id', $word->id)->pluck('form')->sort()->values()->all();
    expect($formsOf($sobaka))->toBe(['собак', 'собака'])
        ->and($formsOf($chetverenki))->toBe(['четвереньках', 'четвереньке', 'четвереньки'])
        ->and($formsOf($horoshy))->toBe(['лучше', 'лучшему'])
        ->and($formsOf($pechka))->toBe([])
        ->and($formsOf($privet))->toBe([]);

    $linked = fn (int $one, int $other) => WordTranslation::query()
        ->where('word_a_id', min($one, $other))
        ->where('word_b_id', max($one, $other))
        ->exists();

    // Links re-pointed onto the bases; the 5 seed links end as 3.
    expect(WordTranslation::query()->count())->toBe(3)
        ->and($linked($chetverenki->id, $allFours->id))->toBeTrue()
        ->and($linked($sobaka->id, $dog->id))->toBeTrue()
        ->and($linked($sobaka->id, $chetverenki->id))->toBeTrue()
        // The relay→own-base and relay→same-base-relay links collapsed.
        ->and($linked($chetverenki->id, $chetverenki->id))->toBeFalse();

    // entity_words re-pointed to the base; the null-linked token stays null.
    expect(EntityWord::query()->where('l_word', 'четвереньках')->value('word_id'))->toBe($chetverenki->id)
        ->and(EntityWord::query()->where('l_word', 'лучшему')->value('word_id'))->toBeNull();

    // Frequency folds as LEAST — the best rank wins: the unranked sentinel
    // (1100000 on четвереньках) never demotes a ranked base, while a better
    // relay rank (50 on собак, 999 on четвереньке) lands on the base.
    expect((float) $sobaka->fresh()->frequency)->toBe(50.0)
        ->and((float) $chetverenki->fresh()->frequency)->toBe(999.0)
        ->and((float) $horoshy->fresh()->frequency)->toBe(200.0)
        ->and((float) $pechka->fresh()->frequency)->toBe(3000.0);

    // Staged translations JSON moved to the base (union of both relays).
    expect($chetverenki->fresh()->translations)->toBe(['во весь дух', 'бегом']);
});

it('is idempotent — a second run demotes nothing and changes nothing', function () {
    $this->artisan('wiktionary:demote-form-of', [
        '--lang' => 'ru',
        '--file' => __DIR__.'/../Fixtures/kaikki-demote-form-of.jsonl',
    ])->assertSuccessful();

    $afterFirst = demoteSnapshot();

    $this->artisan('wiktionary:demote-form-of', [
        '--lang' => 'ru',
        '--file' => __DIR__.'/../Fixtures/kaikki-demote-form-of.jsonl',
    ])->assertSuccessful();

    expect(demoteSnapshot())->toBe($afterFirst)
        ->and(Word::query()->where('l_word', 'баглонье')->exists())->toBeTrue();
});

it('reports phase counts dry without writing anything', function () {
    $before = demoteSnapshot();

    $this->artisan('wiktionary:demote-form-of', [
        '--lang' => 'ru',
        '--file' => __DIR__.'/../Fixtures/kaikki-demote-form-of.jsonl',
        '--dry' => true,
    ])->expectsOutputToContain('DRY RUN')->assertSuccessful();

    expect(demoteSnapshot())->toBe($before)
        // Still there: the relays, their links and the junk forms.
        ->and(Word::query()->where('word', 'четвереньках')->exists())->toBeTrue()
        ->and(Form::query()->where('form', 'sobaka')->exists())->toBeTrue()
        ->and(WordTranslation::query()->count())->toBe(5);
});

it('rejects an unknown language', function () {
    $before = demoteSnapshot();

    $this->artisan('wiktionary:demote-form-of', [
        '--lang' => 'xx',
        '--file' => __DIR__.'/../Fixtures/kaikki-demote-form-of.jsonl',
    ])->assertFailed();

    expect(demoteSnapshot())->toBe($before);
});

it('raises the memory ceiling above the CLI default before any scan work', function () {
    // The relay map holds the whole language's vocabulary in memory, so the
    // command must lift the 128M CLI default itself — the raw-wiktextract
    // importer precedent. Probed through the fast unknown-language failure
    // path: the bump precedes every other statement in handle().
    $original = ini_get('memory_limit');
    ini_set('memory_limit', '128M');

    try {
        $this->artisan('wiktionary:demote-form-of', [
            '--lang' => 'xx',
            '--file' => __DIR__.'/../Fixtures/kaikki-demote-form-of.jsonl',
        ])->assertFailed();

        expect(ini_get('memory_limit'))->toBe('2G');
    } finally {
        ini_set('memory_limit', $original);
    }
});

it('rejects a missing dump file', function () {
    $this->artisan('wiktionary:demote-form-of', [
        '--lang' => 'ru',
        '--file' => 'kaikki/definitely-absent.jsonl',
    ])->assertFailed();
});
