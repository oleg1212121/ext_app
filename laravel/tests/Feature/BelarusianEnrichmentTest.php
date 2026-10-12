<?php

use App\Classes\SentenceEnrichmentService;
use App\Jobs\EnrichEntitySentences;
use App\Models\Entity;
use App\Models\EntitySentence;
use App\Models\Form;
use App\Models\Language;
use App\Models\User;
use App\Models\Word;
use App\Models\WordClass;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

// Guard: tests that reach the python service never touch the wire — the
// fake client is bound for every test here, and anything that still tries
// an HTTP call fails the run.
beforeEach(function () {
    Http::preventStrayRequests();
    fakePython();
});

function beApprovedUser(): User
{
    return User::factory()->create(['is_approved' => true]);
}

/** A Belarusian entity with the given sentence contents. */
function beEntityWith(array $sentences): Entity
{
    createLanguages(['en', 'ru', 'be']);
    $entity = createEntity('be');

    foreach ($sentences as $index => $content) {
        EntitySentence::create(['entity_id' => $entity->id, 'content' => $content, 'order' => ($index + 1) * 1024]);
    }

    return $entity->refresh();
}

/**
 * A Belarusian word-class row (word_class_id is not nullable, and the shared
 * createWordClasses() helper seeds only en/ru).
 */
function beWordClass(string $slug = 'noun'): WordClass
{
    createLanguages(['en', 'ru', 'be']);

    $languageId = Language::query()->where('code', 'be')->value('id');

    return WordClass::query()->updateOrCreate(
        ['language_id' => $languageId, 'slug' => $slug],
        ['title' => 'Назоўнік'],
    );
}

function beWord(string $word, string $classSlug = 'noun', array $attributes = []): Word
{
    $class = beWordClass($classSlug);

    return Word::query()->create([
        'word' => $word,
        'l_word' => mb_strtolower($word),
        'language_id' => $class->language_id,
        'word_class_id' => $class->id,
        ...$attributes,
    ]);
}

it('enriches a belarusian chunk through the be_stress key', function () {
    $fake = fakePython();

    $entity = beEntityWith(['Мама прыйшла ў пакой.']);

    $result = app(SentenceEnrichmentService::class)->enrichChunk(
        $entity,
        EntitySentence::query()->where('entity_id', $entity->id)->get(),
    );

    expect($result['written'])->toBe(1)
        // The declared python versions ride the chunk result (ADR 0067).
        ->and($result['versions'])->toBe(['be_stress' => 1])
        ->and($fake->enrichPayloads[0]['language'])->toBe('be')
        ->and($fake->enrichPayloads[0]['enrichers'])->toBe(['be_stress']);

    // The keyed echo appends a combining acute — the mark landed in the
    // stress annotation column, the shared one (no phrasal column for be).
    $sentence = EntitySentence::query()->where('entity_id', $entity->id)->first();
    expect($sentence->stressed_content)->toBe('Мама прыйшла ў пакой.́')
        ->and($sentence->phrasal_verbs)->toBeNull();
});

it('stamps the belarusian enricher with both algorithm versions and clears staleness', function () {
    $entity = beEntityWith(['Добры дзень.']);

    Bus::fake();
    (new EnrichEntitySentences($entity->id))->handle();

    $stamp = $entity->refresh()->enrichment_stamps['be_stress'];
    expect($stamp['v'])->toBe(1)
        ->and($stamp['pv'])->toBe(1)
        ->and($stamp['at'])->not->toBeNull()
        // The sweep skips the entity once the fresh stamp is in place.
        ->and(app(SentenceEnrichmentService::class)->isStale($entity->refresh()))->toBeFalse();
});

it('sends dictionary stress hints for belarusian tokens', function () {
    $fake = fakePython()->enrichingNothing();

    $entity = beEntityWith(['Дзяўчына чытае кнігу. Вёска спіць.']);
    $book = beWord('кніга');
    // The forms table carries the stressed inflected form (acute style).
    Form::query()->create(['word_id' => $book->id, 'form' => 'кні́гу', 'l_word' => 'кнігу']);
    // The ё-headword carries the stress by itself (same check as Russian).
    beWord('вёска');

    app(SentenceEnrichmentService::class)->enrichChunk(
        $entity->refresh(),
        EntitySentence::query()->where('entity_id', $entity->id)->get(),
    );

    $tokens = collect($fake->enrichPayloads[0]['sentences'][0]['tokens']);
    expect($tokens->firstWhere('surface', 'кнігу')['stressed'])->toBe(['кні́гу'])
        ->and($tokens->firstWhere('surface', 'Вёска')['stressed'])->toBe(['вёска']);
});

it('ships no stress hints until belarusian dictionary data exists', function () {
    $fake = fakePython()->enrichingNothing();

    $entity = beEntityWith(['Яна ідзе дадому.']);

    app(SentenceEnrichmentService::class)->enrichChunk(
        $entity,
        EntitySentence::query()->where('entity_id', $entity->id)->get(),
    );

    // Harmless empty hints: no dictionary rows, no stressed field on the wire.
    expect($fake->enrichPayloads[0]['sentences'][0]['tokens'])
        ->each(fn ($token) => $token->not->toHaveKey('stressed'));
});

it('ships the belarusian stressed variant through the reader vertical', function () {
    $user = beApprovedUser();

    $entity = beEntityWith(['Мама прыйшла дадому.']);
    EntitySentence::query()->where('entity_id', $entity->id)->first()
        ->forceFill(['stressed_content' => 'Ма́ма прыйшла дадо́му.'])
        ->saveQuietly();

    $props = $this->actingAs($user)->get("/reader/{$entity->id}")->assertOk()->inertiaPage()['props'];

    expect($props['rows'][0]['a']['sentences'][0]['stressed'])->toBe('Ма́ма прыйшла дадо́му.');
});
