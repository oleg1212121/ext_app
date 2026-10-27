<?php

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\EntityWord;
use App\Models\Language;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use App\Models\User;
use App\Models\UserApiKey;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    SentenceType::create(['name' => 'sentence', 'description' => 'A standard sentence']);
});

/**
 * @return array{work: Work, en: Entity, ru: Entity, entityMatch: EntityMatch}
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
        'work' => $work,
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
 * @return array{work: Work, en: Entity, ru: Entity, entityMatch: EntityMatch}
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

    return ['work' => $work, 'en' => $en, 'ru' => $ru, 'entityMatch' => $entityMatch];
}

test('the legacy reader paths are gone', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/reader-react')->assertNotFound();
    $this->actingAs($user)->get('/reader-react/en')->assertNotFound();
    $this->actingAs($user)->get('/reader-react/en/1')->assertNotFound();
    // The language segment is gone from the reader surface: the old
    // two-segment shape and the language-tab index no longer resolve,
    // whatever the language code (/reader itself is the works list).
    $this->actingAs($user)->get('/reader/en')->assertNotFound();
    $this->actingAs($user)->get('/reader/de')->assertNotFound();
    $this->actingAs($user)->get('/reader/en/1')->assertNotFound();
    $this->actingAs($user)->get('/reader/de/1')->assertNotFound();
    // The URL split's contract phase: the entity-anchored auto-detect
    // reader is gone too, without a redirect (house removal style) — a
    // bare id under /reader resolves to nothing even when the entity
    // exists, and the route name with it.
    $entity = createEntity('en');
    $this->actingAs($user)->get('/reader/'.$entity->id)->assertNotFound();
    expect(Route::has('reader.show'))->toBeFalse();
});

test('guests are redirected from the works list', function () {
    $this->get('/reader')->assertRedirect(route('login'));
});

test('without a native side the work original is read', function () {
    $user = User::factory()->create();
    $french = Language::query()->create([
        'code' => 'fr',
        'name' => 'French',
        'is_enabled' => true,
        'sort_order' => 5,
    ]);
    $german = Language::query()->create([
        'code' => 'de',
        'name' => 'German',
        'is_enabled' => true,
        'sort_order' => 6,
    ]);

    // Original French work, German translation; the native EN side of the
    // user matches neither, so the original (A) side is read.
    $work = createWork(['original_language_id' => $french->id]);
    $fr = Entity::query()->create([
        'work_id' => $work->id,
        'language_id' => $french->id,
        'name' => 'Test FR Entity',
    ]);
    $de = Entity::query()->create([
        'work_id' => $work->id,
        'language_id' => $german->id,
        'name' => 'Test DE Entity',
    ]);

    $frSentence = EntitySentence::query()->create([
        'entity_id' => $fr->id,
        'content' => 'Phrase originale.',
        'order' => 1,
    ]);
    $entityMatch = createEntityMatch($fr, $de, ['status' => 'completed', 'linked_count' => 1]);
    $meaningMatch = MeaningMatch::query()->create([
        'entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 1.0,
        'alignment_chunk' => 0,
    ]);
    SentenceMeaningMatch::query()->create([
        'entity_sentence_id' => $frSentence->id,
        'meaning_match_id' => $meaningMatch->id,
        'side' => 'a',
    ]);

    $this->actingAs($user)
        ->get(route('reader.works.alignments.show', ['work' => $work->id, 'match' => $entityMatch->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->where('langs.a', 'fr')
            ->where('langs.b', 'de')
            ->where('defaultSide', 'a')
            ->where('rows.0.a.sentences.0.text', 'Phrase originale.'));
});

test('with no native and no original side the a-side is read', function () {
    $user = User::factory()->create();

    // Two translations (ru, fr) of an English-original work: neither side
    // is native for the factory user and neither is the original, so the
    // canonical A-side is read against the B-side.
    $work = createWork();
    $french = Language::query()->create([
        'code' => 'fr',
        'name' => 'French',
        'is_enabled' => true,
        'sort_order' => 5,
    ]);
    $ru = createEntity('ru', $work, ['name' => 'Test RU Entity']);
    $fr = Entity::query()->create([
        'work_id' => $work->id,
        'language_id' => $french->id,
        'name' => 'Test FR Entity',
    ]);

    $ruSentence = EntitySentence::query()->create([
        'entity_id' => $ru->id,
        'content' => 'Первое RU sentence.',
        'order' => 1,
    ]);
    $entityMatch = createEntityMatch($ru, $fr, ['status' => 'completed', 'linked_count' => 1]);
    $meaningMatch = MeaningMatch::query()->create([
        'entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 1.0,
        'alignment_chunk' => 0,
    ]);
    SentenceMeaningMatch::query()->create([
        'entity_sentence_id' => $ruSentence->id,
        'meaning_match_id' => $meaningMatch->id,
        'side' => 'a',
    ]);

    $this->actingAs($user)
        ->get(route('reader.works.alignments.show', ['work' => $work->id, 'match' => $entityMatch->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->where('langs.a', 'ru')
            ->where('langs.b', 'fr')
            ->where('defaultSide', 'a')
            ->where('rows.0.a.sentences.0.text', 'Первое RU sentence.'));
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
        ->get(route('reader.works.entities.show', ['work' => $en->work_id, 'entity' => $en->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->where('langs.a', 'en')
            ->where('langs.b', null)
            ->where('defaultSide', null)
            ->has('rows', 1)
            ->where('rows.0.a.sentences.0.text', 'Standalone EN sentence.')
            // Unaligned rows are keyed by their entity sentence; the
            // translation side does not exist at all.
            ->where('rows.0.b', null)
            ->where('rows.0.key', 'es:'.EntitySentence::query()->where('entity_id', $en->id)->value('id')));
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
        ->get(route('reader.works.alignments.show', ['work' => $entities['work']->id, 'match' => $entities['entityMatch']->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            // The side rule reads the RU side, so the b-side map carries RU
            // words and the a-side map the EN ones.
            ->where('wordMaps.b.первое.w', $ruWord->id)
            ->where('wordMaps.a.cat.w', $cat->id)
            ->where('wordMaps.a.cat.s', null)
            ->where('wordMaps.a.sentence.s', 100)
            ->where('highlight', true)
            // Factory users are native English speakers: the RU b-side is
            // not native (highlightable), the EN a-side is.
            ->where('highlightable.b', true)
            ->where('highlightable.a', false));
});

test('reader page paginates rows and reports meta', function () {
    $user = User::factory()->create();
    $text = createLongAlignedText(60);

    $this->actingAs($user)
        ->get(route('reader.works.alignments.show', ['work' => $text['work']->id, 'match' => $text['entityMatch']->id, 'page' => 2]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->has('rows', 10)
            ->where('rows.0.b.sentences.0.text', 'Строка 51 sentence.')
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
        ->get(route('reader.works.alignments.show', ['work' => $text['work']->id, 'match' => $text['entityMatch']->id, 'page' => 99]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->has('rows', 10)
            ->where('rows.0.b.sentences.0.text', 'Строка 51 sentence.')
            ->where('meta.current_page', 2)
            ->where('meta.last_page', 2));
});

test('junk reader page values resolve to the first page', function () {
    $user = User::factory()->create();
    $text = createLongAlignedText(60);

    $this->actingAs($user)
        ->get(route('reader.works.alignments.show', ['work' => $text['work']->id, 'match' => $text['entityMatch']->id, 'page' => 'abc']))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->has('rows', 50)
            ->where('rows.0.b.sentences.0.text', 'Строка 1 sentence.')
            ->where('meta.current_page', 1));

    $this->actingAs($user)
        ->get(route('reader.works.alignments.show', ['work' => $text['work']->id, 'match' => $text['entityMatch']->id, 'page' => 0]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->where('rows.0.b.sentences.0.text', 'Строка 1 sentence.')
            ->where('meta.current_page', 1));
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
        ->get(route('reader.works.entities.show', ['work' => $en->work_id, 'entity' => $en->id, 'page' => 2]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->has('rows', 5)
            ->where('rows.0.a.sentences.0.text', 'Line 51.')
            ->where('rows.0.a.sentences.0.id', EntitySentence::query()
                ->where('entity_id', $en->id)
                ->where('order', 51)
                ->value('id'))
            ->where('meta.total', 55)
            ->where('meta.last_page', 2)
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
        ->get(route('reader.works.alignments.show', ['work' => $text['work']->id, 'match' => $text['entityMatch']->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->has('rows', 50)
            // The EN side is the b-side (non-reading) map for a native-EN user.
            ->missing('wordMaps.a.orbit'));

    $this->actingAs($user)
        ->get(route('reader.works.alignments.show', ['work' => $text['work']->id, 'match' => $text['entityMatch']->id, 'page' => 2]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->has('rows', 5)
            ->where('wordMaps.a.orbit.w', $orbit->id));
});

test('reader page passes explanation gating and side props', function () {
    $user = User::factory()->create();
    $entities = createAlignedReaderEntities();

    $this->actingAs($user)
        ->get(route('reader.works.alignments.show', ['work' => $entities['work']->id, 'match' => $entities['entityMatch']->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            // Both sides exist, so both carry the Explanation toggle + robot
            // (explainable is per-side existence, 2026-10-24 — the native
            // language no longer gates it); highlightable keeps the
            // not-native rule.
            ->where('explainable.b', true)
            ->where('explainable.a', true)
            ->where('defaultSide', 'b')
            // No API keys yet: AI explanations stay off.
            ->where('explain.enabled', false)
            ->where('explain.modelKey', null)
            ->where('explain.modelLabel', null)
            // Unset explanation pick "follows" the answer model by definition.
            ->where('explain.followsAnswer', true));
});

test('reader page carries the explanation model when the user can use AI', function () {
    $user = User::factory()->create();
    $entities = createAlignedReaderEntities();

    $provider = AiProvider::factory()->enabled()->create(['key' => 'openrouter', 'name' => 'OpenRouter']);
    $model = AiModel::factory()->enabled()->create(['ai_provider_id' => $provider->id, 'external_id' => 'explain', 'name' => 'Explain Model', 'pricing_prompt' => '0', 'pricing_completion' => '0']);
    UserApiKey::factory()->create(['user_id' => $user->id, 'ai_provider_id' => $provider->id]);
    $user->settings()->updateOrCreate(['user_id' => $user->id], ['ai_model_id' => $model->id]);

    $this->actingAs($user)
        ->get(route('reader.works.alignments.show', ['work' => $entities['work']->id, 'match' => $entities['entityMatch']->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->where('explain.enabled', true)
            ->where('explain.modelKey', $model->id)
            ->where('explain.modelLabel', 'Explain Model (free)')
            // No separate explanation pick: it follows the answer model.
            ->where('explain.followsAnswer', true));
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
        ->get(route('reader.works.entities.show', ['work' => $en->work_id, 'entity' => $en->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->where('defaultSide', null)
            // Explainable is per-side existence (2026-10-24): the one EN
            // side exists — native or not, it carries the toggle — and the
            // missing b-side is never explainable.
            ->where('explainable.a', true)
            ->where('explainable.b', false));
});
