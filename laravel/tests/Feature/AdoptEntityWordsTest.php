<?php

use App\Classes\EntityWordAdoption;
use App\Classes\EntityWordIndexer;
use App\Classes\EntityWordLinker;
use App\Jobs\FetchWordTranslations;
use App\Jobs\RefreshEntityWords;
use App\Models\EntityWord;
use App\Models\Form;
use App\Models\Language;
use App\Models\Word;
use App\Models\WordTranslation;
use Illuminate\Support\Facades\Queue;

if (! function_exists('adoptionEntityWord')) {
    function adoptionEntityWord($entity, string $token, ?int $wordId = null, bool $stamped = false): EntityWord
    {
        return EntityWord::query()->create([
            'entity_id' => $entity->id,
            'word_id' => $wordId,
            'l_word' => mb_strtolower($token),
            'token' => $token,
            'count' => 1,
            'unmatchable_at' => $stamped ? now() : null,
        ]);
    }
}

it('adopts stamped tokens into the dictionary under the unknown class', function () {
    $entity = createEntity('en');
    $stamped = adoptionEntityWord($entity, 'Flibbertigibbet', stamped: true);
    $waiting = adoptionEntityWord($entity, 'Pending'); // unstamped — still awaiting a normal link pass

    $this->artisan('words:adopt-from-entities')->assertSuccessful();

    $stamped->refresh();

    expect($stamped->word_id)->not->toBeNull()
        ->and($stamped->unmatchable_at)->toBeNull()
        ->and($waiting->refresh()->word_id)->toBeNull();

    $word = Word::query()->findOrFail($stamped->word_id);

    expect($word->word)->toBe('Flibbertigibbet')
        ->and($word->l_word)->toBe('flibbertigibbet')
        ->and($word->wordClass->slug)->toBe('unknown')
        ->and($word->language->code)->toBe('en')
        // The adopted word carries itself as its lemma form — the forms
        // table alone must resolve lookups.
        ->and(Form::query()->where('word_id', $word->id)->pluck('form')->all())
        ->toBe(['Flibbertigibbet']);
});

it('adopts the existing row when the token’s l_word already exists, preferring the classed one', function () {
    $entity = createEntity('en');

    // A classed row already carries the token's l_word (e.g. it became
    // matchable after the link stamp): adoption must attach to it, not
    // mint an unknown twin.
    $dog = createWord('en', 'dog', 'noun');
    $stampedDog = adoptionEntityWord($entity, 'dog', stamped: true);

    // Even a lower-id unknown row must lose to the classed one.
    $runUnknown = createWord('en', 'run', 'unknown');
    $runVerb = createWord('en', 'run', 'verb');
    $stampedRun = adoptionEntityWord($entity, 'run', stamped: true);

    $this->artisan('words:adopt-from-entities')->assertSuccessful();

    expect($stampedDog->refresh()->word_id)->toBe($dog->id)
        ->and($stampedRun->refresh()->word_id)->toBe($runVerb->id)
        ->and($runVerb->id)->toBeGreaterThan($runUnknown->id)
        ->and(Word::query()->where('l_word', 'dog')->count())->toBe(1)
        ->and(Word::query()->where('l_word', 'run')->count())->toBe(2);
});

it('stores a genuinely new ru token as a plain display word with its lemma form', function () {
    $entity = createEntity('ru');

    // Raw surface token carries the combining stress mark; the indexer
    // already keyed it plain (the tokenizer rule).
    $stamped = EntityWord::query()->create([
        'entity_id' => $entity->id,
        'l_word' => 'собаку',
        'token' => 'соба́ку',
        'count' => 1,
        'unmatchable_at' => now(),
    ]);

    $this->artisan('words:adopt-from-entities')->assertSuccessful();

    $stamped->refresh();
    $word = Word::query()->findOrFail($stamped->word_id);

    expect($word->word)->toBe('собаку')
        ->and($word->word)->not->toBe('соба́ку')
        ->and($word->l_word)->toBe('собаку')
        ->and($word->wordClass->slug)->toBe('unknown')
        ->and(Form::query()->where('word_id', $word->id)->pluck('form')->all())
        ->toBe(['собаку']);
});

it('queues fetches for adopted and translation-less words, never for translated ones', function () {
    Queue::fake();
    enableTranslationProviders();

    $entity = createEntity('en');

    $dog = createWord('en', 'dog', 'noun');
    $cat = createWord('en', 'cat', 'noun');
    WordTranslation::link($cat->id, createWord('ru', 'кот', 'noun')->id);

    adoptionEntityWord($entity, 'Flibbertigibbet', stamped: true);
    adoptionEntityWord($entity, 'dog', $dog->id);
    adoptionEntityWord($entity, 'cat', $cat->id);

    $this->artisan('words:adopt-from-entities')->assertSuccessful();

    $adoptedId = (int) EntityWord::query()->where('l_word', 'flibbertigibbet')->value('word_id');

    Queue::assertPushed(FetchWordTranslations::class, fn (FetchWordTranslations $job) => $job->wordId === $adoptedId);
    Queue::assertPushed(FetchWordTranslations::class, fn (FetchWordTranslations $job) => $job->wordId === $dog->id);
    Queue::assertNotPushed(FetchWordTranslations::class, fn (FetchWordTranslations $job) => $job->wordId === $cat->id);
    expect(Queue::pushed(FetchWordTranslations::class))->toHaveCount(2);
});

it('limits fetch targets to the --to option', function () {
    Queue::fake();
    enableTranslationProviders();

    Language::query()->updateOrCreate(
        ['code' => 'de'],
        ['name' => 'German', 'is_enabled' => true, 'is_interface_enabled' => false, 'sort_order' => 2],
    );

    $entity = createEntity('en');
    adoptionEntityWord($entity, 'Flibbertigibbet', stamped: true);

    $this->artisan('words:adopt-from-entities', ['--to' => 'ru'])->assertSuccessful();

    // One fetch for the adopted word, into ru only — de excluded by --to.
    expect(Queue::pushed(FetchWordTranslations::class))->toHaveCount(1);
});

it('adopts after linking inside the refresh job', function () {
    $entity = createEntity('en');

    // Keep the word index fresh so the job links and adopts without
    // rebuilding (a rebuild would wipe the hand-seeded entity_words rows).
    $entity->update(['words_indexed_at' => now()]);

    adoptionEntityWord($entity, 'Flibbertigibbet', stamped: true);

    (new RefreshEntityWords($entity->id))
        ->handle(app(EntityWordIndexer::class), app(EntityWordLinker::class), app(EntityWordAdoption::class));

    expect(
        EntityWord::query()->where('l_word', 'flibbertigibbet')->whereNotNull('word_id')->exists(),
    )->toBeTrue();
});
