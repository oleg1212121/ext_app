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
 * A rich word: definition (verb — a major POS) + transcription + translation
 * link + a real frequency rank = 2 + 1 + 3 + 1 = 7.
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

/**
 * A word in a class slug the standard seed doesn't carry (the matrix pins
 * both adjective/adverb spellings and minor POSes).
 */
function commandWord(string $word, string $classSlug): Word
{
    createWordClasses();
    $class = WordClass::query()->updateOrCreate(
        [
            'language_id' => Language::query()->where('code', 'en')->value('id'),
            'slug' => $classSlug,
        ],
        ['title' => ucfirst($classSlug)],
    );

    return Word::query()->create([
        'word' => $word,
        'l_word' => $word,
        'language_id' => Language::query()->where('code', 'en')->value('id'),
        'word_class_id' => $class->id,
    ]);
}

it('recalcs completeness for every word', function () {
    $rich = richWord();
    $bare = createWord('en', 'brume', 'noun');
    // Sentinel frequency is not a real rank: the noun's definition points only.
    $sentinel = createWord('en', 'zarf', 'noun', ['frequency' => Word::FREQUENCY_UNRANKED]);
    Definition::query()->create(['word_id' => $sentinel->id, 'definition' => 'A receptacle for coffee berries.']);

    $this->artisan('words:recalc-completeness')->assertSuccessful();

    expect((int) $rich->fresh()->completeness)->toBe(7)
        ->and($rich->fresh()->completeness_at)->not->toBeNull()
        ->and((int) $bare->fresh()->completeness)->toBe(0)
        ->and($bare->fresh()->completeness_at)->not->toBeNull()
        ->and((int) $sentinel->fresh()->completeness)->toBe(2);
});

it('mirrors the definition weights: 2 on major POS (both spellings), 1 otherwise', function () {
    $major = [];
    foreach (['noun', 'verb', 'adjective', 'adj', 'adverb', 'adv'] as $slug) {
        $major[$slug] = commandWord("word_{$slug}", $slug);
        Definition::query()->create(['word_id' => $major[$slug]->id, 'definition' => 'A sense.']);
    }
    $minor = [];
    foreach (['pronoun', 'phrase', 'unknown'] as $slug) {
        $minor[$slug] = commandWord("minor_{$slug}", $slug);
        Definition::query()->create(['word_id' => $minor[$slug]->id, 'definition' => 'A sense.']);
    }

    $this->artisan('words:recalc-completeness')->assertSuccessful();

    foreach ($major as $slug => $word) {
        expect((int) $word->fresh()->completeness)->toBe(2, "definitions on a {$slug} entry");
    }
    foreach ($minor as $slug => $word) {
        expect((int) $word->fresh()->completeness)->toBe(1, "definitions on a {$slug} entry");
    }
});

it('mirrors the demotion rule: 0 definition points only when EVERY definition demotes', function () {
    // Every definition labelled obsolete/archaic: the word stays at 0.
    $allDemoted = createWord('en', 'dodo', 'verb');
    Definition::query()->create(['word_id' => $allDemoted->id, 'definition' => 'No longer used.', 'labels' => ['obsolete']]);
    Definition::query()->create(['word_id' => $allDemoted->id, 'definition' => 'Out of use.', 'labels' => ['archaic']]);

    // One live (unlabelled) definition among demoting ones: full points.
    $mixed = createWord('en', 'brume', 'verb');
    Definition::query()->create(['word_id' => $mixed->id, 'definition' => 'A live sense.']);
    Definition::query()->create(['word_id' => $mixed->id, 'definition' => 'An obsolete sense.', 'labels' => ['obsolete']]);

    // Non-demoting labels (colloquial/rare/...) never demote.
    $colloquial = createWord('en', 'haze', 'verb');
    Definition::query()->create(['word_id' => $colloquial->id, 'definition' => 'An informal sense.', 'labels' => ['colloquial']]);

    // No definitions at all: live (0) — the score is 0 either way, but
    // WordCompleteness::isDemoted must disagree with the all-demotion case.

    $this->artisan('words:recalc-completeness')->assertSuccessful();

    expect((int) $allDemoted->fresh()->completeness)->toBe(0)
        ->and((int) $mixed->fresh()->completeness)->toBe(2)
        ->and((int) $colloquial->fresh()->completeness)->toBe(2);
});

it('judges demotion on the stored definitions — a relay gloss keeps a partially-relay entry live', function () {
    // The popup display-filters relay glosses ("simple past of see") out of
    // partially-relay entries before rendering; the sweep scores the STORED
    // rows, where the unlabelled relay gloss is a live sense. The lazy
    // recalc pins the same rows to the same score in WordEndpointTest —
    // both paths must agree or words.completeness flaps between them.
    $word = createWord('en', 'saw', 'verb', ['frequency' => 900]);
    Definition::query()->create(['word_id' => $word->id, 'definition' => 'simple past of see']);
    Definition::query()->create(['word_id' => $word->id, 'definition' => 'No longer used.', 'labels' => ['obsolete']]);

    $this->artisan('words:recalc-completeness')->assertSuccessful();

    // Live verb definitions 2 + rank 1 = 3 — not the display-filtered set's
    // demoted 0 + rank 1 = 1.
    expect((int) $word->fresh()->completeness)->toBe(3);
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

    expect((int) $word->fresh()->completeness)->toBe(7);
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
        ->and((int) $staleAfter->completeness)->toBe(7);
});

it('with --stale-only still corrects never-scored words', function () {
    $word = richWord('melt');

    $this->artisan('words:recalc-completeness', ['--stale-only' => true])->assertSuccessful();

    expect((int) $word->fresh()->completeness)->toBe(7);
});
