<?php

use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Two works at the edge of every works-list rule: the populated one holds
 * readable, granted and restricted texts plus completed, pending and
 * both-restricted alignments; the empty one has no entities at all (works
 * are a public catalog — ADR 0021 — so it is listed regardless). For the
 * non-admin user: 2 readable entities, 1 readable completed match, and one
 * readable row pair so the chain test can read both halves.
 *
 * @return array{populated: Work, empty: Work, user: User, open: Entity, granted: Entity, match: EntityMatch}
 */
function createWorksIndexFixture(): array
{
    $user = User::factory()->create();

    $populated = createWork(['title' => 'Alpha Work', 'author' => 'Author A', 'description' => 'Populated work']);
    $empty = createWork(['title' => 'Beta Work']);

    $open = createEntity('en', $populated, ['name' => 'Alpha EN', 'is_restricted' => false]);
    $granted = createEntity('ru', $populated, ['name' => 'Beta RU', 'is_restricted' => true]);
    $granted->grantedUsers()->attach($user->id);
    $secret = createEntity('ru', $populated, ['name' => 'Secret RU', 'is_restricted' => true]);

    // Counted for the user: completed, both sides readable.
    $match = createEntityMatch($open, $granted, ['status' => 'completed']);

    // Not counted: pending matches have no meaning-match rows to read; the
    // restricted pair needs grants on both of its sides.
    createEntityMatch($open, createEntity('ru', $populated, ['name' => 'Pending RU']), ['status' => 'pending']);
    createEntityMatch(
        createEntity('en', $populated, ['name' => 'Secret EN', 'is_restricted' => true]),
        $secret,
        ['status' => 'completed'],
    );

    $openSentence = EntitySentence::query()->create([
        'entity_id' => $open->id,
        'content' => 'First EN sentence.',
        'order' => 1,
    ]);
    $grantedSentence = EntitySentence::query()->create([
        'entity_id' => $granted->id,
        'content' => 'Первое RU предложение.',
        'order' => 1,
    ]);
    $meaningMatch = MeaningMatch::query()->create([
        'entity_match_id' => $match->id,
        'order' => 0,
        'similarity' => 1.0,
        'alignment_chunk' => 0,
    ]);
    SentenceMeaningMatch::query()->create([
        'entity_sentence_id' => $openSentence->id,
        'meaning_match_id' => $meaningMatch->id,
        'side' => 'a',
    ]);
    SentenceMeaningMatch::query()->create([
        'entity_sentence_id' => $grantedSentence->id,
        'meaning_match_id' => $meaningMatch->id,
        'side' => 'b',
    ]);

    return [
        'populated' => $populated,
        'empty' => $empty,
        'user' => $user,
        'open' => $open,
        'granted' => $granted,
        'match' => $match,
    ];
}

test('the works list shows every work with readable-scoped counts', function () {
    $fixture = createWorksIndexFixture();

    $this->actingAs($fixture['user'])
        ->get('/reader')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reader/Works')
            ->where('q', '')
            ->has('works', 2)
            // orderBy('title').
            ->where('works.0.title', 'Alpha Work')
            // The restricted entities are invisible without grants, so 3 of
            // the 5 are readable.
            ->where('works.0.entities_count', 3)
            // Only the completed both-sides-readable match counts: the
            // readable-but-pending match has no meaning-match rows to read
            // and the restricted completed pair needs grants on both sides.
            ->where('works.0.alignments_count', 1)
            // Works are a public catalog: the empty work is listed too.
            ->where('works.1.title', 'Beta Work')
            ->where('works.1.entities_count', 0)
            ->where('works.1.alignments_count', 0)
            ->where('meta.total', 2));
});

test('an admin counts every entity and every completed alignment', function () {
    $fixture = createWorksIndexFixture();

    $this->actingAs(User::factory()->admin()->create())
        ->get('/reader')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('works.0.entities_count', 5)
            // Admin bypass reads the restricted pair, so both completed
            // matches count; pending still does not.
            ->where('works.0.alignments_count', 2));
});

test('the works card payload carries the work ref and both counts', function () {
    $fixture = createWorksIndexFixture();

    $this->actingAs($fixture['user'])
        ->get('/reader')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // id, title, author, description, original_language and the two
            // counts — no timestamps, no management fields.
            ->has('works.0', 7)
            ->where('works.0.id', $fixture['populated']->id)
            ->where('works.0.author', 'Author A')
            ->where('works.0.description', 'Populated work')
            ->where('works.0.original_language.code', 'en')
            ->has('works.0.original_language', 2));
});

test('the works list search filters title or author with an escaped needle', function () {
    $fixture = createWorksIndexFixture();

    $this->actingAs($fixture['user'])
        ->get('/reader?q=alpha')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('q', 'alpha')
            ->has('works', 1)
            ->where('works.0.title', 'Alpha Work'));

    $this->actingAs($fixture['user'])
        ->get('/reader?q='.rawurlencode('Author B'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('works', 0));

    createWork(['title' => 'Gamma Work', 'author' => 'Author B']);

    $this->actingAs($fixture['user'])
        ->get('/reader?q='.rawurlencode('Author B'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('works', 1)
            ->where('works.0.title', 'Gamma Work'));

    // A bare % would match everything as a wildcard; escaped, it matches
    // only the literal percent sign.
    createWork(['title' => 'Delta 100%', 'author' => 'Author C']);

    $this->actingAs($fixture['user'])
        ->get('/reader?q=%')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('works', 1)
            ->where('works.0.title', 'Delta 100%'));
});

test('the works list paginates at fifteen per page', function () {
    $fixture = createWorksIndexFixture();

    foreach (range(1, 15) as $i) {
        createWork(['title' => sprintf('Filler Work %02d', $i)]);
    }

    $this->actingAs($fixture['user'])
        ->get('/reader')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('works', 15)
            ->where('meta.current_page', 1)
            ->where('meta.per_page', 15)
            ->where('meta.total', 17)
            ->where('meta.last_page', 2));

    $this->actingAs($fixture['user'])
        ->get('/reader?page=2')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('works', 2)
            ->where('meta.current_page', 2));
});

test('the full reader chain is reachable from the works list', function () {
    $fixture = createWorksIndexFixture();
    $work = $fixture['populated'];

    // The works-first entry.
    $this->actingAs($fixture['user'])
        ->get('/reader')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reader/Works')
            ->has('works', 2));

    // A work card's landing: the sections page.
    $this->get(route('reader.works.show', ['work' => $work->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reader/WorkHome')
            ->where('entities_count', 3)
            ->where('alignments_count', 1));

    // Both branch lists.
    $this->get(route('reader.works.entities.index', ['work' => $work->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reader/WorkEntitiesList')
            ->has('entities', 3));

    $this->get(route('reader.works.alignments.index', ['work' => $work->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reader/WorkAlignmentsList')
            ->has('alignments', 1));

    // Both readers.
    $this->get(route('reader.works.entities.show', ['work' => $work->id, 'entity' => $fixture['open']->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->where('positionKey', 'ent:'.$fixture['open']->id));

    $this->get(route('reader.works.alignments.show', ['work' => $work->id, 'match' => $fixture['match']->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->where('positionKey', 'mm:'.$fixture['match']->id));
});
