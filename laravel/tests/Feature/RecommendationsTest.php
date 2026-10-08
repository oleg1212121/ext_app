<?php

use App\Models\Entity;
use App\Models\User;
use App\Models\UserEntityWordKnowledge;
use App\Models\UserWord;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A recommendation-rankable entity: word list indexed (so the page does not
 * treat it as mid-rebuild) and a completed upload unless overridden.
 */
function recommendationEntity(string $lang, array $attributes = [], ?Work $work = null): Entity
{
    return createEntity($lang, $work, [
        'words_indexed_at' => now(),
        ...$attributes,
    ]);
}

/**
 * A stored word-knowledge snapshot (the page reads the table, never computes).
 */
function recommendationSnapshot(Entity $entity, User $user, ?float $score): UserEntityWordKnowledge
{
    return UserEntityWordKnowledge::query()->create([
        'user_id' => $user->id,
        'entity_id' => $entity->id,
        'score' => $score,
        'computed_at' => now(),
    ]);
}

test('lists works with qualifying texts least-known first, threshold inclusive', function () {
    $user = approvedUser();

    $lowWork = createWork(['title' => 'Brave New World']);
    $low = recommendationEntity('en', ['name' => 'Brave New World EN'], $lowWork);
    $mid = recommendationEntity('en', ['name' => 'Brave New World RU'], $lowWork);
    $highWork = createWork(['title' => 'Animal Farm']);
    $high = recommendationEntity('en', ['name' => 'Animal Farm EN'], $highWork);
    $below = recommendationEntity('en', ['name' => 'Below Threshold'], createWork(['title' => 'Hidden Work']));

    recommendationSnapshot($low, $user, 90.0);
    recommendationSnapshot($mid, $user, 95.5);
    recommendationSnapshot($high, $user, 100.0);
    recommendationSnapshot($below, $user, 89.99);

    $this->actingAs($user)
        ->get('/recommendations')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Recommendations/Index')
            ->where('knowledge', 90)
            ->where('lang', 'en')
            ->where('meta.total', 2)
            ->where('works.0.id', $lowWork->id)
            ->where('works.0.min_score', 90)
            ->where('works.0.entities.0.id', $low->id)
            ->where('works.0.entities.0.word_knowledge', 90)
            ->where('works.0.entities.1.id', $mid->id)
            ->where('works.0.entities.1.word_knowledge', 95.5)
            ->where('works.1.id', $highWork->id)
            ->where('works.1.entities.0.id', $high->id));
});

test('defaults to en and honours an explicit language', function () {
    $user = approvedUser();
    $en = recommendationEntity('en');
    $ru = recommendationEntity('ru', [], createWork(['title' => 'Russian Work']));
    recommendationSnapshot($en, $user, 95.0);
    recommendationSnapshot($ru, $user, 95.0);

    $this->actingAs($user)->get('/recommendations')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('meta.total', 1)
            ->where('works.0.entities.0.id', $en->id));

    $this->actingAs($user)->get('/recommendations?lang=ru')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('lang', 'ru')
            ->where('meta.total', 1)
            ->where('works.0.entities.0.id', $ru->id));
});

test('an unknown language code is a 404', function () {
    $this->actingAs(approvedUser())
        ->get('/recommendations?lang=de')
        ->assertNotFound();
});

test('search matches work fields and qualifying text names', function () {
    $user = approvedUser();

    $byTitle = createWork(['title' => 'Moby Dick', 'author' => 'Herman Melville']);
    $byTitleEntity = recommendationEntity('en', ['name' => 'Unrelated Name'], $byTitle);
    recommendationSnapshot($byTitleEntity, $user, 91.0);

    $byEntity = createWork(['title' => 'Something Else']);
    $byEntityEntity = recommendationEntity('en', ['name' => 'The Whale Chapter'], $byEntity);
    recommendationSnapshot($byEntityEntity, $user, 92.0);

    // Matches q, but has no qualifying text, so it must not surface.
    $belowWork = createWork(['title' => 'Moby Dick Sequel']);
    $belowEntity = recommendationEntity('en', ['name' => 'Moby Dick Junior'], $belowWork);
    recommendationSnapshot($belowEntity, $user, 10.0);

    // 'moby' matches the work title only; the below-threshold work that
    // also matches (its entity is named 'Moby Dick Junior') must not surface.
    $this->actingAs($user)->get('/recommendations?q=moby')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('meta.total', 1)
            ->where('works.0.entities.0.id', $byTitleEntity->id));

    $this->actingAs($user)->get('/recommendations?q=whale')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('meta.total', 1)
            ->where('works.0.entities.0.id', $byEntityEntity->id));

    $this->actingAs($user)->get('/recommendations?q=junior')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('meta.total', 0));
});

