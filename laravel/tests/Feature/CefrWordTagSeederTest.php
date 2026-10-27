<?php

use App\Models\Language;
use App\Models\Tag;
use App\Models\Word;
use App\Models\WordClass;
use Database\Seeders\CefrWordTagSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Small-fixture tests for the CEFR tag sync core (ticket: cefr-word-tags 01).
 * The seeder's embedded ~7k-entry lists are never pinned here — sync()
 * receives hand-rolled mini lists, so the assertions stay on the mechanics:
 * full replace, all-POS matching, normalization, unmatched skipping, output.
 */

/**
 * Minimal English dictionary fixture: two word classes, `hello` as two rows
 * (noun + interjection), `goodbye` as one, a straight-apostrophe row, a word
 * absent from every list, and an unrelated word for the detach case.
 *
 * @return array<string, int|Word>
 */
function cefrFixture(): array
{
    $language = createLanguages(['en'])['en'];

    $nounId = WordClass::query()->updateOrCreate(
        ['language_id' => $language->id, 'slug' => 'noun'],
        ['title' => 'Noun'],
    )->id;

    $interjectionId = WordClass::query()->updateOrCreate(
        ['language_id' => $language->id, 'slug' => 'interjection'],
        ['title' => 'Interjection'],
    )->id;

    $create = fn (string $word, int $classId): Word => Word::query()->create([
        'word' => $word,
        'l_word' => mb_strtolower($word),
        'language_id' => $language->id,
        'word_class_id' => $classId,
    ]);

    return [
        'language_id' => $language->id,
        'noun_id' => $nounId,
        'interjection_id' => $interjectionId,
        'hello_noun' => $create('hello', $nounId),
        'hello_interjection' => $create('hello', $interjectionId),
        'goodbye' => $create('goodbye', $nounId),
        'murphys' => $create("murphy's", $nounId),
        'absent' => $create('absentword', $nounId),
        'unrelated' => $create('unrelatedword', $nounId),
    ];
}

/**
 * Mini CEFR lists exercising normalization (case, whitespace, U+2019) and an
 * unmatched entry. All six slugs present — absent levels sync as emptied.
 *
 * @return array<string, list<string>>
 */
function cefrMiniLists(): array
{
    return [
        'a1' => ['HELLO', ' goodbye ', 'nowordhere'],
        'a2' => ['MURPHY’S'],
        'b1' => [],
        'b2' => [],
        'c1' => [],
        'c2' => [],
    ];
}

/**
 * A console command capturing line() calls, wired into the seeder's public
 * setCommand() so the per-level report can be asserted.
 */
function cefrCaptureCommand(): Command
{
    return new class extends Command
    {
        public array $lines = [];

        protected $signature = 'cefr-test-capture';

        public function line($string, $style = null, $verbosity = null)
        {
            $this->lines[] = $string;
        }
    };
}

/**
 * Run sync() with the capturing command wired in, returning the captured lines.
 *
 * @param  array<string, list<string>>  $lists
 * @return list<string>
 */
function cefrSyncCaptured(array $lists, int $languageId): array
{
    $command = cefrCaptureCommand();

    $seeder = (new CefrWordTagSeeder)->setCommand($command);

    $seeder->sync($lists, $languageId);

    return $command->lines;
}

it('attaches every POS row of a listed spelling, matching through normalization', function () {
    $fixture = cefrFixture();

    app(CefrWordTagSeeder::class)->sync(cefrMiniLists(), $fixture['language_id']);

    $a1 = Tag::query()->where('slug', 'a1')->firstOrFail();
    $attachedWordIds = $a1->words()->pluck('words.id');

    // Both `hello` POS rows plus `goodbye` — normalized entries (HELLO,
    // ' goodbye ') matched their lowercase rows; the unmatched entry and the
    // absent word attach nothing.
    expect($attachedWordIds->count())->toBe(3)
        ->and($attachedWordIds)->toContain($fixture['hello_noun']->id)
        ->and($attachedWordIds)->toContain($fixture['hello_interjection']->id)
        ->and($attachedWordIds)->toContain($fixture['goodbye']->id)
        ->and($fixture['absent']->tags()->exists())->toBeFalse()
        ->and(Word::query()->where('l_word', 'nowordhere')->exists())->toBeFalse();

    // The U+2019 entry matches the straight-apostrophe row on a2.
    $a2 = Tag::query()->where('slug', 'a2')->firstOrFail();
    expect($a2->words()->pluck('words.id')->all())
        ->toBe([$fixture['murphys']->id]);
});

