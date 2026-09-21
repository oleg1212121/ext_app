<?php

use App\Classes\EntityTextHasher;
use App\Jobs\AlignEntitySentences;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

// Guard: anything that leaks an HTTP call must hit a fake response instead of
// hanging on the Python service timeout.
beforeEach(fn () => Http::fake());

/**
 * An entity with 3 sentences and a current text hash.
 */
function hashedEntity(string $lang, object $work, string $name, array $contents = ['First.', 'Second.', 'Third.']): Entity
{
    $sentenceType = SentenceType::query()->firstOrCreate(['name' => 'Narration']);

    $entity = createEntity($lang, $work, [
        'name' => $name,
        'signature' => json_encode([1.0, 0.0]),
        'is_restricted' => false,
    ]);

    foreach ($contents as $index => $content) {
        EntitySentence::create([
            'entity_id' => $entity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => $content,
            'order' => ($index + 1) * 1024,
        ]);
    }

    $entity->forceFill([
        'text_hash' => (new EntityTextHasher)->hash($entity),
        'text_hashed_at' => now(),
        'sentences_updated_at' => now(),
    ])->save();

    return $entity->refresh();
}

/**
 * An exact copy of $source (same sentences -> same text hash) under a new name.
 */
function exactCopy(Entity $source, string $name): Entity
{
    $copy = createEntity(
        $source->language->code,
        $source->work,
        ['name' => $name, 'signature' => $source->signature, 'is_restricted' => false],
    );

    foreach ($source->sentences()->orderBy('order')->get() as $sentence) {
        EntitySentence::create([
            'entity_id' => $copy->id,
            'sentence_type_id' => $sentence->sentence_type_id,
            'content' => $sentence->content,
            'order' => $sentence->order,
        ]);
    }

    $copy->forceFill([
        'text_hash' => $source->text_hash,
        'text_hashed_at' => now(),
        'sentences_updated_at' => now(),
    ])->save();

    return $copy->refresh();
}

/**
 * A completed alignment between $a and $b: one meaning match per sentence
 * pair, junctions on both sides; $confirmedRows limits how many rows carry
 * junctions (for ranking tests).
 */
function completedSourceMatch(Entity $a, Entity $b, int $confirmedRows = PHP_INT_MAX): EntityMatch
{
    $match = createEntityMatch($a, $b, ['status' => 'completed', 'completed_at' => now()]);

    $aSentences = $a->sentences()->orderBy('order')->get();
    $bSentences = $b->sentences()->orderBy('order')->get();

    foreach ($aSentences as $index => $aSentence) {
        $meaning = MeaningMatch::create([
            'entity_match_id' => $match->id,
            'order' => ($index + 1) * 1024,
            'similarity' => 0.9,
            'alignment_chunk' => $index === 1 ? -1 : 0, // second row is a human landmark
        ]);

        if ($index < $confirmedRows) {
            SentenceMeaningMatch::create([
                'entity_sentence_id' => $aSentence->id,
                'meaning_match_id' => $meaning->id,
                'side' => 'a',
            ]);

            SentenceMeaningMatch::create([
                'entity_sentence_id' => $bSentences[$index]->id,
                'meaning_match_id' => $meaning->id,
                'side' => 'b',
            ]);
        }
    }

    $match->update([
        'linked_count' => $match->meaningMatches()->count(),
        'a_total_sentences' => $aSentences->count(),
        'b_total_sentences' => $bSentences->count(),
        'entity_similarity' => 0.95,
    ]);

    return $match->refresh();
}

function storeMatch(Entity $first, Entity $second)
{
    return test()->actingAs(User::factory()->create())
        ->post(route('alignments.store'), [
            'first_entity_id' => $first->id,
            'second_entity_id' => $second->id,
            'chunk_size' => 75,
            'max_n' => 6,
        ]);
}

