<?php

use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\Language;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A work whose user may read an EN pair side and a granted RU one; a
 * restricted RU entity nobody granted completes the picture. The same
 * shape the browse tests build on.
 *
 * @return array{work: Work, user: User}
 */
function createAlignmentListWork(): array
{
    $work = createWork(['title' => 'Alignment Work', 'author' => 'Author']);
    $user = User::factory()->create();

    createEntity('en', $work, ['name' => 'Alpha EN', 'is_restricted' => false]);
    $ru = createEntity('ru', $work, ['name' => 'Beta RU', 'is_restricted' => true]);
    $ru->grantedUsers()->attach($user->id);
    createEntity('ru', $work, ['name' => 'Secret RU', 'is_restricted' => true]);

    return ['work' => $work, 'user' => $user];
}

/**
 * A same-work pair of entities named after the tag, for match fixtures.
 *
 * @return array{0: Entity, 1: Entity}
 */
function createNamedPair(Work $work, string $tag, bool $restricted = false): array
{
    return [
        createEntity('en', $work, ['name' => "{$tag} EN", 'is_restricted' => $restricted]),
        createEntity('ru', $work, ['name' => "{$tag} RU", 'is_restricted' => $restricted]),
    ];
}

test('guests are redirected from the work alignments list', function () {
    $fixture = createAlignmentListWork();

    $this->get(route('reader.works.alignments.index', ['work' => $fixture['work']->id]))
        ->assertRedirect(route('login'));
});

