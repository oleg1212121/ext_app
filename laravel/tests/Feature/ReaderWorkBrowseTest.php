<?php

use App\Models\Entity;
use App\Models\EntitySentence;
use App\Models\Language;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A work holding readable and restricted texts across two languages, and
 * the user who may read two of the three. The EN entity is named to sort
 * first so list ordering is assertable; it carries two sentences so the
 * card's sentences_count is assertable too.
 *
 * @return array{work: Work, open: Entity, granted: Entity, secret: Entity, user: User}
 */
function createBrowseWork(): array
{
    $work = createWork(['title' => 'Browse Work', 'author' => 'Author', 'description' => 'A description']);
    $user = User::factory()->create();

    $open = createEntity('en', $work, ['name' => 'Alpha EN', 'label' => 'Original', 'is_restricted' => false]);
    $granted = createEntity('ru', $work, ['name' => 'Beta RU', 'label' => 'Garnett translation', 'is_restricted' => true]);
    $granted->grantedUsers()->attach($user->id);
    $secret = createEntity('ru', $work, ['name' => 'Secret RU', 'is_restricted' => true]);

    foreach (['First.', 'Second.'] as $i => $text) {
        EntitySentence::query()->create([
            'entity_id' => $open->id,
            'content' => $text,
            'order' => $i + 1,
        ]);
    }

    return ['work' => $work, 'open' => $open, 'granted' => $granted, 'secret' => $secret, 'user' => $user];
}

test('guests are redirected from the work browse routes', function () {
    $fixture = createBrowseWork();

    $this->get(route('reader.works.show', ['work' => $fixture['work']->id]))
        ->assertRedirect(route('login'));

    $this->get(route('reader.works.entities.index', ['work' => $fixture['work']->id]))
        ->assertRedirect(route('login'));
});

test('the work sections page shows the work header and the readable texts count', function () {
    $fixture = createBrowseWork();

    $this->actingAs($fixture['user'])
        ->get(route('reader.works.show', ['work' => $fixture['work']->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reader/WorkHome')
            ->where('work.id', $fixture['work']->id)
            ->where('work.title', 'Browse Work')
            ->where('work.author', 'Author')
            ->where('work.description', 'A description')
            ->where('work.original_language.code', 'en')
            // The restricted Secret RU is invisible to this user, so the
            // Entities card's count is the readable count.
            ->where('entities_count', 2));
});

test('a work sections page 404s for an unknown work', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('reader.works.show', ['work' => 999999]))
        ->assertNotFound();
});

