<?php

use App\Classes\EntityWordIndexer;
use App\Classes\WordTokenizer;
use App\Models\EntitySentence;
use App\Models\EntitySentenceWord;
use App\Models\EntityWord;
use Carbon\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

function addSentenceForRows($entity, string $content): EntitySentence
{
    return EntitySentence::query()->create([
        'entity_id' => $entity->id,
        'content' => $content,
        'order' => EntitySentence::query()->where('entity_id', $entity->id)->count() * 1024 + 1024,
    ]);
}

function sentenceWordRows(int $sentenceId): array
{
    return EntitySentenceWord::query()
        ->where('entity_sentence_id', $sentenceId)
        ->orderBy('position')
        ->get(['position', 'start', 'end', 'l_word', 'token'])
        ->map(fn ($row) => $row->toArray())
        ->all();
}

it('stores one row per token occurrence with ordinal and char span', function () {
    $entity = createEntity('en');

    $first = addSentenceForRows($entity, 'The quick brown fox jumps over the lazy dog.');
    // Single-character tokens (I, a) and short forms exercise the filter:
    // the rows must carry exactly the tokens tokenize() counts.
    $second = addSentenceForRows($entity, "It's I a go-go.");

    app(EntityWordIndexer::class)->index($entity);

    // "The quick brown fox jumps over the lazy dog." — hand-counted spans.
    expect(sentenceWordRows($first->id))->toBe([
        ['position' => 0, 'start' => 0, 'end' => 3, 'l_word' => 'the', 'token' => 'The'],
        ['position' => 1, 'start' => 4, 'end' => 9, 'l_word' => 'quick', 'token' => 'quick'],
        ['position' => 2, 'start' => 10, 'end' => 15, 'l_word' => 'brown', 'token' => 'brown'],
        ['position' => 3, 'start' => 16, 'end' => 19, 'l_word' => 'fox', 'token' => 'fox'],
        ['position' => 4, 'start' => 20, 'end' => 25, 'l_word' => 'jumps', 'token' => 'jumps'],
        ['position' => 5, 'start' => 26, 'end' => 30, 'l_word' => 'over', 'token' => 'over'],
        ['position' => 6, 'start' => 31, 'end' => 34, 'l_word' => 'the', 'token' => 'the'],
        ['position' => 7, 'start' => 35, 'end' => 39, 'l_word' => 'lazy', 'token' => 'lazy'],
        ['position' => 8, 'start' => 40, 'end' => 43, 'l_word' => 'dog', 'token' => 'dog'],
    ]);

    // "It's I a go-go." — only the two kept occurrences get rows.
    expect(sentenceWordRows($second->id))->toBe([
        ['position' => 0, 'start' => 0, 'end' => 4, 'l_word' => "it's", 'token' => "It's"],
        ['position' => 1, 'start' => 9, 'end' => 14, 'l_word' => 'go-go', 'token' => 'go-go'],
    ]);

    expect($entity->refresh()->words_indexed_at)->not->toBeNull();
});

it('re-indexing rebuilds identical occurrence rows', function () {
    $entity = createEntity('en');
    $sentence = addSentenceForRows($entity, 'One two three, one two.');

    $indexer = app(EntityWordIndexer::class);
    $indexer->index($entity);
    $rows = sentenceWordRows($sentence->id);

    $indexer->index($entity);

    expect(sentenceWordRows($sentence->id))->toBe($rows)
        ->and(EntitySentenceWord::query()->where('entity_id', $entity->id)->count())->toBe(5);
});

it('derives an entity word list identical to the pre-rework tokenize accumulation', function () {
    $entity = createEntity('en');

    addSentenceForRows($entity, 'Dog runs. I a');
    // The representative surface of a repeated l_word is its FIRST
    // occurrence across the document in reading order.
    addSentenceForRows($entity, 'dog dog. Ёлка ёлка!');
    // Case-distinct, hyphenated, quoted and apostrophized forms.
    addSentenceForRows($entity, "«word» MOTHER-IN-LAW's mother-in-law");

    app(EntityWordIndexer::class)->index($entity);

    $derived = EntityWord::query()
        ->where('entity_id', $entity->id)
        ->get(['l_word', 'token', 'count'])
        ->map(fn ($row) => [$row->l_word => ['token' => $row->token, 'count' => (int) $row->count]])
        ->collapse()
        ->all();

    // Hand-written truth: single-character tokens (I, a) must not appear,
    // ё folds into the key while the display token keeps it, counts are
    // summed per key, representatives come from the first occurrence.
    // (ksort: DB collation orders mixed-script keys differently from PHP.)
    $expected = [
        'dog' => ['token' => 'Dog', 'count' => 3],
        'runs' => ['token' => 'runs', 'count' => 1],
        'елка' => ['token' => 'Ёлка', 'count' => 2],
        'word' => ['token' => 'word', 'count' => 1],
        "mother-in-law's" => ['token' => "MOTHER-IN-LAW's", 'count' => 1],
        'mother-in-law' => ['token' => 'mother-in-law', 'count' => 1],
    ];
    ksort($derived);
    ksort($expected);

    expect($derived)->toBe($expected);

    // And that list is exactly what the pre-rework tokenizer accumulation
    // produced for the same text.
    $tokenizer = app(WordTokenizer::class);
    $accumulated = [];
    foreach (EntitySentence::query()->where('entity_id', $entity->id)->orderBy('order')->pluck('content') as $content) {
        foreach ($tokenizer->tokenize($content) as $lWord => $entry) {
            $accumulated[$lWord] ??= ['token' => $entry['token'], 'count' => 0];
            $accumulated[$lWord]['count'] += $entry['count'];
        }
    }
    ksort($accumulated);

    expect($derived)->toBe($accumulated);
});

it('removes token rows via cascade on sentence delete and the next derivation drops the tokens', function () {
    $entity = createEntity('en');
    addSentenceForRows($entity, 'The dog runs.');
    $removed = addSentenceForRows($entity, 'The cat sleeps.');

    app(EntityWordIndexer::class)->index($entity);
    expect(EntitySentenceWord::query()->where('entity_id', $entity->id)->count())->toBe(6);

    $removed->delete();

    // The DB-level cascade removed the deleted sentence's rows.
    expect(EntitySentenceWord::query()->where('entity_sentence_id', $removed->id)->count())->toBe(0)
        ->and(EntitySentenceWord::query()->where('entity_id', $entity->id)->count())->toBe(3);

    // The next derivation no longer counts the removed sentence's tokens.
    app(EntityWordIndexer::class)->index($entity);

    expect(EntityWord::query()->where('entity_id', $entity->id)->where('l_word', 'cat')->exists())->toBeFalse()
        ->and(EntityWord::query()->where('entity_id', $entity->id)->where('l_word', 'dog')->value('count'))->toBe(1);
});

it('stamps each sentence with its own updated_at, not the index time', function () {
    $entity = createEntity('en');
    addSentenceForRows($entity, 'Fresh text.');

    // Advance the clock past the sentence's creation so a now()-based stamp
    // would visibly diverge from the sentence's own updated_at.
    $this->travel(1)->hours();

    app(EntityWordIndexer::class)->index($entity);

    $sentence = EntitySentence::query()->where('entity_id', $entity->id)->first();

    expect($sentence->words_indexed_at->equalTo($sentence->updated_at))->toBeTrue()
        ->and($sentence->words_indexed_at->lessThan(now()))->toBeTrue();

    // The entity-level marker is the max sentence updated_at, as before.
    expect($entity->refresh()->words_indexed_at->equalTo($sentence->updated_at))->toBeTrue();
});
