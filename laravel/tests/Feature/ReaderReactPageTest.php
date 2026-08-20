<?php

use App\Models\EnEntity;
use App\Models\EnEntitySentence;
use App\Models\EnRuEntityMatch;
use App\Models\EnRuMeaningMatch;
use App\Models\EnSentenceMeaningMatch;
use App\Models\RuEntity;
use App\Models\RuEntitySentence;
use App\Models\RuSentenceMeaningMatch;
use App\Models\SentenceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    SentenceType::create(['name' => 'sentence', 'description' => 'A standard sentence']);
});

/**
 * @return array{en: EnEntity, ru: RuEntity, entityMatch: EnRuEntityMatch}
 */
function createAlignedReaderEntities(): array
{
    $en = EnEntity::query()->create([
        'name' => 'Test EN Entity',
        'file_path' => 'texts/simulator/test_en.txt',
    ]);
    $ru = RuEntity::query()->create([
        'name' => 'Test RU Entity',
        'file_path' => 'texts/simulator/test_ru.txt',
    ]);

    $enSentence = EnEntitySentence::query()->create([
        'en_entity_id' => $en->id,
        'content' => 'First EN sentence.',
        'order' => 1,
    ]);
    $ruSentence = RuEntitySentence::query()->create([
        'ru_entity_id' => $ru->id,
        'content' => 'First RU sentence.',
        'order' => 1,
    ]);

    $entityMatch = EnRuEntityMatch::query()->create([
        'en_entity_id' => $en->id,
        'ru_entity_id' => $ru->id,
        'status' => 'completed',
        'linked_count' => 1,
    ]);

    $meaningMatch = EnRuMeaningMatch::query()->create([
        'en_ru_entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 1.0,
        'alignment_chunk' => 0,
    ]);

    EnSentenceMeaningMatch::query()->create([
        'en_entity_sentence_id' => $enSentence->id,
        'en_ru_meaning_match_id' => $meaningMatch->id,
    ]);
    RuSentenceMeaningMatch::query()->create([
        'ru_entity_sentence_id' => $ruSentence->id,
        'en_ru_meaning_match_id' => $meaningMatch->id,
    ]);

    return [
        'en' => $en,
        'ru' => $ru,
        'entityMatch' => $entityMatch,
    ];
}

test('guests are redirected from reader react page', function () {
    $this->get(route('reader.react', ['lang' => 'en', 'entityId' => 1]))
        ->assertRedirect(route('login'));
});

test('guests are redirected from reader react index page', function () {
    $this->get(route('reader.react.index', ['lang' => 'en']))
        ->assertRedirect(route('login'));
});

test('reader react index redirects bare path to english route', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/reader-react')
        ->assertRedirect('/reader-react/en');
});

test('authenticated users can view reader react index with english entities', function () {
    $user = User::factory()->create();
    $enEntity = EnEntity::query()->create([
        'name' => 'Index EN Entity',
        'file_path' => 'texts/simulator/index_en.txt',
    ]);

    $this->actingAs($user)
        ->get(route('reader.react.index', ['lang' => 'en']))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('ReaderReactIndex')
            ->where('lang', 'en')
            ->where('languages', ['en', 'ru'])
            ->has('entities', 1)
            ->where('entities.0.id', $enEntity->id)
            ->where('entities.0.name', 'Index EN Entity'));
});

test('authenticated users can view reader react index with russian entities', function () {
    $user = User::factory()->create();
    $ruEntity = RuEntity::query()->create([
        'name' => 'Index RU Entity',
        'file_path' => 'texts/simulator/index_ru.txt',
    ]);

    $this->actingAs($user)
        ->get(route('reader.react.index', ['lang' => 'ru']))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('ReaderReactIndex')
            ->where('lang', 'ru')
            ->has('entities', 1)
            ->where('entities.0.id', $ruEntity->id)
            ->where('entities.0.name', 'Index RU Entity'));
});

test('unsupported reader react index language returns not found', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/reader-react/de')
        ->assertNotFound();
});

test('authenticated users can view reader react page with english primary rows', function () {
    $user = User::factory()->create();
    $entities = createAlignedReaderEntities();

    $this->actingAs($user)
        ->get(route('reader.react', ['lang' => 'en', 'entityId' => $entities['en']->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('ReaderReact')
            ->where('lang', 'en')
            ->where('entity.id', $entities['en']->id)
            ->where('entity.name', 'Test EN Entity')
            ->has('rows', 1)
            ->where('rows.0.0', 'First EN sentence.')
            ->where('rows.0.1', 'First RU sentence.'));
});

test('authenticated users can view reader react page with russian primary rows', function () {
    $user = User::factory()->create();
    $entities = createAlignedReaderEntities();

    $this->actingAs($user)
        ->get(route('reader.react', ['lang' => 'ru', 'entityId' => $entities['ru']->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('ReaderReact')
            ->where('lang', 'ru')
            ->where('entity.id', $entities['ru']->id)
            ->where('entity.name', 'Test RU Entity')
            ->has('rows', 1)
            ->where('rows.0.0', 'First RU sentence.')
            ->where('rows.0.1', 'First EN sentence.'));
});

test('unsupported reader react language returns not found', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/reader-react/de/1')
        ->assertNotFound();
});

test('missing reader react entity returns not found', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('reader.react', ['lang' => 'en', 'entityId' => 99999]))
        ->assertNotFound();
});

test('entity without alignment returns single language rows', function () {
    $user = User::factory()->create();
    $en = EnEntity::query()->create([
        'name' => 'Unaligned EN Entity',
        'file_path' => 'texts/simulator/unaligned.txt',
    ]);

    EnEntitySentence::query()->create([
        'en_entity_id' => $en->id,
        'content' => 'Standalone EN sentence.',
        'order' => 1,
    ]);

    $this->actingAs($user)
        ->get(route('reader.react', ['lang' => 'en', 'entityId' => $en->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('ReaderReact')
            ->where('lang', 'en')
            ->has('rows', 1)
            ->where('rows.0.0', 'Standalone EN sentence.')
            ->where('rows.0.1', ''));
});
