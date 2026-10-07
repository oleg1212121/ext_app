<?php

use App\Classes\EntityWordKnowledgeService;
use App\Models\Entity;
use App\Models\UserEntityWordKnowledge;
use App\Models\UserWord;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * An entity whose word list is indexed and linked: entries are
 * [l_word, count, word|null] triples, with an optional familiarity map of
 * l_word => familiarity written for the canonical test user.
 */
function knowledgeEntity(array $entries, array $familiarity = []): array
{
    $entity = createEntity('en', attributes: ['words_indexed_at' => now()]);
    $user = approvedUser();

    foreach ($entries as [$lWord, $count, $word]) {
        $entity->entityWords()->create([
            'word_id' => $word?->id,
            'l_word' => $lWord,
            'token' => $lWord,
            'count' => $count,
        ]);

        if (isset($familiarity[$lWord])) {
            UserWord::query()->create([
                'user_id' => $user->id,
                'word_id' => $word->id,
                'familiarity' => $familiarity[$lWord],
            ]);
        }
    }

    return [$entity, $user];
}

test('score is occurrence-weighted with the capped-linear familiarity mapping', function () {
    [$entity, $user] = knowledgeEntity(
        [
            ['forest', 500, createWord('en', 'forest')],
            ['river', 20, createWord('en', 'river')],
            ['grove', 3, createWord('en', 'grove')],
        ],
        ['forest' => 100, 'river' => 50],
    );

    // earned = 500*60 + 20*50 = 31000 of 60*523 = 31380.
    expect(app(EntityWordKnowledgeService::class)->score($entity, $user->id))
        ->toEqualWithDelta(98.79, 0.01);
});

test('familiarity above the cap counts as fully known and no row counts as zero', function () {
    [$entity, $user] = knowledgeEntity(
        [
            ['alpha', 10, createWord('en', 'alpha')],
            ['beta', 10, createWord('en', 'beta')],
            ['gamma', 10, createWord('en', 'gamma')],
        ],
        ['alpha' => 60, 'beta' => 100],
    );

    // 60 and 100 both contribute full weight; gamma (no row) contributes 0
    // of 60 — a third of the occurrences, whatever their familiarity.
    expect(app(EntityWordKnowledgeService::class)->score($entity, $user->id))
        ->toEqualWithDelta(200 / 3, 0.01);
});

test('unlinked tokens are excluded from both numerator and denominator', function () {
    [$entity, $user] = knowledgeEntity([
        ['known', 1, createWord('en', 'known')],
        ['orphan', 1000000, null],
    ], ['known' => 100]);

    expect(app(EntityWordKnowledgeService::class)->score($entity, $user->id))->toBe(100.0);
});

test('score is null when the entity has no linked words', function () {
    [$entity, $user] = knowledgeEntity([['orphan', 5, null]]);

    expect(app(EntityWordKnowledgeService::class)->score($entity, $user->id))->toBeNull();
});

test('ensure stores a snapshot on first view and reuses it while fresh', function () {
    [$entity, $user] = knowledgeEntity([['forest', 4, createWord('en', 'forest')]], ['forest' => 30]);

    $service = app(EntityWordKnowledgeService::class);

    $pair = $service->ensure($entity, $user);

    // Familiarity 30 of the 60 cap → 50%.
    expect($pair->score)->toBe(50.0)
        ->and(UserEntityWordKnowledge::query()->count())->toBe(1);

    // Familiarity changes after the snapshot are invisible until it goes
    // stale — the stored row is returned as-is.
    UserWord::query()->where('user_id', $user->id)->update(['familiarity' => 90]);

    expect($service->ensure($entity, $user)->score)->toBe(50.0);

    // A rebuilt word list invalidates the snapshot immediately.
    $entity->forceFill(['words_indexed_at' => now()->addMinute()])->save();

    expect($service->ensure($entity, $user)->score)->toBe(100.0);
});

test('ensure refuses while the word list is stale and returns nothing stored', function () {
    [$entity, $user] = knowledgeEntity([['forest', 4, createWord('en', 'forest')]]);
    $entity->forceFill(['words_indexed_at' => null])->save();

    expect(app(EntityWordKnowledgeService::class)->ensure($entity, $user))->toBeNull()
        ->and(UserEntityWordKnowledge::query()->count())->toBe(0);
});

test('ensure stores a null score for an entity without linked words', function () {
    [$entity, $user] = knowledgeEntity([['orphan', 5, null]]);

    $pair = app(EntityWordKnowledgeService::class)->ensure($entity, $user);

    expect($pair)->not->toBeNull()
        ->and($pair->score)->toBeNull();
});

