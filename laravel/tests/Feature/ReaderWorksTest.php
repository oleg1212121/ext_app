<?php

use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\User;
use App\Models\Work;
use Database\Seeders\UiStringSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A completed aligned pair inside one work for the work-scoped readers:
 * two sentences per side across two meaning matches. The EN entity is
 * created first, so the canonical A-side is EN and the B-side RU.
 *
 * @return array{work: Work, en: Entity, ru: Entity, entityMatch: EntityMatch}
 */
function createWorkReadingPair(): array
{
    $work = createWork();

    $en = createEntity('en', $work, ['name' => 'Work EN Entity']);
    $ru = createEntity('ru', $work, ['name' => 'Work RU Entity']);

    $enSentences = [];
    $ruSentences = [];
    foreach (['First' => 'Первое', 'Second' => 'Второе'] as $enText => $ruText) {
        $enSentences[] = EntitySentence::query()->create([
            'entity_id' => $en->id,
            'content' => "{$enText} EN sentence.",
            'order' => count($enSentences) + 1,
        ]);
        $ruSentences[] = EntitySentence::query()->create([
            'entity_id' => $ru->id,
            'content' => "{$ruText} RU предложение.",
            'order' => count($ruSentences) + 1,
        ]);
    }

    $entityMatch = createEntityMatch($en, $ru, ['status' => 'completed', 'linked_count' => 2]);

    foreach ($enSentences as $i => $enSentence) {
        $meaningMatch = MeaningMatch::query()->create([
            'entity_match_id' => $entityMatch->id,
            'order' => $i,
            'similarity' => 1.0,
            'alignment_chunk' => 0,
        ]);

        SentenceMeaningMatch::query()->create([
            'entity_sentence_id' => $enSentence->id,
            'meaning_match_id' => $meaningMatch->id,
            'side' => 'a',
        ]);
        SentenceMeaningMatch::query()->create([
            'entity_sentence_id' => $ruSentences[$i]->id,
            'meaning_match_id' => $meaningMatch->id,
            'side' => 'b',
        ]);
    }

    return ['work' => $work, 'en' => $en, 'ru' => $ru, 'entityMatch' => $entityMatch];
}

test('guests are redirected from the work-scoped reader routes', function () {
    $pair = createWorkReadingPair();

    $this->get(route('reader.works.entities.show', ['work' => $pair['work']->id, 'entity' => $pair['en']->id]))
        ->assertRedirect(route('login'));

    $this->get(route('reader.works.alignments.show', ['work' => $pair['work']->id, 'match' => $pair['entityMatch']->id]))
        ->assertRedirect(route('login'));
});

