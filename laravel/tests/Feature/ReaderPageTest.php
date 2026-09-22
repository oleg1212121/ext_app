<?php

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\EntityWord;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use App\Models\User;
use App\Models\UserApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    SentenceType::create(['name' => 'sentence', 'description' => 'A standard sentence']);
});

/**
 * @return array{en: Entity, ru: Entity, entityMatch: EntityMatch}
 */
function createAlignedReaderEntities(): array
{
    $work = createWork();

    $en = createEntity('en', $work, [
        'name' => 'Test EN Entity',
        'file_path' => 'texts/simulator/test_en.txt',
    ]);
    $ru = createEntity('ru', $work, [
        'name' => 'Test RU Entity',
        'file_path' => 'texts/simulator/test_ru.txt',
    ]);

    $enSentence = EntitySentence::query()->create([
        'entity_id' => $en->id,
        'content' => 'First EN sentence about a cat.',
        'order' => 1,
    ]);
    $ruSentence = EntitySentence::query()->create([
        'entity_id' => $ru->id,
        'content' => 'Первое RU sentence.',
        'order' => 1,
    ]);

    $entityMatch = createEntityMatch($en, $ru, [
        'status' => 'completed',
        'linked_count' => 1,
    ]);

    $meaningMatch = MeaningMatch::query()->create([
        'entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 1.0,
        'alignment_chunk' => 0,
    ]);

    SentenceMeaningMatch::query()->create([
        'entity_sentence_id' => $enSentence->id,
        'meaning_match_id' => $meaningMatch->id,
        'side' => 'a',
    ]);
    SentenceMeaningMatch::query()->create([
        'entity_sentence_id' => $ruSentence->id,
        'meaning_match_id' => $meaningMatch->id,
        'side' => 'b',
    ]);

    return [
        'en' => $en,
        'ru' => $ru,
        'entityMatch' => $entityMatch,
    ];
}

/**
 * A text long enough to cross the reader's page boundary (50 rows per
 * page): $count aligned meaning matches, row i saying "Row {i} sentence."
 * on each side. Pagination is a scale boundary, so this is the one fixture
 * allowed to go big.
 *
 * @return array{en: Entity, ru: Entity, entityMatch: EntityMatch}
 */
function createLongAlignedText(int $count): array
{
    $work = createWork();

    $en = createEntity('en', $work, [
        'name' => 'Long EN Entity',
        'file_path' => 'texts/simulator/long_en.txt',
    ]);
    $ru = createEntity('ru', $work, [
        'name' => 'Long RU Entity',
        'file_path' => 'texts/simulator/long_ru.txt',
    ]);

    $entityMatch = createEntityMatch($en, $ru, [
        'status' => 'completed',
        'linked_count' => $count,
    ]);

    foreach (range(1, $count) as $i) {
        $enSentence = EntitySentence::query()->create([
            'entity_id' => $en->id,
            'content' => "Row {$i} sentence.",
            'order' => $i,
        ]);
        $ruSentence = EntitySentence::query()->create([
            'entity_id' => $ru->id,
            'content' => "Строка {$i} sentence.",
            'order' => $i,
        ]);

        $meaningMatch = MeaningMatch::query()->create([
            'entity_match_id' => $entityMatch->id,
            'order' => $i - 1,
            'similarity' => 1.0,
            'alignment_chunk' => 0,
        ]);

        SentenceMeaningMatch::query()->create([
            'entity_sentence_id' => $enSentence->id,
            'meaning_match_id' => $meaningMatch->id,
            'side' => 'a',
        ]);
        SentenceMeaningMatch::query()->create([
            'entity_sentence_id' => $ruSentence->id,
            'meaning_match_id' => $meaningMatch->id,
            'side' => 'b',
        ]);
    }

    return ['en' => $en, 'ru' => $ru, 'entityMatch' => $entityMatch];
}

test('guests are redirected from reader page', function () {
    $this->get(route('reader.show', ['lang' => 'en', 'entityId' => 1]))
        ->assertRedirect(route('login'));
});

test('the legacy reader paths are gone', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/reader-react')->assertNotFound();
    $this->actingAs($user)->get('/reader-react/en')->assertNotFound();
    $this->actingAs($user)->get('/reader-react/en/1')->assertNotFound();
    $this->actingAs($user)->get('/reader')->assertNotFound();
    $this->actingAs($user)->get('/reader/en')->assertNotFound();
});