it('creates the six CEFR tags with their display names', function () {
    $fixture = cefrFixture();

    app(CefrWordTagSeeder::class)->sync(cefrMiniLists(), $fixture['language_id']);

    expect(Tag::query()->orderBy('slug')->pluck('name', 'slug')->all())->toBe([
        'a1' => 'CEFR A1',
        'a2' => 'CEFR A2',
        'b1' => 'CEFR B1',
        'b2' => 'CEFR B2',
        'c1' => 'CEFR C1',
        'c2' => 'CEFR C2',
    ]);
});

it('fully replaces a CEFR tag, detaching pre-existing words absent from the list', function () {
    $fixture = cefrFixture();

    // Pre-existing tag with a stale name, carrying an attachment to a word
    // the list does not contain — a manual Filament attachment, effectively.
    $a1 = Tag::query()->create(['slug' => 'a1', 'name' => 'Legacy A1']);
    $fixture['unrelated']->tags()->attach($a1->id);

    app(CefrWordTagSeeder::class)->sync(cefrMiniLists(), $fixture['language_id']);

    $attachedWordIds = $a1->words()->pluck('words.id');

    expect($a1->refresh()->name)->toBe('CEFR A1')
        ->and($attachedWordIds->count())->toBe(3)
        ->and($attachedWordIds)->not->toContain($fixture['unrelated']->id)
        ->and($fixture['unrelated']->tags()->exists())->toBeFalse();
});

it('re-runs as no-ops without duplicating pivot rows', function () {
    $fixture = cefrFixture();

    app(CefrWordTagSeeder::class)->sync(cefrMiniLists(), $fixture['language_id']);
    $before = DB::table('tagged_words')->orderBy('word_id')->orderBy('tag_id')->get(['word_id', 'tag_id']);

    app(CefrWordTagSeeder::class)->sync(cefrMiniLists(), $fixture['language_id']);
    $after = DB::table('tagged_words')->orderBy('word_id')->orderBy('tag_id')->get(['word_id', 'tag_id']);

    expect($after->count())->toBe($before->count())
        ->and($after->all())->toEqual($before->all());
});

it('reports attached counts and unmatched entries per level', function () {
    $fixture = cefrFixture();

    $lines = cefrSyncCaptured(cefrMiniLists(), $fixture['language_id']);

    $a1Line = collect($lines)->first(fn (string $line) => str_starts_with($line, 'CEFR a1:'));
    $a2Line = collect($lines)->first(fn (string $line) => str_starts_with($line, 'CEFR a2:'));
    $b1Line = collect($lines)->first(fn (string $line) => str_starts_with($line, 'CEFR b1:'));

    expect($a1Line)->toContain('attached 3 words')->toContain('nowordhere')
        ->and($a2Line)->toContain('attached 1 word')->not->toContain('unmatched')
        ->and($b1Line)->toContain('attached 0 words')
        ->and($lines)->toHaveCount(6);
});

it('seeds the six CEFR tags through run() when english exists', function () {
    $fixture = cefrFixture();

    app(CefrWordTagSeeder::class)->run();

    $slugs = Tag::query()->orderBy('slug')->pluck('slug');
    expect($slugs->count())->toBe(6)
        ->and($slugs->all())->toBe(['a1', 'a2', 'b1', 'b2', 'c1', 'c2']);
});

it('skips with no tags when the english language is missing', function () {
    // Fresh database: no Language rows at all — the guard must bail out.
    expect(Language::query()->count())->toBe(0);

    app(CefrWordTagSeeder::class)->run();

    expect(Tag::query()->count())->toBe(0)
        ->and(DB::table('tagged_words')->count())->toBe(0);
});