test('only the viewer\'s own snapshots count', function () {
    $user = approvedUser();
    $mine = recommendationEntity('en', [], createWork(['title' => 'My Work']));
    $theirs = recommendationEntity('en', [], createWork(['title' => 'Their Work']));
    recommendationSnapshot($mine, $user, 95.0);
    recommendationSnapshot($theirs, approvedUser(), 95.0);

    $this->actingAs($user)->get('/recommendations')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('meta.total', 1)
            ->where('works.0.entities.0.id', $mine->id));
});

test('unrankable texts are excluded silently', function () {
    $user = approvedUser();

    $nullScore = recommendationEntity('en');
    recommendationSnapshot($nullScore, $user, null);

    // A sentence edited after the last word-list build — the "calculating" state.
    $midRebuild = recommendationEntity('en', ['words_indexed_at' => now()->subHour()]);
    recommendationSnapshot($midRebuild, $user, 95.0);
    $midRebuild->sentences()->create(['sentence_type_id' => null, 'content' => 'Fresh sentence.', 'order' => 1]);

    $notIndexed = recommendationEntity('en', ['words_indexed_at' => null]);
    recommendationSnapshot($notIndexed, $user, 95.0);

    $processing = recommendationEntity('en', ['status' => 'processing']);
    recommendationSnapshot($processing, $user, 95.0);

    $restricted = recommendationEntity('en', ['is_restricted' => true]);
    recommendationSnapshot($restricted, $user, 95.0);

    $this->actingAs($user)->get('/recommendations')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('meta.total', 0));
});

test('a granted restricted text is listed', function () {
    $user = approvedUser();
    $granted = recommendationEntity('en', ['is_restricted' => true]);
    recommendationSnapshot($granted, $user, 95.0);
    $granted->grantedUsers()->attach($user->id);

    $this->actingAs($user)->get('/recommendations')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('meta.total', 1)
            ->where('works.0.entities.0.id', $granted->id));
});

test('pagination counts works, 15 per page', function () {
    $user = approvedUser();

    for ($i = 1; $i <= 16; $i++) {
        $entity = recommendationEntity('en', ['name' => "Text {$i}"], createWork(['title' => "Work {$i}"]));
        recommendationSnapshot($entity, $user, 90 + $i / 100);
    }

    $this->actingAs($user)->get('/recommendations')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('meta.total', 16)
            ->where('meta.last_page', 2)
            ->where('meta.per_page', 15)
            ->has('works', 15));

    $this->actingAs($user)->get('/recommendations?page=2')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('works', 1));
});

test('the knowledge parameter is clamped and falls back to 90 when invalid', function () {
    $user = approvedUser();
    $entity = recommendationEntity('en');
    recommendationSnapshot($entity, $user, 80.0);

    $this->actingAs($user)->get('/recommendations?knowledge=75')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('knowledge', 75)
            ->where('meta.total', 1));

    $this->actingAs($user)->get('/recommendations?knowledge=abc')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('knowledge', 90)
            ->where('meta.total', 0));

    $this->actingAs($user)->get('/recommendations?knowledge=150')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('knowledge', 100)
            ->where('meta.total', 0));

    $this->actingAs($user)->get('/recommendations?knowledge=-10')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('knowledge', 0)
            ->where('meta.total', 1));
});

test('the page carries the word-test hint flag and the snapshot presence', function () {
    $user = approvedUser();
    $entity = recommendationEntity('en');
    recommendationSnapshot($entity, $user, 92.0);

    $this->actingAs($user)->get('/recommendations')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('has_no_familiarity', true)
            ->where('has_any_scores', true));

    UserWord::query()->create([
        'user_id' => $user->id,
        'word_id' => createWord('en', 'river')->id,
        'familiarity' => 40,
    ]);

    $this->actingAs($user)->get('/recommendations')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('has_no_familiarity', false));
});

test('a viewer without snapshots gets the empty no-scores state', function () {
    $user = approvedUser();

    $this->actingAs($user)->get('/recommendations')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('meta.total', 0)
            ->where('has_any_scores', false));
});

test('guests are redirected to login', function () {
    $this->get('/recommendations')->assertRedirect(route('login'));
});
