<?php

use App\Classes\WordCompleteness;
use App\Classes\WordFamily;
use App\Models\Definition;
use App\Models\Etymology;
use App\Models\Example;
use App\Models\Form;
use App\Models\Language;
use App\Models\Transcription;
use App\Models\TranscriptionType;
use App\Models\Word;
use App\Models\WordTranslation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * A word with nothing known about it — the 0 anchor of the completeness
 * matrix (spec decision 3).
 */
function bareCompletenessWord(string $word = 'melt', array $attributes = []): Word
{
    return createWord('en', $word, 'verb', $attributes);
}

/**
 * The score over the word's content as it stands NOW (relations reloaded —
 * children are created after the word in these tests).
 */
function currentScore(Word $word, ?bool $hasLinkedTranslations = null): int
{
    return WordCompleteness::score(
        $word->load(['definitions', 'transcriptions', 'examples', 'etymologies']),
        $hasLinkedTranslations,
    );
}

function enIpaType(): TranscriptionType
{
    createWordClasses(); // seeds the languages the type's language_id points at

    return TranscriptionType::query()->create([
        'language_id' => Language::query()->where('code', 'en')->value('id'),
        'slug' => 'ipa',
        'title' => 'IPA',
    ]);
}

it('scores a word with nothing known about it 0', function () {
    $word = bareCompletenessWord();

    expect(currentScore($word, hasLinkedTranslations: false))->toBe(0);
});

it('scores the unranked frequency sentinel and the no-rank default 0', function () {
    $sentinel = bareCompletenessWord('zarf', ['frequency' => Word::FREQUENCY_UNRANKED]);
    $neverRanked = bareCompletenessWord('brume', ['frequency' => 0]);

    expect(currentScore($sentinel, false))->toBe(0)
        ->and(currentScore($neverRanked, false))->toBe(0);
});

it('scores linked translations 2 — existence, not count', function () {
    $word = bareCompletenessWord();
    WordTranslation::link($word->id, createWord('ru', 'кот', 'noun')->id);
    WordTranslation::link($word->id, createWord('ru', 'плавить', 'verb')->id);

    expect(currentScore($word, hasLinkedTranslations: true))->toBe(2);
});

it('scores each content child 1 — existence, not count', function () {
    $type = enIpaType();

    $defined = bareCompletenessWord('saw');
    Definition::query()->create(['word_id' => $defined->id, 'definition' => 'To cut with a saw.']);
    Definition::query()->create(['word_id' => $defined->id, 'definition' => 'A saying.']);
    expect(currentScore($defined, false))->toBe(1);

    $transcribed = bareCompletenessWord('sigh');
    Transcription::query()->create([
        'word_id' => $transcribed->id,
        'transcription_type_id' => $type->id,
        'transcription' => 'saɪ',
    ]);
    expect(currentScore($transcribed, false))->toBe(1);

    $exemplified = bareCompletenessWord('wilt');
    Example::query()->create(['word_id' => $exemplified->id, 'example' => 'The leaves wilt.']);
    expect(currentScore($exemplified, false))->toBe(1);

    $withEtymology = bareCompletenessWord('skunk');
    Etymology::query()->create(['word_id' => $withEtymology->id, 'etymology' => 'From a Massachusett term.']);
    expect(currentScore($withEtymology, false))->toBe(1);
});

it('scores a real frequency rank 1', function () {
    $ranked = bareCompletenessWord('melt', ['frequency' => 2143]);

    expect(currentScore($ranked, false))->toBe(1);
});

it('scores links, one content kind and a real rank 4', function () {
    $word = bareCompletenessWord('melt', ['frequency' => 900]);
    Definition::query()->create(['word_id' => $word->id, 'definition' => 'To change from solid to liquid.']);
    WordTranslation::link($word->id, createWord('ru', 'плавить', 'verb')->id);

    // links 2 + definition 1 + rank 1
    expect(currentScore($word, hasLinkedTranslations: true))->toBe(4);
});