test('the work-scoped entity reader is always monolingual, even for an entity with a readable match', function () {
    $user = User::factory()->create();
    $pair = createWorkReadingPair();

    $this->actingAs($user)
        ->get(route('reader.works.entities.show', ['work' => $pair['work']->id, 'entity' => $pair['en']->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->where('work.id', $pair['work']->id)
            ->where('work.title', 'Test Work')
            ->where('entity.id', $pair['en']->id)
            ->where('entity.name', 'Work EN Entity')
            // The split's whole point: the entity reader never goes
            // bilingual — no reading side, no translation column — even
            // though the entity sits in a fully readable match.
            ->where('defaultSide', null)
            ->where('langs.a', 'en')
            ->where('langs.b', null)
            ->has('rows', 2)
            ->where('rows.0.a.sentences.0.text', 'First EN sentence.')
            ->where('rows.0.b', null)
            ->where('rows.0.key', 'es:'.EntitySentence::query()
                ->where('entity_id', $pair['en']->id)
                ->where('order', 1)
                ->value('id'))
            // The single-language position key shape is unchanged.
            ->where('positionKey', 'ent:'.$pair['en']->id));
});

test('the entity reader 404s when the entity belongs to another work', function () {
    $user = User::factory()->create();
    $pair = createWorkReadingPair();
    $otherWork = createWork(['title' => 'Other Work']);

    $this->actingAs($user)
        ->get(route('reader.works.entities.show', ['work' => $otherWork->id, 'entity' => $pair['en']->id]))
        ->assertNotFound();
});

test('the entity reader 404s for a missing entity', function () {
    $user = User::factory()->create();
    $pair = createWorkReadingPair();

    $this->actingAs($user)
        ->get(route('reader.works.entities.show', ['work' => $pair['work']->id, 'entity' => 999999]))
        ->assertNotFound();
});

test('the entity reader 403s for a restricted entity until the user has a grant', function () {
    $user = User::factory()->create();
    $pair = createWorkReadingPair();
    $pair['en']->update(['is_restricted' => true]);

    $this->actingAs($user)
        ->get(route('reader.works.entities.show', ['work' => $pair['work']->id, 'entity' => $pair['en']->id]))
        ->assertForbidden();

    $pair['en']->grantedUsers()->attach($user->id);

    $this->actingAs($user)
        ->get(route('reader.works.entities.show', ['work' => $pair['work']->id, 'entity' => $pair['en']->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->where('defaultSide', null));
});

test('the entity reader paginates sentences and clamps an out-of-range page', function () {
    $user = User::factory()->create();
    $pair = createWorkReadingPair();

    // 55 more sentences beyond the fixture's first two: 57 rows over two
    // pages. Pagination is a scale boundary, so going big here is allowed.
    foreach (range(1, 55) as $i) {
        EntitySentence::query()->create([
            'entity_id' => $pair['en']->id,
            'content' => "Line {$i}.",
            'order' => 100 + $i,
        ]);
    }

    $this->actingAs($user)
        ->get(route('reader.works.entities.show', ['work' => $pair['work']->id, 'entity' => $pair['en']->id, 'page' => 99]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->has('rows', 7)
            ->where('rows.0.a.sentences.0.text', 'Line 49.')
            ->where('meta.current_page', 2)
            ->where('meta.per_page', 50)
            ->where('meta.total', 57)
            ->where('meta.last_page', 2)
            ->where('positionKey', 'ent:'.$pair['en']->id));
});

test('the entity reader links the work alignments list when a readable completed match exists', function () {
    $user = User::factory()->create();
    $pair = createWorkReadingPair();
    $this->seed(UiStringSeeder::class);

    $this->actingAs($user)
        ->get(route('reader.works.entities.show', ['work' => $pair['work']->id, 'entity' => $pair['en']->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            // One entity sits in many matches, so the link goes to the
            // work's alignments list, not to one match.
            ->has('crossLinks', 1)
            ->where('crossLinks.0.href', '/reader/works/'.$pair['work']->id.'/alignments')
            ->where('crossLinks.0.label', 'Alignments'));
});

test('the entity reader keeps the alignments link for a stale match', function () {
    $user = User::factory()->create();
    $pair = createWorkReadingPair();
    $this->seed(UiStringSeeder::class);
    EntityMatch::query()->whereKey($pair['entityMatch']->id)->update(['status' => 'stale']);

    $this->actingAs($user)
        ->get(route('reader.works.entities.show', ['work' => $pair['work']->id, 'entity' => $pair['en']->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->has('crossLinks', 1)
            ->where('crossLinks.0.href', '/reader/works/'.$pair['work']->id.'/alignments'));
});

test('the entity reader hides the alignments link for pending or failed matches', function () {
    $user = User::factory()->create();
    $pair = createWorkReadingPair();
    $this->seed(UiStringSeeder::class);

    EntityMatch::query()->whereKey($pair['entityMatch']->id)->update(['status' => 'pending']);

    $this->actingAs($user)
        ->get(route('reader.works.entities.show', ['work' => $pair['work']->id, 'entity' => $pair['en']->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->has('crossLinks', 0));

    EntityMatch::query()->whereKey($pair['entityMatch']->id)->update(['status' => 'failed']);

    $this->actingAs($user)
        ->get(route('reader.works.entities.show', ['work' => $pair['work']->id, 'entity' => $pair['en']->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->has('crossLinks', 0));
});

test('the entity reader hides the alignments link when the matches are unreadable to the user', function () {
    $user = User::factory()->create();
    $pair = createWorkReadingPair();
    $this->seed(UiStringSeeder::class);
    $pair['ru']->update(['is_restricted' => true]);

    // A completed match is only link-worthy when the user may read both
    // sides — a restricted counterpart hides the link.
    $this->actingAs($user)
        ->get(route('reader.works.entities.show', ['work' => $pair['work']->id, 'entity' => $pair['en']->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->has('crossLinks', 0));

    // Admin bypass reads everything, so the link is back.
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('reader.works.entities.show', ['work' => $pair['work']->id, 'entity' => $pair['en']->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->has('crossLinks', 1));
});

test('the work-scoped alignment reader renders bilingual rows with the side rule as defaultSide', function () {
    $user = User::factory()->create();
    $pair = createWorkReadingPair();

    $firstMeaningMatchId = MeaningMatch::query()
        ->where('entity_match_id', $pair['entityMatch']->id)
        ->orderBy('order')
        ->value('id');

    $this->actingAs($user)
        ->get(route('reader.works.alignments.show', ['work' => $pair['work']->id, 'match' => $pair['entityMatch']->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->where('work.id', $pair['work']->id)
            ->where('work.title', 'Test Work')
            // Factory users are native EN: the EN side becomes their
            // translation, so the RU learning side reads first.
            ->where('defaultSide', 'b')
            ->where('langs.a', 'en')
            ->where('langs.b', 'ru')
            ->where('entity.id', $pair['ru']->id)
            ->where('entity.name', 'Work RU Entity')
            ->has('rows', 2)
            ->where('rows.0.key', 'mm:'.$firstMeaningMatchId)
            ->where('rows.0.a.sentences.0.text', 'First EN sentence.')
            ->where('rows.0.b.sentences.0.text', 'Первое RU предложение.')
            // The shared match position key is unchanged: saved reading
            // positions survive the URL split.
            ->where('positionKey', 'mm:'.$pair['entityMatch']->id)
            ->where('meta.current_page', 1)
            ->where('meta.total', 2)
            ->where('explainable.a', true)
            ->where('explainable.b', true));
});

test('the alignment reader applies the native-side rule per user', function () {
    $user = nativeRuUser();
    $pair = createWorkReadingPair();

    // A native-RU user reads the EN side against the RU translation.
    $this->actingAs($user)
        ->get(route('reader.works.alignments.show', ['work' => $pair['work']->id, 'match' => $pair['entityMatch']->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->where('defaultSide', 'a')
            ->where('entity.id', $pair['en']->id)
            ->where('entity.name', 'Work EN Entity'));
});

test('the alignment reader 404s for a match whose a-side belongs to another work', function () {
    $user = User::factory()->create();
    $pair = createWorkReadingPair();
    $otherWork = createWork(['title' => 'Other Work']);

    $this->actingAs($user)
        ->get(route('reader.works.alignments.show', ['work' => $otherWork->id, 'match' => $pair['entityMatch']->id]))
        ->assertNotFound();
});

test('the alignment reader 403s when one side is restricted without a grant', function () {
    $user = User::factory()->create();
    $pair = createWorkReadingPair();
    // Only the b-side restricted: being able to read the URL's work is not
    // enough to read the pair — the old auto-detect fallback to monolingual
    // rows is deliberately gone here (the entity reader is that fallback).
    $pair['ru']->update(['is_restricted' => true]);

    $this->actingAs($user)
        ->get(route('reader.works.alignments.show', ['work' => $pair['work']->id, 'match' => $pair['entityMatch']->id]))
        ->assertForbidden();

    $pair['ru']->grantedUsers()->attach($user->id);

    $this->actingAs($user)
        ->get(route('reader.works.alignments.show', ['work' => $pair['work']->id, 'match' => $pair['entityMatch']->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->where('defaultSide', 'b'));
});

test('the alignment reader cross-links each side entity reader in the header', function () {
    $user = User::factory()->create();
    $pair = createWorkReadingPair();

    $this->actingAs($user)
        ->get(route('reader.works.alignments.show', ['work' => $pair['work']->id, 'match' => $pair['entityMatch']->id]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->has('crossLinks', 2)
            ->where('crossLinks.0.href', '/reader/works/'.$pair['work']->id.'/entities/'.$pair['en']->id)
            ->where('crossLinks.0.label', 'Work EN Entity')
            ->where('crossLinks.1.href', '/reader/works/'.$pair['work']->id.'/entities/'.$pair['ru']->id)
            ->where('crossLinks.1.label', 'Work RU Entity'));
});