function matchFor(Entity $first, Entity $second): ?EntityMatch
{
    return EntityMatch::query()
        ->where('a_entity_id', min($first->id, $second->id))
        ->where('b_entity_id', max($first->id, $second->id))
        ->first();
}

test('creating a match between exact copies reuses the completed alignment', function () {
    $work = createWork();
    $en = hashedEntity('en', $work, 'EN original');
    $ru = hashedEntity('ru', $work, 'RU original');
    completedSourceMatch($en, $ru);

    $enCopy = exactCopy($en, 'EN copy');
    $ruCopy = exactCopy($ru, 'RU copy');

    Bus::fake();

    storeMatch($enCopy, $ruCopy)
        ->assertRedirect(route('alignments.index'))
        ->assertSessionHas('success', 'Entity match created — alignment copied from an identical text pair.');

    Bus::assertNotDispatched(AlignEntitySentences::class);

    $newMatch = matchFor($enCopy, $ruCopy);

    expect($newMatch)->not->toBeNull()
        ->and($newMatch->status)->toBe('completed')
        ->and($newMatch->completed_at)->not->toBeNull()
        ->and((float) $newMatch->entity_similarity)->toBe(0.95)
        ->and($newMatch->a_total_sentences)->toBe(3)
        ->and($newMatch->b_total_sentences)->toBe(3);

    // Meaning matches copied with landmarks preserved.
    $rows = $newMatch->meaningMatches()->orderBy('order')->get();
    expect($rows)->toHaveCount(3)
        ->and($rows[1]->alignment_chunk)->toBe(-1)
        ->and((float) $rows[0]->similarity)->toBe(0.9);

    // Junctions map positionally onto the copies' own sentences.
    $expectedContents = ['First.', 'Second.', 'Third.'];

    foreach ($rows as $index => $row) {
        $junctions = $row->sentenceMeaningMatches()->get();
        expect($junctions)->toHaveCount(2);

        foreach ($junctions as $junction) {
            $sentence = EntitySentence::find($junction->entity_sentence_id);

            expect($sentence->entity_id)->toBe(
                $junction->side === 'a'
                    ? $newMatch->a_entity_id
                    : $newMatch->b_entity_id,
            )->and($sentence->content)->toBe($expectedContents[$index]);
        }
    }
});

test('the copy mirrors sides when the copies swap orientation', function () {
    $work = createWork();
    $en = hashedEntity('en', $work, 'EN original');
    $ru = hashedEntity('ru', $work, 'RU original');

    // Source orientation: a = whichever entity has the lower id.
    completedSourceMatch($en, $ru);
    $sourceHadEnOnA = min($en->id, $ru->id) === $en->id;

    // Copy pair with the opposite orientation: create the ru copy first so it
    // gets the lower id.
    [$copyFirst, $copySecond] = $sourceHadEnOnA ? ['ru', 'en'] : ['en', 'ru'];

    $copies = [];
    $copies[$copyFirst] = $copyFirst === 'en' ? exactCopy($en, 'EN copy') : exactCopy($ru, 'RU copy');
    $copies[$copySecond] = $copySecond === 'en' ? exactCopy($en, 'EN copy 2') : exactCopy($ru, 'RU copy 2');

    Bus::fake();

    storeMatch($copies['en'], $copies['ru']);

    $newMatch = matchFor($copies['en'], $copies['ru']);
    expect($newMatch->status)->toBe('completed');

    // Every junction's sentence belongs to the correct entity for its side.
    foreach ($newMatch->meaningMatches as $row) {
        foreach ($row->sentenceMeaningMatches as $junction) {
            $sentence = EntitySentence::find($junction->entity_sentence_id);
            $expectedEntityId = $junction->side === 'a' ? $newMatch->a_entity_id : $newMatch->b_entity_id;

            expect($sentence->entity_id)->toBe($expectedEntityId);
        }
    }
});