it('scores links, three content kinds and a real rank 6', function () {
    $type = enIpaType();
    $word = bareCompletenessWord('melt', ['frequency' => 900]);
    Definition::query()->create(['word_id' => $word->id, 'definition' => 'To change from solid to liquid.']);
    Transcription::query()->create([
        'word_id' => $word->id,
        'transcription_type_id' => $type->id,
        'transcription' => 'mɛlt',
    ]);
    Example::query()->create(['word_id' => $word->id, 'example' => 'The snow melts.']);
    WordTranslation::link($word->id, createWord('ru', 'плавить', 'verb')->id);

    // links 2 + definition 1 + transcription 1 + example 1 + rank 1
    expect(currentScore($word, hasLinkedTranslations: true))->toBe(6);
});

it('scores a fully known word 7', function () {
    $type = enIpaType();
    $word = bareCompletenessWord('melt', ['frequency' => 900]);
    Definition::query()->create(['word_id' => $word->id, 'definition' => 'To change from solid to liquid.']);
    Transcription::query()->create([
        'word_id' => $word->id,
        'transcription_type_id' => $type->id,
        'transcription' => 'mɛlt',
    ]);
    Example::query()->create(['word_id' => $word->id, 'example' => 'The snow melts.']);
    Etymology::query()->create(['word_id' => $word->id, 'etymology' => 'From Old English meltan.']);
    WordTranslation::link($word->id, createWord('ru', 'плавить', 'verb')->id);

    expect(currentScore($word, hasLinkedTranslations: true))->toBe(7);
});

// ---------------------------------------------------------------------------
// Lazy staleness recalc — triggered by opening the popup (WordFamily::resolve)
// ---------------------------------------------------------------------------

/**
 * A word the popup family has already scored once; the family recalc wrote
 * both completeness columns. Returns [word, the stamp it wrote].
 *
 * @return array{0: Word, 1: Carbon}
 */
function resolvedWord(): array
{
    $word = bareCompletenessWord();
    Definition::query()->create(['word_id' => $word->id, 'definition' => 'To change from solid to liquid.']);
    WordFamily::resolve($word, 'melt');

    return [$word, $word->fresh()->completeness_at->copy()];
}

/**
 * Push every row of $table for the word one minute past the given stamp, so
 * the staleness comparison sees the change regardless of clock rounding.
 */
function futureStamps(string $table, int $wordId, Carbon $from): void
{
    DB::table($table)->where('word_id', $wordId)->update([
        'created_at' => $from->copy()->addMinute(),
        'updated_at' => $from->copy()->addMinute(),
    ]);
}

/**
 * A fresh stamp (written by the resolve in resolvedWord) does not move when
 * the recalc re-fires — both stamps land in the same timestamp(0) second —
 * so the observable is the SCORE: planted wrong (0) under the fresh stamp,
 * only a real recompute rewrites it to the true value (> 0).
 */
function expectRecalced(Word $word, Carbon $stamp, int $expectedScore = 1): void
{
    DB::table('words')->where('id', $word->id)->update(['completeness' => 0]);

    WordFamily::resolve($word, 'melt');

    expect($word->fresh()->completeness)->toBe($expectedScore);
}

it('recalcs a never-scored word when the popup family resolves', function () {
    $word = bareCompletenessWord();
    Definition::query()->create(['word_id' => $word->id, 'definition' => 'To change from solid to liquid.']);

    expect($word->completeness)->toBeNull();

    WordFamily::resolve($word, 'melt');

    $fresh = $word->fresh();
    expect($fresh->completeness)->toBe(1)
        ->and($fresh->completeness_at)->not->toBeNull();
});

it('does not recompute a fresh word on resolve', function () {
    [$word, $stamp] = resolvedWord();
    $future = $stamp->copy()->addMinute();

    // Freshness — not correctness — gates the recompute: a deliberately
    // wrong score under a future stamp stays untouched.
    DB::table('words')->where('id', $word->id)->update(['completeness' => 0, 'completeness_at' => $future]);

    WordFamily::resolve($word, 'melt');

    $after = $word->fresh();
    expect($after->completeness)->toBe(0)
        ->and($after->completeness_at->equalTo($future))->toBeTrue();
});

it('recalcs when the word row itself was touched', function () {
    [$word, $stamp] = resolvedWord();

    DB::table('words')->where('id', $word->id)->update([
        'updated_at' => $stamp->copy()->addMinute(),
    ]);

    expectRecalced($word, $stamp);
});

it('recalcs when a definition row was inserted', function () {
    [$word, $stamp] = resolvedWord();

    Definition::query()->create(['word_id' => $word->id, 'definition' => 'Operate a saw.']);
    futureStamps('definitions', $word->id, $stamp);

    expectRecalced($word, $stamp, expectedScore: 1); // existence, not count
});

