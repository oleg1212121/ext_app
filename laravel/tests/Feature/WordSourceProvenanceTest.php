<?php

use App\Classes\WiktionaryParser;
use App\Classes\WordAiEnrichmentService;
use App\Filament\Resources\WordResource\Pages\ListWords;
use App\Models\EntityWord;
use App\Models\Language;
use App\Models\Word;
use App\Models\WordClass;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| words.source import provenance (popup-provenance ticket 03, ADR 0084)
|--------------------------------------------------------------------------
|
| Every writer that can CREATE a word row stamps words.source at insert
| (kaikki / golddict / cmudict / entity / ai), never overwrites it, and
| runs the fill-if-null claim so a re-run badges the pre-column rows it
| touches. Provenance sections below mirror the writer list 1:1.
*/

// Writes the records (arrays) to a temp JSONL fixture file and returns its
// path — the caller unlinks it when the test is done. Named apart from the
// other import fixtures: parallel workers may load several files at once.
function provenanceJsonlFixture(array $records): string
{
    $tmpFile = tempnam(sys_get_temp_dir(), 'provenance_');
    file_put_contents($tmpFile, implode("\n", array_map(fn (array $record) => json_encode($record), $records))."\n");

    return $tmpFile;
}

it('claims only still-null rows of the batch l_word/class pairs in the language (the fill-if-null backdoor)', function () {
    createWordClasses();
    // The claimed row: null source, the exact triple the batch touches —
    // a pre-column row a re-run badges.
    $claimed = createWord('en', 'orphan');
    // A row another creator already claimed: never overwritten.
    $foreign = createWord('en', 'borrowed', 'noun', ['source' => Word::SOURCE_KAIKKI]);
    // Same l_word, class NOT in the batch: pair-scoped, not l_word-wide.
    $otherClass = createWord('en', 'orphan', 'verb');
    // Same l_word and class in ANOTHER language: language-scoped.
    $otherLang = createWord('ru', 'orphan');

    Word::fillSourceIfNull(Word::SOURCE_GOLDDICT, $claimed->language_id, [
        ['l_word' => 'orphan', 'word_class_id' => $claimed->word_class_id],
        ['l_word' => 'never-here', 'word_class_id' => $claimed->word_class_id],
    ]);

    expect($claimed->fresh()->source)->toBe(Word::SOURCE_GOLDDICT)
        ->and($foreign->fresh()->source)->toBe(Word::SOURCE_KAIKKI)
        ->and($otherClass->fresh()->source)->toBeNull()
        ->and($otherLang->fresh()->source)->toBeNull();
});

it('exposes the closed source set for the admin filter', function () {
    expect(Word::SOURCES)->toBe(['kaikki', 'golddict', 'cmudict', 'entity', 'ai']);
});

it('stamps kaikki on inserted words and the re-run claim fills pre-column rows it touches', function () {
    createWordClasses();
    // A pre-column row (null source) whose triple the import touches: the
    // fill-if-null claim badges it when kaikki re-runs.
    $preColumn = createWord('en', 'cat', 'noun');
    // A row another creator claimed first: the same import touches it and
    // must not overwrite (first importer wins).
    $claimed = createWord('en', 'dog', 'noun', ['source' => Word::SOURCE_GOLDDICT]);

    $tmpFile = provenanceJsonlFixture([
        ['word' => 'cat', 'pos' => 'noun', 'senses' => [['glosses' => ['A small domesticated feline']]]],
        ['word' => 'dog', 'pos' => 'noun', 'senses' => [['glosses' => ['A domesticated canine']]]],
        ['word' => 'bird', 'pos' => 'noun', 'senses' => [['glosses' => ['A feathered animal']]]],
    ]);

    (new WiktionaryParser('en', 'ru', 100))->import($tmpFile);
    unlink($tmpFile);

    // bird is a fresh INSERT: stamped at insert.
    expect(Word::query()->where('word', 'bird')->sole()->source)->toBe(Word::SOURCE_KAIKKI)
        // cat pre-dates the column: claimed by the fill-if-null pass.
        ->and($preColumn->fresh()->source)->toBe(Word::SOURCE_KAIKKI)
        // dog was golddict's: untouched.
        ->and($claimed->fresh()->source)->toBe(Word::SOURCE_GOLDDICT);
});

it('stamps golddict on inserted words and the re-run claim fills pre-column rows it touches', function () {
    createLanguages(['be']);
    $beId = Language::query()->where('code', 'be')->value('id');
    // The sample fixture's classes, pre-created so the pre-column rows can
    // take the exact triples the import will touch (cat noun, hello
    // interjection).
    $nounId = WordClass::query()->create(['language_id' => $beId, 'slug' => 'noun', 'title' => 'noun'])->id;
    $interjectionId = WordClass::query()->create(['language_id' => $beId, 'slug' => 'interjection', 'title' => 'interjection'])->id;
    $preColumn = Word::query()->create(['word' => 'cat', 'l_word' => 'cat', 'language_id' => $beId, 'word_class_id' => $nounId]);
    $claimed = Word::query()->create(['word' => 'hello', 'l_word' => 'hello', 'language_id' => $beId, 'word_class_id' => $interjectionId, 'source' => Word::SOURCE_KAIKKI]);

    $this->artisan('goldendict:import', [
        'file' => __DIR__.'/../Fixtures/goldendict-sample.jsonl',
        '--lang' => 'be',
    ])->assertSuccessful();

    // Fresh inserts (bird, blank, …) all carry golddict; cat pre-dates the
    // column and gets claimed; hello stays kaikki's (first importer wins).
    expect(Word::query()->where('language_id', $beId)->where('source', Word::SOURCE_GOLDDICT)->count())->toBe(5)
        ->and($preColumn->fresh()->source)->toBe(Word::SOURCE_GOLDDICT)
        ->and($claimed->fresh()->source)->toBe(Word::SOURCE_KAIKKI);
});