test('authenticated users can view reader page with english primary rows', function () {
    $user = User::factory()->create();
    $entities = createAlignedReaderEntities();

    $this->actingAs($user)
        ->get(route('reader.show', ['lang' => 'en', 'entityId' => $entities['en']->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->where('lang', 'en')
            ->where('entity.id', $entities['en']->id)
            ->where('entity.name', 'Test EN Entity')
            ->has('rows', 1)
            ->where('rows.0.0', 'First EN sentence about a cat.')
            ->where('rows.0.1', 'Первое RU sentence.')
            ->has('rowKeys', 1)
            ->where('rowKeys.0', 'mm:'.MeaningMatch::query()->where('entity_match_id', $entities['entityMatch']->id)->value('id')));
});

test('authenticated users can view reader page with russian primary rows', function () {
    $user = User::factory()->create();
    $entities = createAlignedReaderEntities();

    $this->actingAs($user)
        ->get(route('reader.show', ['lang' => 'ru', 'entityId' => $entities['ru']->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->where('lang', 'ru')
            ->where('entity.id', $entities['ru']->id)
            ->where('entity.name', 'Test RU Entity')
            ->has('rows', 1)
            ->where('rows.0.0', 'Первое RU sentence.')
            ->where('rows.0.1', 'First EN sentence about a cat.')
            // Row keys follow the rows, whatever side is being read.
            ->has('rowKeys', 1)
            ->where('rowKeys.0', 'mm:'.MeaningMatch::query()->where('entity_match_id', $entities['entityMatch']->id)->value('id')));
});

test('unsupported reader language returns not found', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/reader/de/1')
        ->assertNotFound();
});

test('missing reader entity returns not found', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('reader.show', ['lang' => 'en', 'entityId' => 99999]))
        ->assertNotFound();
});

test('entity without alignment returns single language rows', function () {
    $user = User::factory()->create();
    $en = createEntity('en', null, [
        'name' => 'Unaligned EN Entity',
        'file_path' => 'texts/simulator/unaligned.txt',
    ]);

    EntitySentence::query()->create([
        'entity_id' => $en->id,
        'content' => 'Standalone EN sentence.',
        'order' => 1,
    ]);

    $this->actingAs($user)
        ->get(route('reader.show', ['lang' => 'en', 'entityId' => $en->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->where('lang', 'en')
            ->has('rows', 1)
            ->where('rows.0.0', 'Standalone EN sentence.')
            ->where('rows.0.1', '')
            // Unaligned rows are keyed by their entity sentence.
            ->has('rowKeys', 1)
            ->where('rowKeys.0', 'es:'.EntitySentence::query()->where('entity_id', $en->id)->value('id')));
});

test('reader page includes the interactive word map with familiarity values', function () {
    $user = User::factory()->create();
    $entities = createAlignedReaderEntities();

    $cat = createWord('en', 'cat', 'noun');
    EntityWord::query()->create([
        'entity_id' => $entities['en']->id,
        'word_id' => $cat->id,
        'l_word' => 'cat',
        'token' => 'cat',
        'count' => 1,
    ]);
    // Unlinked tokens must not appear in the map.
    EntityWord::query()->create([
        'entity_id' => $entities['en']->id,
        'word_id' => null,
        'l_word' => 'xylophone',
        'token' => 'xylophone',
        'count' => 1,
    ]);
    $known = createWord('en', 'sentence', 'noun');
    EntityWord::query()->create([
        'entity_id' => $entities['en']->id,
        'word_id' => $known->id,
        'l_word' => 'sentence',
        'token' => 'sentence',
        'count' => 1,
    ]);
    $user->userWords()->create(['word_id' => $known->id, 'familiarity' => 100]);

    $ruWord = createWord('ru', 'первое', 'noun');
    EntityWord::query()->create([
        'entity_id' => $entities['ru']->id,
        'word_id' => $ruWord->id,
        'l_word' => 'первое',
        'token' => 'Первое',
        'count' => 1,
    ]);

    $this->actingAs($user)
        ->get(route('reader.show', ['lang' => 'en', 'entityId' => $entities['en']->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->where('wordMap.cat.w', $cat->id)
            ->where('wordMap.cat.s', null)
            ->where('wordMap.sentence.s', 100)
            ->where('translationWordMap.первое.w', $ruWord->id)
            ->where('highlight', true)
            // Factory users are native English speakers: the EN primary side
            // is native (not highlightable), the RU translation side is.
            ->where('primaryHighlightable', false)
            ->where('translationHighlightable', true));
});

test('reader page paginates rows and reports meta', function () {
    $user = User::factory()->create();
    $text = createLongAlignedText(60);

    $this->actingAs($user)
        ->get(route('reader.show', ['lang' => 'en', 'entityId' => $text['en']->id, 'page' => 2]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->has('rows', 10)
            ->where('rows.0.0', 'Row 51 sentence.')
            ->has('rowKeys', 10)
            ->where('meta.current_page', 2)
            ->where('meta.per_page', 50)
            ->where('meta.total', 60)
            ->where('meta.last_page', 2)
            ->where('positionKey', 'mm:'.$text['entityMatch']->id));
});

test('an out-of-range reader page clamps to the last page', function () {
    $user = User::factory()->create();
    $text = createLongAlignedText(60);

    $this->actingAs($user)
        ->get(route('reader.show', ['lang' => 'en', 'entityId' => $text['en']->id, 'page' => 99]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->has('rows', 10)
            ->where('rows.0.0', 'Row 51 sentence.')
            ->where('meta.current_page', 2)
            ->where('meta.last_page', 2));
});

test('junk reader page values resolve to the first page', function () {
    $user = User::factory()->create();
    $text = createLongAlignedText(60);

    $this->actingAs($user)
        ->get(route('reader.show', ['lang' => 'en', 'entityId' => $text['en']->id, 'page' => 'abc']))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->has('rows', 50)
            ->where('rows.0.0', 'Row 1 sentence.')
            ->where('meta.current_page', 1));

    $this->actingAs($user)
        ->get(route('reader.show', ['lang' => 'en', 'entityId' => $text['en']->id, 'page' => 0]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->where('rows.0.0', 'Row 1 sentence.')
            ->where('meta.current_page', 1));
});

test('both reading sides of a match share one position key', function () {
    $user = User::factory()->create();
    $text = createLongAlignedText(1);

    $this->actingAs($user)
        ->get(route('reader.show', ['lang' => 'ru', 'entityId' => $text['ru']->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->where('rows.0.0', 'Строка 1 sentence.')
            ->where('meta.last_page', 1)
            ->where('positionKey', 'mm:'.$text['entityMatch']->id));
});

test('a single language entity paginates with an entity position key', function () {
    $user = User::factory()->create();
    $en = createEntity('en', null, [
        'name' => 'Long Single Entity',
        'file_path' => 'texts/simulator/long_single.txt',
    ]);

    foreach (range(1, 55) as $i) {
        EntitySentence::query()->create([
            'entity_id' => $en->id,
            'content' => "Line {$i}.",
            'order' => $i,
        ]);
    }

    $this->actingAs($user)
        ->get(route('reader.show', ['lang' => 'en', 'entityId' => $en->id, 'page' => 2]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->has('rows', 5)
            ->where('rows.0.0', 'Line 51.')
            ->where('meta.total', 55)
            ->where('meta.last_page', 2)
            ->where('rowKeys.0', 'es:'.EntitySentence::query()
                ->where('entity_id', $en->id)
                ->where('order', 51)
                ->value('id'))
            ->where('positionKey', 'ent:'.$en->id));
});

test('the word map is scoped to the rows on the current page', function () {
    $user = User::factory()->create();
    $text = createLongAlignedText(55);

    // 'orbit' exists in the entity's word list but only in a page-2 row.
    $orbit = createWord('en', 'orbit', 'noun');
    EntityWord::query()->create([
        'entity_id' => $text['en']->id,
        'word_id' => $orbit->id,
        'l_word' => 'orbit',
        'token' => 'orbit',
        'count' => 1,
    ]);
    EntitySentence::query()
        ->where('entity_id', $text['en']->id)
        ->where('order', 55)
        ->update(['content' => 'The orbit decays.']);

    $this->actingAs($user)
        ->get(route('reader.show', ['lang' => 'en', 'entityId' => $text['en']->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->has('rows', 50)
            ->missing('wordMap.orbit'));

    $this->actingAs($user)
        ->get(route('reader.show', ['lang' => 'en', 'entityId' => $text['en']->id, 'page' => 2]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->has('rows', 5)
            ->where('wordMap.orbit.w', $orbit->id));
});

test('reader page passes explanation gating and side props', function () {
    $user = User::factory()->create();
    $entities = createAlignedReaderEntities();

    $this->actingAs($user)
        ->get(route('reader.show', ['lang' => 'en', 'entityId' => $entities['en']->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            // Reading the EN side: primary is native, translation is not.
            ->where('primaryExplainable', false)
            ->where('translationExplainable', true)
            ->where('primarySide', 'a')
            // No API keys yet: AI explanations stay off.
            ->where('explain.enabled', false)
            ->where('explain.modelKey', null));
});

test('reader page carries the explanation model when the user can use AI', function () {
    $user = User::factory()->create();
    $entities = createAlignedReaderEntities();

    $provider = AiProvider::factory()->enabled()->create(['key' => 'openrouter', 'name' => 'OpenRouter']);
    $model = AiModel::factory()->enabled()->create(['ai_provider_id' => $provider->id, 'external_id' => 'explain', 'name' => 'Explain Model']);
    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $provider->id]);
    $user->settings()->updateOrCreate(['user_id' => $user->id], ['ai_model_id' => $model->id]);

    $this->actingAs($user)
        ->get(route('reader.show', ['lang' => 'en', 'entityId' => $entities['en']->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->where('explain.enabled', true)
            ->where('explain.modelKey', $model->id));
});

test('a single language reader page has no primary side', function () {
    $user = User::factory()->create();
    $en = createEntity('en', null, [
        'name' => 'Unaligned EN Entity',
        'file_path' => 'texts/simulator/unaligned.txt',
    ]);

    EntitySentence::query()->create([
        'entity_id' => $en->id,
        'content' => 'Standalone EN sentence.',
        'order' => 1,
    ]);

    $this->actingAs($user)
        ->get(route('reader.show', ['lang' => 'en', 'entityId' => $en->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->where('primarySide', null)
            // The factory user is a native English speaker: the EN primary
            // column is native, so it is not explainable.
            ->where('primaryExplainable', false)
            ->where('translationExplainable', false));
});