it('recalcs when a definition row was updated', function () {
    [$word, $stamp] = resolvedWord();

    DB::table('definitions')->where('word_id', $word->id)->update([
        'definition' => 'Revised sense.',
        'updated_at' => $stamp->copy()->addMinute(),
    ]);

    expectRecalced($word, $stamp);
});

it('recalcs when a transcription row was inserted', function () {
    [$word, $stamp] = resolvedWord();
    $type = enIpaType();

    Transcription::query()->create([
        'word_id' => $word->id,
        'transcription_type_id' => $type->id,
        'transcription' => 'mɛlt',
    ]);
    futureStamps('transcriptions', $word->id, $stamp);

    expectRecalced($word, $stamp, expectedScore: 2); // definition + transcription
});

it('recalcs when a transcription row was updated', function () {
    [$word, $stamp] = resolvedWord();
    $type = enIpaType();
    Transcription::query()->create([
        'word_id' => $word->id,
        'transcription_type_id' => $type->id,
        'transcription' => 'melt',
    ]);

    DB::table('transcriptions')->where('word_id', $word->id)->update([
        'transcription' => 'mɛlt',
        'updated_at' => $stamp->copy()->addMinute(),
    ]);

    expectRecalced($word, $stamp, expectedScore: 2); // definition + transcription
});

it('recalcs when an example row was inserted', function () {
    [$word, $stamp] = resolvedWord();

    Example::query()->create(['word_id' => $word->id, 'example' => 'The snow melts.']);
    futureStamps('examples', $word->id, $stamp);

    expectRecalced($word, $stamp, expectedScore: 2); // definition + example
});

it('recalcs when an example row was updated', function () {
    [$word, $stamp] = resolvedWord();
    Example::query()->create(['word_id' => $word->id, 'example' => 'Snow melts.']);

    DB::table('examples')->where('word_id', $word->id)->update([
        'example' => 'Ice melts.',
        'updated_at' => $stamp->copy()->addMinute(),
    ]);

    expectRecalced($word, $stamp, expectedScore: 2); // definition + example
});

it('recalcs when an etymology row was inserted', function () {
    [$word, $stamp] = resolvedWord();

    Etymology::query()->create(['word_id' => $word->id, 'etymology' => 'From Old English meltan.']);
    futureStamps('etymologies', $word->id, $stamp);

    expectRecalced($word, $stamp, expectedScore: 2); // definition + etymology
});

it('recalcs when an etymology row was updated', function () {
    [$word, $stamp] = resolvedWord();
    Etymology::query()->create(['word_id' => $word->id, 'etymology' => 'Old English.']);

    DB::table('etymologies')->where('word_id', $word->id)->update([
        'etymology' => 'From Old English meltan.',
        'updated_at' => $stamp->copy()->addMinute(),
    ]);

    expectRecalced($word, $stamp, expectedScore: 2); // definition + etymology
});

it('recalcs when a form row was inserted', function () {
    [$word, $stamp] = resolvedWord();

    Form::query()->create(['word_id' => $word->id, 'form' => 'melted', 'l_word' => 'melted']);
    futureStamps('forms', $word->id, $stamp);

    expectRecalced($word, $stamp, expectedScore: 1); // forms carry no score points
});

it('recalcs when a form row was updated', function () {
    [$word, $stamp] = resolvedWord();
    Form::query()->create(['word_id' => $word->id, 'form' => 'melted', 'l_word' => 'melted']);

    DB::table('forms')->where('word_id', $word->id)->update([
        'form' => 'melting',
        'updated_at' => $stamp->copy()->addMinute(),
    ]);

    expectRecalced($word, $stamp, expectedScore: 1); // forms carry no score points
});

it('recalcs when a translation link lands after the last score', function () {
    [$word, $stamp] = resolvedWord();
    $link = WordTranslation::link($word->id, createWord('ru', 'плавить', 'verb')->id);

    DB::table('word_translations')->where('id', $link->id)->update([
        'created_at' => $stamp->copy()->addMinute(),
        'updated_at' => $stamp->copy()->addMinute(),
    ]);

    expectRecalced($word, $stamp, expectedScore: 3); // definition 1 + translation 2
});