it('stamps cmudict on the word rows it creates', function () {
    createWordClasses();

    $file = tempnam(sys_get_temp_dir(), 'provenance_cmu_');
    file_put_contents($file, "BLORP B L AO1 R P\nSPLORE S P L AO1 R\n");

    $this->artisan('dictionary:import-cmudict', ['file' => $file])->assertSuccessful();
    unlink($file);

    $sources = Word::query()->whereIn('l_word', ['blorp', 'splore'])->pluck('source', 'l_word');
    expect($sources['blorp'])->toBe(Word::SOURCE_CMUDICT)
        ->and($sources['splore'])->toBe(Word::SOURCE_CMUDICT);
});

// A stamped unmatchable token on the entity, the AdoptEntityWords fixture
// shape (named apart: parallel workers may load several files at once).
function provenanceEntityWord($entity, string $token, bool $stamped = true): EntityWord
{
    return EntityWord::query()->create([
        'entity_id' => $entity->id,
        'word_id' => null,
        'l_word' => mb_strtolower($token),
        'token' => $token,
        'count' => 1,
        'unmatchable_at' => $stamped ? now() : null,
    ]);
}

it('stamps entity on minted words, claims a raced-in null row, never overwrites', function () {
    Http::fake();

    // The mint: a stamped token no dictionary row carries.
    $entity = createEntity('en');
    provenanceEntityWord($entity, 'Flibbertigibbet');

    // The race-find: the stale pre-fold stamp keys 'ёлка' while the row keys
    // 'елка' — the candidate lookup misses, the mint's firstOrCreate lands
    // on the existing null row and the fill-if-null claims it.
    $orphan = createWord('en', 'елка', 'unknown'); // source null
    provenanceEntityWord($entity, 'ёлка');

    // The claimed row: the same race-find onto a row another creator owns.
    $claimed = createWord('en', 'еморок', 'unknown', ['source' => Word::SOURCE_KAIKKI]);
    provenanceEntityWord($entity, 'ёморок');

    // The adopt-onto path: a classed row found directly is NOT a creating
    // write — its source is never touched.
    $dog = createWord('en', 'dog', 'noun', ['source' => Word::SOURCE_KAIKKI]);
    provenanceEntityWord($entity, 'dog');

    $this->artisan('words:adopt-from-entities')->assertSuccessful();

    expect(Word::query()->where('l_word', 'flibbertigibbet')->sole()->source)->toBe(Word::SOURCE_ENTITY)
        ->and($orphan->fresh()->source)->toBe(Word::SOURCE_ENTITY)
        ->and($claimed->fresh()->source)->toBe(Word::SOURCE_KAIKKI)
        ->and($dog->fresh()->source)->toBe(Word::SOURCE_KAIKKI);
});

it('stamps ai on the adopt-on-ask mint; the adopt onto an existing row never touches its source', function () {
    createWordClasses();
    $service = app(WordAiEnrichmentService::class);
    $ru = Language::query()->where('code', 'ru')->first();

    // The mint: the only word-row write the ask path makes (a concurrent
    // double-ask that lost the insert adopts the winner's row — the
    // firstOrCreate's fill-if-null backdoor claims a raced-in null row).
    $minted = $service->resolveOrAdopt('Bomb-hit', $ru);
    expect($minted->source)->toBe(Word::SOURCE_AI)
        ->and($minted->fresh()->source)->toBe(Word::SOURCE_AI);

    // The adopt-onto path is a resolving read for provenance: a row another
    // creator owns keeps its stamp, a pre-column row stays null until ITS
    // creator re-runs.
    $kaikki = createWord('ru', 'дом', 'noun', ['source' => Word::SOURCE_KAIKKI]);
    $preColumn = createWord('ru', 'еморок', 'noun');

    expect($service->resolveOrAdopt('До́м', $ru)->id)->toBe($kaikki->id)
        ->and($kaikki->fresh()->source)->toBe(Word::SOURCE_KAIKKI)
        ->and($service->resolveOrAdopt('Ёморок', $ru)->id)->toBe($preColumn->id)
        ->and($preColumn->fresh()->source)->toBeNull();
});

it('shows the source column with a dash for null and narrows by the source filter', function () {
    actingAsAdmin();
    createWordClasses();

    $imported = createWord('en', 'imported', 'noun', ['source' => Word::SOURCE_KAIKKI]);
    $orphan = createWord('en', 'orphan');

    // The null-source row renders the dash placeholder in the Source column.
    Livewire::test(ListWords::class)
        ->assertSee('—')
        // The filter narrows to the stamped row only; provenance is never
        // hand-editable, so the resource form carries no source field.
        ->filterTable('source', Word::SOURCE_KAIKKI)
        ->assertCanSeeTableRecords([$imported])
        ->assertCanNotSeeTableRecords([$orphan]);
});