test('the work sections page counts alignments the user can read at completed or stale', function () {
    $fixture = createBrowseWork();
    $work = $fixture['work'];

    $pair = function (string $tag, bool $restricted = false) use ($work): array {
        return [
            createEntity('en', $work, ['name' => "{$tag} EN", 'is_restricted' => $restricted]),
            createEntity('ru', $work, ['name' => "{$tag} RU", 'is_restricted' => $restricted]),
        ];
    };

    // Counted for the user: completed and stale, both sides readable.
    createEntityMatch(...$pair('Done'), attributes: ['status' => 'completed']);
    createEntityMatch(...$pair('Stale'), attributes: ['status' => 'stale']);

    // Not counted: pending/failed matches have no meaning-match rows to read.
    createEntityMatch(...$pair('Pending'), attributes: ['status' => 'pending']);
    createEntityMatch(...$pair('Failed'), attributes: ['status' => 'failed']);

    // Not counted for this user: the restricted pair needs grants on both
    // sides. A completed match from another work never counts under this
    // work's URL either — the work comes via the A-side entity.
    createEntityMatch(...$pair('Restricted', restricted: true), attributes: ['status' => 'completed']);
    $otherWork = createWork(['title' => 'Other Work']);
    createEntityMatch(
        createEntity('en', $otherWork, ['name' => 'Other EN']),
        createEntity('ru', $otherWork, ['name' => 'Other RU']),
        ['status' => 'completed'],
    );

    $this->actingAs($fixture['user'])
        ->get(route('reader.works.show', ['work' => $work->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reader/WorkHome')
            ->where('alignments_count', 2));

    // Admin bypass reads everything, so the restricted pair counts too.
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('reader.works.show', ['work' => $work->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reader/WorkHome')
            ->where('alignments_count', 3));
});

test('the work texts list shows only readable entities of the work', function () {
    $fixture = createBrowseWork();

    $this->actingAs($fixture['user'])
        ->get(route('reader.works.entities.index', ['work' => $fixture['work']->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reader/WorkEntitiesList')
            ->where('work.id', $fixture['work']->id)
            ->where('work.title', 'Browse Work')
            ->has('entities', 2)
            ->where('entities.0.name', 'Alpha EN')
            ->where('entities.0.label', 'Original')
            ->where('entities.0.language.code', 'en')
            ->where('entities.0.sentences_count', 2)
            ->where('entities.1.name', 'Beta RU')
            ->where('entities.1.language.code', 'ru')
            ->where('meta.total', 2));
});

test('the work texts list search matches name or label with an escaped needle', function () {
    $fixture = createBrowseWork();

    $this->actingAs($fixture['user'])
        ->get(route('reader.works.entities.index', ['work' => $fixture['work']->id, 'q' => 'garnett']))
        ->assertInertia(fn ($page) => $page
            ->where('q', 'garnett')
            ->has('entities', 1)
            ->where('entities.0.name', 'Beta RU'));

    // A bare % would match everything as a wildcard; escaped, it matches
    // only the literal percent sign.
    createEntity('en', $fixture['work'], ['name' => 'Gamma 100%', 'is_restricted' => false]);

    $this->actingAs($fixture['user'])
        ->get(route('reader.works.entities.index', ['work' => $fixture['work']->id, 'q' => '%']))
        ->assertInertia(fn ($page) => $page
            ->has('entities', 1)
            ->where('entities.0.name', 'Gamma 100%'));
});

test('the work texts list language filter narrows by entity language', function () {
    $fixture = createBrowseWork();

    $this->actingAs($fixture['user'])
        ->get(route('reader.works.entities.index', ['work' => $fixture['work']->id, 'lang' => 'ru']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('lang', 'ru')
            ->has('entities', 1)
            ->where('entities.0.name', 'Beta RU'));

    // No ?lang is the All-languages default.
    $this->actingAs($fixture['user'])
        ->get(route('reader.works.entities.index', ['work' => $fixture['work']->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('lang', '')
            ->has('entities', 2));
});

test('the work texts list 404s for an unknown or disabled language code', function () {
    $fixture = createBrowseWork();
    createLanguages(['en', 'ru', 'de']);
    Language::query()->where('code', 'de')->update(['is_enabled' => false]);

    $this->actingAs($fixture['user'])
        ->get(route('reader.works.entities.index', ['work' => $fixture['work']->id, 'lang' => 'xx']))
        ->assertNotFound();

    $this->actingAs($fixture['user'])
        ->get(route('reader.works.entities.index', ['work' => $fixture['work']->id, 'lang' => 'de']))
        ->assertNotFound();
});

test('the work texts list paginates at fifteen per page', function () {
    $fixture = createBrowseWork();

    foreach (range(1, 13) as $i) {
        createEntity('en', $fixture['work'], ['name' => sprintf('Filler %02d EN', $i), 'is_restricted' => false]);
    }
    // 15 readable so far (the fixture's Alpha EN and granted Beta RU are
    // already two); one more tips the list onto a second page.
    createEntity('en', $fixture['work'], ['name' => 'Zeta EN', 'is_restricted' => false]);

    $this->actingAs($fixture['user'])
        ->get(route('reader.works.entities.index', ['work' => $fixture['work']->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('entities', 15)
            ->where('meta.current_page', 1)
            ->where('meta.per_page', 15)
            ->where('meta.total', 16)
            ->where('meta.last_page', 2));

    $this->actingAs($fixture['user'])
        ->get(route('reader.works.entities.index', ['work' => $fixture['work']->id, 'page' => 2]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('entities', 1)
            ->where('entities.0.name', 'Zeta EN')
            ->where('meta.current_page', 2));
});

test('an admin sees restricted entities in the work texts list', function () {
    $fixture = createBrowseWork();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('reader.works.entities.index', ['work' => $fixture['work']->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('entities', 3)
            ->where('entities.2.name', 'Secret RU'));
});