test('a stale hash on a copy is refreshed synchronously before the lookup', function () {
    $work = createWork();
    $en = hashedEntity('en', $work, 'EN original');
    $ru = hashedEntity('ru', $work, 'RU original');
    completedSourceMatch($en, $ru);

    $enCopy = exactCopy($en, 'EN copy');
    $ruCopy = exactCopy($ru, 'RU copy');

    // Simulate a pending rehash on one copy.
    $enCopy->forceFill(['text_hash' => null, 'text_hashed_at' => null])->save();

    Bus::fake();

    storeMatch($enCopy, $ruCopy);

    expect($enCopy->refresh()->text_hash)->toBe($en->text_hash)
        ->and(matchFor($enCopy, $ruCopy)->status)->toBe('completed');
});

test('falls back to the full pipeline when the positional map cannot hold', function () {
    $work = createWork();
    $en = hashedEntity('en', $work, 'EN original');
    $ru = hashedEntity('ru', $work, 'RU original');
    completedSourceMatch($en, $ru);

    $enCopy = exactCopy($en, 'EN copy');
    $ruCopy = exactCopy($ru, 'RU copy');

    // Corrupt the copy: an extra sentence (count mismatch) but a fresh-looking
    // hash equal to the source, so the lookup matches and the copy guard
    // itself must refuse.
    EntitySentence::create([
        'entity_id' => $ruCopy->id,
        'sentence_type_id' => $ruCopy->sentences()->first()->sentence_type_id,
        'content' => 'Extra.',
        'order' => 999_999,
    ]);
    $ruCopy->forceFill([
        'text_hash' => $ru->text_hash,
        'text_hashed_at' => now(),
        'sentences_updated_at' => now()->subMinute(),
    ])->save();

    Bus::fake();

    storeMatch($enCopy, $ruCopy);

    // The full pipeline takes over: pending or aligning, never completed-by-copy.
    $newMatch = matchFor($enCopy, $ruCopy);

    expect($newMatch->status)->not->toBe('completed');
    Bus::assertDispatched(AlignEntitySentences::class);
});

test('prefers the eligible source with the most confirmed rows', function () {
    $work = createWork();

    // Source A: en/ru original pair, fully junctioned.
    $en = hashedEntity('en', $work, 'EN original');
    $ru = hashedEntity('ru', $work, 'RU original');
    completedSourceMatch($en, $ru);

    // Source B: an earlier exact-copy pair, completed later but with only one
    // confirmed row.
    $enCopy1 = exactCopy($en, 'EN copy 1');
    $ruCopy1 = exactCopy($ru, 'RU copy 1');
    completedSourceMatch($enCopy1, $ruCopy1, confirmedRows: 1)
        ->update(['completed_at' => now()->addMinute()]);

    // Target: another exact-copy pair.
    $enCopy2 = exactCopy($en, 'EN copy 2');
    $ruCopy2 = exactCopy($ru, 'RU copy 2');

    Bus::fake();

    storeMatch($enCopy2, $ruCopy2);

    $newMatch = matchFor($enCopy2, $ruCopy2);

    // The fully-confirmed source won: all three rows carry junctions.
    expect($newMatch->status)->toBe('completed')
        ->and($newMatch->meaningMatches()->count())->toBe(3)
        ->and($newMatch->meaningMatches()->whereHas('sentenceMeaningMatches')->count())->toBe(3);
});

test('with no eligible completed source the full pipeline runs', function () {
    $work = createWork();
    $en = hashedEntity('en', $work, 'EN original');
    $ru = hashedEntity('ru', $work, 'RU original');

    // A completed match pairing ru with a DIFFERENT text: not eligible for
    // the en/ru pair because the a-side hash does not match.
    $otherEn = hashedEntity('en', $work, 'Other EN', ['Different.', 'Content.', 'Entirely.']);
    completedSourceMatch($otherEn, $ru);

    Bus::fake();

    storeMatch($en, $ru)
        ->assertRedirect(route('alignments.index'))
        ->assertSessionHas('success', 'Entity match created — alignment started.');

    Bus::assertDispatched(AlignEntitySentences::class);
});
