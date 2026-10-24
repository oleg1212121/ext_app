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
use App\Models\WordClass;
use App\Models\WordTranslation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * A word with nothing known about it — the 0 anchor of the completeness
 * matrix (spec decision 3, weighted v2).
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
        $word->load(['definitions', 'transcriptions', 'examples', 'etymologies', 'wordClass']),
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

/**
 * A word class for any slug the matrix pins beyond the standard seed
 * (adjective/adverb and their short spellings, minor POSes).
 */
function enWordClass(string $slug): WordClass
{
    createWordClasses();

    return WordClass::query()->updateOrCreate(
        ['language_id' => Language::query()->where('code', 'en')->value('id'), 'slug' => $slug],
        ['title' => ucfirst($slug)],
    );
}

/**
 * A word in any class slug (createWord only knows the standard seed).
 */
function wordInClass(string $word, string $slug, array $attributes = []): Word
{
    $class = enWordClass($slug);

    return Word::query()->create([
        'word' => $word,
        'l_word' => $word,
        'language_id' => Language::query()->where('code', 'en')->value('id'),
        'word_class_id' => $class->id,
        ...$attributes,
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

it('scores linked translations 3 — existence, not count', function () {
    $word = bareCompletenessWord();
    WordTranslation::link($word->id, createWord('ru', 'кот', 'noun')->id);
    WordTranslation::link($word->id, createWord('ru', 'плавить', 'verb')->id);

    expect(currentScore($word, hasLinkedTranslations: true))->toBe(3);
});

it('scores definitions 2 on the major POS — both adjective/adverb spellings', function () {
    foreach (['noun', 'verb', 'adjective', 'adj', 'adverb', 'adv'] as $slug) {
        $word = wordInClass("word_{$slug}", $slug);
        Definition::query()->create(['word_id' => $word->id, 'definition' => 'A sense.']);

        expect(currentScore($word, false))->toBe(2, "definitions on a {$slug} entry");
    }
});

it('scores definitions 1 on any other POS', function () {
    foreach (['pronoun', 'phrase', 'unknown'] as $slug) {
        $word = wordInClass("word_{$slug}", $slug);
        Definition::query()->create(['word_id' => $word->id, 'definition' => 'A sense.']);

        expect(currentScore($word, false))->toBe(1, "definitions on a {$slug} entry");
    }
});

it('scores definitions 0 when every definition carries a demoting label', function () {
    $cases = [['obsolete'], ['archaic'], ['obsolete', 'rare']];
    foreach ($cases as $i => $labels) {
        $word = bareCompletenessWord("obsolete{$i}");
        Definition::query()->create(['word_id' => $word->id, 'definition' => 'No longer used.', 'labels' => $labels]);
        Definition::query()->create(['word_id' => $word->id, 'definition' => 'Out of use.', 'labels' => $labels]);

        expect(currentScore($word, false))->toBe(0);
    }
});

it('scores definition points when only some definitions are demoted', function () {
    $word = bareCompletenessWord();
    Definition::query()->create(['word_id' => $word->id, 'definition' => 'A live sense.']);
    Definition::query()->create(['word_id' => $word->id, 'definition' => 'An obsolete sense.', 'labels' => ['obsolete']]);

    // Not EVERY definition demotes: the live sense keeps the verb's 2 points.
    expect(currentScore($word, false))->toBe(2);
});

it('treats a word with no definitions as live, not demoted', function () {
    expect(WordCompleteness::isDemoted(bareCompletenessWord()))->toBeFalse();
});

it('isDemoted agrees with the score only when every definition demotes', function () {
    $allObsolete = bareCompletenessWord('zarf');
    Definition::query()->create(['word_id' => $allObsolete->id, 'definition' => 'No longer used.', 'labels' => ['obsolete']]);

    $mixed = bareCompletenessWord('brume');
    Definition::query()->create(['word_id' => $mixed->id, 'definition' => 'A live sense.']);
    Definition::query()->create(['word_id' => $mixed->id, 'definition' => 'An archaic sense.', 'labels' => ['archaic']]);

    $unlabelled = bareCompletenessWord('fog');
    Definition::query()->create(['word_id' => $unlabelled->id, 'definition' => 'A live sense.']);

    $nonDemotingLabels = bareCompletenessWord('haze');
    Definition::query()->create(['word_id' => $nonDemotingLabels->id, 'definition' => 'Informal sense.', 'labels' => ['colloquial']]);

    expect(WordCompleteness::isDemoted($allObsolete))->toBeTrue()
        ->and(WordCompleteness::isDemoted($mixed))->toBeFalse()
        ->and(WordCompleteness::isDemoted($unlabelled))->toBeFalse()
        ->and(WordCompleteness::isDemoted($nonDemotingLabels))->toBeFalse();
});

it('isDemoted when the word\'s own headword mark carries a demoting label — definitions or not', function () {
    // The word-level mark (words.labels, the source's headword mark)
    // demotes directly, whatever the senses say — even no definitions,
    // which without a mark is the live anchor above.
    $markedNoDefinitions = bareCompletenessWord('zarf', ['labels' => ['obsolete']]);

    $markedLiveSense = bareCompletenessWord('brume', ['labels' => ['archaic']]);
    Definition::query()->create(['word_id' => $markedLiveSense->id, 'definition' => 'A live sense.']);

    expect(WordCompleteness::isDemoted($markedNoDefinitions))->toBeTrue()
        ->and(WordCompleteness::isDemoted($markedLiveSense))->toBeTrue();
});

it('keeps a word-level non-demoting mark (rare) live with full definition points', function () {
    $word = bareCompletenessWord('zarf', ['labels' => ['rare']]);
    Definition::query()->create(['word_id' => $word->id, 'definition' => 'A live sense.']);

    expect(WordCompleteness::isDemoted($word))->toBeFalse()
        ->and(currentScore($word, false))->toBe(2);
});

it('scores definitions 0 when the word\'s own mark carries a demoting label', function () {
    $word = bareCompletenessWord('zarf', ['labels' => ['obsolete']]);
    Definition::query()->create(['word_id' => $word->id, 'definition' => 'A sense.']);

    expect(currentScore($word, false))->toBe(0);
});

it('scores each content child 1 — existence, not count', function () {
    $type = enIpaType();

    // Definitions on a MINOR POS carry the single point (major POS would be 2).
    $defined = wordInClass('i', 'pronoun');
    Definition::query()->create(['word_id' => $defined->id, 'definition' => 'The speaker or writer.']);
    Definition::query()->create(['word_id' => $defined->id, 'definition' => 'The one, itself.']);
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

it('scores links, one content kind and a real rank 6', function () {
    $word = bareCompletenessWord('melt', ['frequency' => 900]);
    Definition::query()->create(['word_id' => $word->id, 'definition' => 'To change from solid to liquid.']);
    WordTranslation::link($word->id, createWord('ru', 'плавить', 'verb')->id);

    // links 3 + definition on a verb 2 + rank 1
    expect(currentScore($word, hasLinkedTranslations: true))->toBe(6);
});

it('scores links, three content kinds and a real rank 8', function () {
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

    // links 3 + definition 2 + transcription 1 + example 1 + rank 1
    expect(currentScore($word, hasLinkedTranslations: true))->toBe(8);
});

it('scores a fully known word 9', function () {
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

    expect(currentScore($word, hasLinkedTranslations: true))->toBe(WordCompleteness::MAX)->toBe(9);
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
function expectRecalced(Word $word, Carbon $stamp, int $expectedScore = 2): void
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
    expect($fresh->completeness)->toBe(2) // definitions on a verb entry
        ->and($fresh->completeness_at)->not->toBeNull();
});

it('recalcs when demoting labels land after the last score', function () {
    [$word, $stamp] = resolvedWord();

    DB::table('definitions')->where('word_id', $word->id)->update([
        'labels' => json_encode(['obsolete']),
        'updated_at' => $stamp->copy()->addMinute(),
    ]);

    // Every definition now demotes: the definition points sink.
    expectRecalced($word, $stamp, expectedScore: 0);
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

    expectRecalced($word, $stamp, expectedScore: 2); // verb entry, existence not count
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

    expectRecalced($word, $stamp, expectedScore: 3); // definition + transcription
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

    expectRecalced($word, $stamp, expectedScore: 3); // definition + transcription
});

it('recalcs when an example row was inserted', function () {
    [$word, $stamp] = resolvedWord();

    Example::query()->create(['word_id' => $word->id, 'example' => 'The snow melts.']);
    futureStamps('examples', $word->id, $stamp);

    expectRecalced($word, $stamp, expectedScore: 3); // definition + example
});

it('recalcs when an example row was updated', function () {
    [$word, $stamp] = resolvedWord();
    Example::query()->create(['word_id' => $word->id, 'example' => 'Snow melts.']);

    DB::table('examples')->where('word_id', $word->id)->update([
        'example' => 'Ice melts.',
        'updated_at' => $stamp->copy()->addMinute(),
    ]);

    expectRecalced($word, $stamp, expectedScore: 3); // definition + example
});

it('recalcs when an etymology row was inserted', function () {
    [$word, $stamp] = resolvedWord();

    Etymology::query()->create(['word_id' => $word->id, 'etymology' => 'From Old English meltan.']);
    futureStamps('etymologies', $word->id, $stamp);

    expectRecalced($word, $stamp, expectedScore: 3); // definition + etymology
});

it('recalcs when an etymology row was updated', function () {
    [$word, $stamp] = resolvedWord();
    Etymology::query()->create(['word_id' => $word->id, 'etymology' => 'Old English.']);

    DB::table('etymologies')->where('word_id', $word->id)->update([
        'etymology' => 'From Old English meltan.',
        'updated_at' => $stamp->copy()->addMinute(),
    ]);

    expectRecalced($word, $stamp, expectedScore: 3); // definition + etymology
});

it('recalcs when a form row was inserted', function () {
    [$word, $stamp] = resolvedWord();

    Form::query()->create(['word_id' => $word->id, 'form' => 'melted', 'l_word' => 'melted']);
    futureStamps('forms', $word->id, $stamp);

    expectRecalced($word, $stamp, expectedScore: 2); // forms carry no score points
});

it('recalcs when a form row was updated', function () {
    [$word, $stamp] = resolvedWord();
    Form::query()->create(['word_id' => $word->id, 'form' => 'melted', 'l_word' => 'melted']);

    DB::table('forms')->where('word_id', $word->id)->update([
        'form' => 'melting',
        'updated_at' => $stamp->copy()->addMinute(),
    ]);

    expectRecalced($word, $stamp, expectedScore: 2); // forms carry no score points
});

it('recalcs when a translation link lands after the last score', function () {
    [$word, $stamp] = resolvedWord();
    $link = WordTranslation::link($word->id, createWord('ru', 'плавить', 'verb')->id);

    DB::table('word_translations')->where('id', $link->id)->update([
        'created_at' => $stamp->copy()->addMinute(),
        'updated_at' => $stamp->copy()->addMinute(),
    ]);

    expectRecalced($word, $stamp, expectedScore: 5); // definitions 2 + translation 3
});