test('the show page carries the snapshot and the word-test hint', function () {
    [$entity, $user] = knowledgeEntity(
        [['forest', 4, createWord('en', 'forest')]],
        ['forest' => 60],
    );

    $this->actingAs($user)
        ->get("/works/{$entity->work_id}/entities/{$entity->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('word_knowledge.score', 100)
            ->where('has_no_familiarity', false));

    // A viewer with no familiarity data at all scores 0% and is pointed at
    // the word test.
    $blank = approvedUser();

    $this->actingAs($blank)
        ->get("/works/{$entity->work_id}/entities/{$entity->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('word_knowledge.score', 0)
            ->where('has_no_familiarity', true));

    expect(UserEntityWordKnowledge::query()->where('user_id', $blank->id)->count())->toBe(1);
});

test('the show page shows the building state while the word list is not indexed', function () {
    $entity = createEntity('en', attributes: ['words_indexed_at' => null]);

    $this->actingAs(approvedUser())
        ->get("/works/{$entity->work_id}/entities/{$entity->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('word_knowledge', null));
});

test('the refresh sweep recomputes aged snapshots and skips fresh ones', function () {
    [$entity, $user] = knowledgeEntity([['forest', 4, createWord('en', 'forest')]], ['forest' => 30]);
    $service = app(EntityWordKnowledgeService::class);

    $pair = $service->ensure($entity, $user);
    $pair->forceFill(['computed_at' => now()->subDays(4)])->save();
    UserWord::query()->where('user_id', $user->id)->update(['familiarity' => 90]);

    [$otherEntity, $otherUser] = knowledgeEntity([['river', 2, createWord('en', 'river')]], ['river' => 50]);
    $otherPair = $service->ensure($otherEntity, $otherUser);
    // Fresh: 2 days old (inside the 3-day cap) and newer than the entity's
    // last index build. The expected moment is captured at write time — a
    // fresh now() at assertion time drifts across the second boundary.
    $freshAt = now()->subDays(2);
    $otherEntity->forceFill(['words_indexed_at' => now()->subDays(3)])->save();
    $otherPair->forceFill(['computed_at' => $freshAt])->save();

    $this->artisan('entities:refresh-word-knowledge')->assertSuccessful();

    $pair->refresh();
    $otherPair->refresh();

    expect($pair->score)->toBe(100.0)
        ->and($pair->computed_at->timestamp)->toBeGreaterThan(now()->subMinute()->timestamp)
        ->and($otherPair->computed_at->format('Y-m-d H:i:s'))->toBe($freshAt->format('Y-m-d H:i:s'))
        ->and($otherPair->score)->toBe(83.33);
});

test('the refresh sweep recomputes snapshots overtaken by a word-list rebuild', function () {
    [$entity, $user] = knowledgeEntity([['forest', 4, createWord('en', 'forest')]], ['forest' => 30]);
    $service = app(EntityWordKnowledgeService::class);
    $pair = $service->ensure($entity, $user);

    $entity->forceFill(['words_indexed_at' => now()->addMinutes(5)])->save();
    UserWord::query()->where('user_id', $user->id)->update(['familiarity' => 90]);

    $this->artisan('entities:refresh-word-knowledge')->assertSuccessful();

    expect($pair->refresh()->score)->toBe(100.0);
});

test('the refresh sweep respects the limit and skips entities mid-rebuild', function () {
    [$first, $firstUser] = knowledgeEntity([['alpha', 1, createWord('en', 'alpha')]], ['alpha' => 30]);
    [$second, $secondUser] = knowledgeEntity([['beta', 1, createWord('en', 'beta')]], ['beta' => 30]);
    $service = app(EntityWordKnowledgeService::class);
    $firstPair = $service->ensure($first, $firstUser);
    $secondPair = $service->ensure($second, $secondUser);

    // Age both past the cap; the second entity's word list is mid-rebuild,
    // so its pair must be left alone even when addressed by the sweep.
    $staleAt = now()->subDays(4);
    $firstPair->forceFill(['computed_at' => $staleAt])->save();
    $secondPair->forceFill(['computed_at' => $staleAt])->save();
    $second->forceFill(['words_indexed_at' => now()->subDays(5)])->save();
    $second->sentences()->create(['sentence_type_id' => null, 'content' => 'New sentence.', 'order' => 1024]);

    $this->artisan('entities:refresh-word-knowledge', ['--limit' => 1])->assertSuccessful();

    expect($firstPair->refresh()->computed_at->timestamp)->toBeGreaterThan(now()->subMinute()->timestamp)
        ->and($secondPair->refresh()->computed_at->timestamp)->toBe($staleAt->timestamp);
});
