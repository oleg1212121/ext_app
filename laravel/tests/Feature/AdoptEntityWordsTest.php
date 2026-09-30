<?php

use App\Classes\EntityWordAdoption;
use App\Classes\EntityWordIndexer;
use App\Classes\EntityWordLinker;
use App\Jobs\FetchWordTranslations;
use App\Jobs\RefreshEntityWords;
use App\Models\EntityWord;
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
        ->and($word->language->code)->toBe('en');
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
