<?php

use App\Models\Definition;
use App\Models\Language;
use App\Models\Transcription;
use App\Models\TranscriptionType;
use App\Models\Word;
use App\Models\WordClass;
use App\Models\WordTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * A rich word: definition + transcription + translation link + a real
 * frequency rank = 2 + 1 + 1 + 1 = 5.
 */
function richWord(string $word = 'melt'): Word
{
    createWordClasses();
    $type = TranscriptionType::query()->firstOrCreate([
        'language_id' => Language::query()->where('code', 'en')->value('id'),
        'slug' => 'ipa',
    ], ['title' => 'IPA']);
    $word = createWord('en', $word, 'verb', ['frequency' => 900]);
    Definition::query()->create(['word_id' => $word->id, 'definition' => 'To change from solid to liquid.']);
    Transcription::query()->create([
        'word_id' => $word->id,
        'transcription_type_id' => $type->id,
        'transcription' => 'mɛlt',
    ]);
    WordTranslation::link($word->id, ruTranslationTarget()->id);

    return $word;
}

/**
 * The shared native-side link target — one row, however many rich words.
 */
function ruTranslationTarget(): Word
{
    return Word::query()->firstOrCreate([
        'l_word' => 'плавить',
        'language_id' => Language::query()->where('code', 'ru')->value('id'),
        'word_class_id' => WordClass::query()->where('slug', 'verb')->value('id'),
    ], ['word' => 'плавить']);
}

it('recalcs completeness for every word', function () {
    $rich = richWord();
    $bare = createWord('en', 'brume', 'noun');
    // Sentinel frequency is not a real rank: the definition point only.
    $sentinel = createWord('en', 'zarf', 'noun', ['frequency' => Word::FREQUENCY_UNRANKED]);
    Definition::query()->create(['word_id' => $sentinel->id, 'definition' => 'A receptacle for coffee berries.']);

    $this->artisan('words:recalc-completeness')->assertSuccessful();

    expect((int) $rich->fresh()->completeness)->toBe(5)
        ->and($rich->fresh()->completeness_at)->not->toBeNull()
        ->and((int) $bare->fresh()->completeness)->toBe(0)
        ->and($bare->fresh()->completeness_at)->not->toBeNull()
        ->and((int) $sentinel->fresh()->completeness)->toBe(1);
});

it('is idempotent — a second full run rewrites nothing', function () {
    $word = richWord();
    $this->artisan('words:recalc-completeness')->assertSuccessful();

    $stamp = $word->fresh()->completeness_at;
    $this->artisan('words:recalc-completeness')->assertSuccessful();

    expect($word->fresh()->completeness_at->equalTo($stamp))->toBeTrue();
});

it('corrects a wrong score even under a fresh stamp on a full run', function () {
    $word = richWord();
    $this->artisan('words:recalc-completeness')->assertSuccessful();
    // Fresh stamp, wrong score — the full sweep is authoritative over values.
    DB::table('words')->where('id', $word->id)->update(['completeness' => 0]);

    $this->artisan('words:recalc-completeness')->assertSuccessful();

    expect((int) $word->fresh()->completeness)->toBe(5);
});

it('with --stale-only leaves fresh words alone and rewrites stale ones', function () {
    $fresh = richWord('melt');
    $stale = richWord('thaw');
    $this->artisan('words:recalc-completeness')->assertSuccessful();

    // A controlled future stamp is definitively fresh; thaw's child content
    // moves one hour beyond it, past the staleness threshold.
    $future = now()->addHour();
    DB::table('words')->whereIn('id', [$fresh->id, $stale->id])->update(['completeness_at' => $future]);
    DB::table('definitions')->where('word_id', $stale->id)->update(['updated_at' => $future->copy()->addHour()]);

    $this->artisan('words:recalc-completeness', ['--stale-only' => true])->assertSuccessful();

    // The exact stored stamp (the column is timestamp(0) — the Carbon's
    // microseconds are gone on read-back, so compare the string).
    $storedFuture = DB::table('words')->where('id', $fresh->id)->value('completeness_at');

    $freshAfter = $fresh->fresh();
    $staleAfter = $stale->fresh();
    expect($freshAfter->completeness_at->format('Y-m-d H:i:s'))->toBe($storedFuture, 'fresh word must not be rewritten')
        ->and($staleAfter->completeness_at->format('Y-m-d H:i:s'))->not->toBe($storedFuture, 'stale word must be rewritten')
        ->and((int) $staleAfter->completeness)->toBe(5);
});

it('with --stale-only still corrects never-scored words', function () {
    $word = richWord('melt');

    $this->artisan('words:recalc-completeness', ['--stale-only' => true])->assertSuccessful();

    expect((int) $word->fresh()->completeness)->toBe(5);
});