test('the work alignments list shows only readable matches at completed or stale', function () {
    $fixture = createAlignmentListWork();
    $work = $fixture['work'];

    // Listed: completed and stale matches the user can read both sides of.
    createEntityMatch(...createNamedPair($work, 'Done'), attributes: ['status' => 'completed']);
    createEntityMatch(...createNamedPair($work, 'Stale'), attributes: ['status' => 'stale']);

    // Not listed: pending and failed matches have no meaning-match rows to
    // read — the same rule the Bilinguals simulator picker applies.
    createEntityMatch(...createNamedPair($work, 'Pending'), attributes: ['status' => 'pending']);
    createEntityMatch(...createNamedPair($work, 'Failed'), attributes: ['status' => 'failed']);

    // Not listed for this user: a restricted pair needs grants on both
    // sides. A completed match of another work never lists under this
    // work's URL either — the work comes via the A-side entity.
    createEntityMatch(...createNamedPair($work, 'Restricted', restricted: true), attributes: ['status' => 'completed']);
    $otherWork = createWork(['title' => 'Other Work']);
    createEntityMatch(
        createEntity('en', $otherWork, ['name' => 'Other EN']),
        createEntity('ru', $otherWork, ['name' => 'Other RU']),
        ['status' => 'completed'],
    );

    $this->actingAs($fixture['user'])
        ->get(route('reader.works.alignments.index', ['work' => $work->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reader/WorkAlignmentsList')
            ->where('work.id', $work->id)
            ->where('work.title', 'Alignment Work')
            ->has('alignments', 2)
            ->where('alignments.0.status', fn ($status) => in_array($status, ['completed', 'stale'], true))
            ->where('alignments.1.status', fn ($status) => in_array($status, ['completed', 'stale'], true))
            ->where('alignments.0.a_language_code', 'en')
            ->where('alignments.0.b_language_code', 'ru')
            ->where('alignments.0.a_entity_name', fn ($name) => in_array($name, ['Done EN', 'Stale EN'], true))
            ->where('alignments.0.b_entity_name', fn ($name) => in_array($name, ['Done RU', 'Stale RU'], true)));

    // Admin bypass reads everything, so the restricted pair lists too.
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('reader.works.alignments.index', ['work' => $work->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('alignments', 3));
});

test('the work alignments list search matches either side entity name', function () {
    $fixture = createAlignmentListWork();
    $work = $fixture['work'];

    $match = createEntityMatch(
        createEntity('en', $work, ['name' => 'Garnett Translation']),
        createEntity('ru', $work, ['name' => 'Русский перевод']),
        ['status' => 'completed'],
    );

    $this->actingAs($fixture['user'])
        ->get(route('reader.works.alignments.index', ['work' => $work->id, 'q' => 'garnett']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('q', 'garnett')
            ->has('alignments', 1)
            ->where('alignments.0.id', $match->id));

    // The b-side's name hits too: the search crosses the pair.
    $this->actingAs($fixture['user'])
        ->get(route('reader.works.alignments.index', ['work' => $work->id, 'q' => 'перевод']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('alignments', 1));

    $this->actingAs($fixture['user'])
        ->get(route('reader.works.alignments.index', ['work' => $work->id, 'q' => 'nothing']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('alignments', 0));
});

test('the work alignments list language filter matches either side language', function () {
    createLanguages(['en', 'ru', 'be']);
    $work = createWork(['title' => 'Trilingual']);
    $user = User::factory()->create();

    $en = createEntity('en', $work, ['name' => 'EN original']);
    $ru = createEntity('ru', $work, ['name' => 'RU translation']);
    $be = createEntity('be', $work, ['name' => 'BE translation']);

    createEntityMatch($en, $ru, ['status' => 'completed']);
    createEntityMatch($be, $ru, ['status' => 'completed']);
    createEntityMatch($en, $be, ['status' => 'completed']);

    // Picking ru shows the ru pairs — whatever side ru sits on.
    $this->actingAs($user)
        ->get(route('reader.works.alignments.index', ['work' => $work->id, 'lang' => 'ru']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('lang', 'ru')
            ->has('alignments', 2));

    $this->actingAs($user)
        ->get(route('reader.works.alignments.index', ['work' => $work->id, 'lang' => 'be']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('alignments', 2));

    // No ?lang is the All-languages default.
    $this->actingAs($user)
        ->get(route('reader.works.alignments.index', ['work' => $work->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('lang', '')
            ->has('alignments', 3));
});

test('the work alignments list 404s for an unknown or disabled language code', function () {
    $fixture = createAlignmentListWork();
    createLanguages(['en', 'ru', 'de']);
    Language::query()->where('code', 'de')->update(['is_enabled' => false]);

    $this->actingAs($fixture['user'])
        ->get(route('reader.works.alignments.index', ['work' => $fixture['work']->id, 'lang' => 'xx']))
        ->assertNotFound();

    $this->actingAs($fixture['user'])
        ->get(route('reader.works.alignments.index', ['work' => $fixture['work']->id, 'lang' => 'de']))
        ->assertNotFound();
});

test('the work alignments list 404s for an unknown work', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('reader.works.alignments.index', ['work' => 999999]))
        ->assertNotFound();
});

test('the work alignments list paginates at fifteen per page, newest first', function () {
    $fixture = createAlignmentListWork();
    $work = $fixture['work'];

    foreach (range(1, 16) as $i) {
        createEntityMatch(...createNamedPair($work, "Pair {$i}"), attributes: ['status' => 'completed']);
    }

    // The freshest match rides first: latest() orders the list.
    $fresh = createEntityMatch(...createNamedPair($work, 'Fresh'), attributes: ['status' => 'completed']);
    EntityMatch::query()->whereKey($fresh->id)->update(['created_at' => now()->addDay()]);

    $this->actingAs($fixture['user'])
        ->get(route('reader.works.alignments.index', ['work' => $work->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('alignments', 15)
            ->where('alignments.0.id', $fresh->id)
            ->where('meta.current_page', 1)
            ->where('meta.per_page', 15)
            ->where('meta.total', 17)
            ->where('meta.last_page', 2));

    $this->actingAs($fixture['user'])
        ->get(route('reader.works.alignments.index', ['work' => $work->id, 'page' => 2]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('alignments', 2)
            ->where('meta.current_page', 2));
});

test('the sections page alignments count agrees with the list total', function () {
    $fixture = createAlignmentListWork();
    $work = $fixture['work'];

    createEntityMatch(...createNamedPair($work, 'Done'), attributes: ['status' => 'completed']);
    // Not counted anywhere: pending has no meaning-match rows to read.
    createEntityMatch(...createNamedPair($work, 'Pending'), attributes: ['status' => 'pending']);

    $this->actingAs($fixture['user'])
        ->get(route('reader.works.show', ['work' => $work->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('alignments_count', 1));

    $this->actingAs($fixture['user'])
        ->get(route('reader.works.alignments.index', ['work' => $work->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('meta.total', 1));
});

test('the work alignments list ships the match payload without reader_target', function () {
    $fixture = createAlignmentListWork();
    $work = $fixture['work'];

    [$en, $ru] = createNamedPair($work, 'Payload');
    $match = createEntityMatch($en, $ru, ['status' => 'completed']);

    $this->actingAs($fixture['user'])
        ->get(route('reader.works.alignments.index', ['work' => $work->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('q', '')
            ->where('lang', '')
            ->has('languages', 2)
            // The exact matchPayload shape: the Library's reader_target
            // extension is deliberately absent — the reader URL is
            // match-anchored and needs no side precomputation.
            ->has('alignments.0', 15)
            ->where('alignments.0.id', $match->id)
            ->where('alignments.0.work_id', $work->id)
            ->where('alignments.0.a_entity_name', 'Payload EN')
            ->where('alignments.0.b_entity_name', 'Payload RU')
            ->where('alignments.0.status', 'completed'));
});
