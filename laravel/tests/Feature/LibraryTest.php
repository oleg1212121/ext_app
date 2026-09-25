<?php

use App\Jobs\ProcessEntityFile;
use App\Models\Entity;
use App\Models\Language;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

if (! function_exists('approvedUser')) {
    function approvedUser(): User
    {
        return User::factory()->create(['is_approved' => true]);
    }
}

if (! function_exists('adminUser')) {
    function adminUser(): User
    {
        return User::factory()->create(['is_approved' => true, 'role' => 'admin']);
    }
}

test('guest is redirected from the works pages', function () {
    $this->get('/works')->assertRedirect();
});

test('unapproved user is redirected from the works pages', function () {
    $user = User::factory()->create(['is_approved' => false]);

    $this->actingAs($user)->get('/works')->assertRedirect('/pending-approval');
});

test('the legacy library URLs are gone', function () {
    $user = approvedUser();

    $this->actingAs($user)->get('/library')->assertNotFound();
    $this->actingAs($user)->get('/library/1')->assertNotFound();
    $this->actingAs($user)->get('/library/1?tab=alignments')->assertNotFound();
    $this->actingAs($user)->post('/library', [])->assertNotFound();
});

test('the legacy entities browse pages redirect to the works entities branch', function () {
    createLanguages();

    $this->actingAs(approvedUser())
        ->get('/entities')
        ->assertRedirect('/works/entities');

    $this->actingAs(approvedUser())
        ->get('/entities/en')
        ->assertRedirect('/works/entities');
});

test('index lists works including a work with no entities', function () {
    createLanguages();
    createWork(['title' => 'Alpha', 'author' => 'An Author']);
    createEntity('en', null, ['name' => 'Alpha EN']);

    $this->actingAs(approvedUser())
        ->get('/works')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Library/Index')
            ->where('variant', 'catalog')
            ->has('works', 2)
            ->where('works.0.title', 'Alpha')
            ->where('works.0.author', 'An Author')
            ->where('works.0.original_language.name', 'English')
            ->where('works.0.entities_count', 0)
            ->where('works.0.alignments_count', 0)
            ->where('works.1.entities_count', 1)
            ->where('meta.total', 2));
});

test('work card counts only entities the user can read', function () {
    createLanguages();
    $user = approvedUser();
    $work = createWork(['title' => 'Counted']);

    createEntity('en', $work, ['name' => 'Open', 'is_restricted' => false]);
    $granted = createEntity('en', $work, ['name' => 'Granted', 'is_restricted' => true]);
    $granted->grantedUsers()->attach($user->id);
    createEntity('ru', $work, ['name' => 'Secret', 'is_restricted' => true]);

    $assertCount = fn (int $count) => fn ($page) => $page->where('works.0.entities_count', $count);

    $this->actingAs($user)
        ->get('/works')
        ->assertInertia($assertCount(2));

    $this->actingAs(approvedUser())
        ->get('/works')
        ->assertInertia($assertCount(1));

    $this->actingAs(adminUser())
        ->get('/works')
        ->assertInertia($assertCount(3));
});

test('the entities branch list shows every work with readable entity counts', function () {
    createLanguages();
    createWork(['title' => 'Alpha']);
    createEntity('en', null, ['name' => 'Alpha EN']);

    $this->actingAs(approvedUser())
        ->get('/works/entities')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Library/Index')
            ->where('variant', 'entities')
            ->has('works', 2)
            ->where('works.0.entities_count', 0)
            ->where('works.1.entities_count', 1)
            ->where('meta.total', 2));
});

test('the alignments branch list counts only matches the user can read', function () {
    createLanguages();
    $user = approvedUser();
    $work = createWork(['title' => 'Counted']);
    createWork(['title' => 'Empty of matches']);

    $openEn = createEntity('en', $work, ['name' => 'Open EN', 'is_restricted' => false]);
    $openRu = createEntity('ru', $work, ['name' => 'Open RU', 'is_restricted' => false]);
    createEntityMatch($openEn, $openRu);

    $secretEn = createEntity('en', $work, ['name' => 'Secret EN', 'is_restricted' => true]);
    $secretRu = createEntity('ru', $work, ['name' => 'Secret RU', 'is_restricted' => true]);
    createEntityMatch($secretEn, $secretRu);

    $assertCount = fn (int $count) => fn ($page) => $page
        ->where('variant', 'alignments')
        ->where('works.0.alignments_count', $count);

    // Works stay a public catalog: even a work with zero readable matches is listed.
    $this->actingAs($user)
        ->get('/works/alignments')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Library/Index')
            ->has('works', 2)
            ->where('works.0.alignments_count', 1)
            ->where('works.1.title', 'Empty of matches')
            ->where('works.1.alignments_count', 0));

    // Grants on both sides of the restricted match make it count.
    $secretEn->grantedUsers()->attach($user->id);
    $secretRu->grantedUsers()->attach($user->id);

    $this->actingAs($user)
        ->get('/works/alignments')
        ->assertInertia($assertCount(2));

    $this->actingAs(approvedUser())
        ->get('/works/alignments')
        ->assertInertia($assertCount(1));

    $this->actingAs(adminUser())
        ->get('/works/alignments')
        ->assertInertia($assertCount(2));
});

test('index search matches works by title or author', function () {
    createLanguages();
    createWork(['title' => 'War and Peace', 'author' => 'Tolstoy']);
    createWork(['title' => 'Anna Karenina', 'author' => 'Tolstoy']);
    createWork(['title' => 'Crime and Punishment', 'author' => 'Dostoevsky']);

    $this->actingAs(approvedUser())
        ->get('/works?q=tolstoy')
        ->assertInertia(fn ($page) => $page
            ->where('q', 'tolstoy')
            ->has('works', 2)
            ->where('meta.total', 2));

    $this->actingAs(approvedUser())
        ->get('/works?q=crime')
        ->assertInertia(fn ($page) => $page
            ->has('works', 1)
            ->where('works.0.title', 'Crime and Punishment'));

    $this->actingAs(approvedUser())
        ->get('/works?q=nothing-matches')
        ->assertInertia(fn ($page) => $page
            ->has('works', 0)
            ->where('meta.total', 0));
});

test('index paginates works', function () {
    createLanguages();

    foreach (range(1, 16) as $i) {
        createWork(['title' => sprintf('Work %02d', $i)]);
    }

    $this->actingAs(approvedUser())
        ->get('/works')
        ->assertInertia(fn ($page) => $page
            ->has('works', 15)
            ->where('meta.current_page', 1)
            ->where('meta.last_page', 2)
            ->where('meta.total', 16));

    $this->actingAs(approvedUser())
        ->get('/works?page=2')
        ->assertInertia(fn ($page) => $page
            ->has('works', 1)
            ->where('meta.current_page', 2));
});

test('a work landing page shows its metadata and readable counts', function () {
    createLanguages();
    $work = createWork([
        'title' => 'Landing',
        'author' => 'Author',
        'description' => 'A description',
        'original_language_id' => Language::query()->where('code', 'ru')->value('id'),
    ]);
    $user = approvedUser();

    createEntity('en', $work, ['name' => 'Open EN', 'is_restricted' => false]);
    $granted = createEntity('ru', $work, ['name' => 'Granted RU', 'is_restricted' => true]);
    $granted->grantedUsers()->attach($user->id);
    createEntity('ru', $work, ['name' => 'Secret RU', 'is_restricted' => true]);

    $openEn = createEntity('en', $work, ['name' => 'Pair EN', 'is_restricted' => false]);
    $openRu = createEntity('ru', $work, ['name' => 'Pair RU', 'is_restricted' => false]);
    createEntityMatch($openEn, $openRu);

    $this->actingAs($user)
        ->get("/works/{$work->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Library/ShowWork')
            ->where('work.title', 'Landing')
            ->where('work.author', 'Author')
            ->where('work.description', 'A description')
            ->where('work.original_language.code', 'ru')
            ->where('work.entities_count', 4)
            ->where('work.alignments_count', 1)
            ->missing('tab')
            ->missing('entities')
            ->missing('alignments'));
});

test('a work landing page 404s for an unknown work', function () {
    $this->actingAs(approvedUser())
        ->get('/works/999999')
        ->assertNotFound();
});

test('the work entities page lists only readable entities of the work', function () {
    createLanguages();
    $work = createWork(['title' => 'Shown', 'author' => 'Author', 'description' => 'A description']);
    $user = approvedUser();

    $open = createEntity('en', $work, ['name' => 'Open EN', 'is_restricted' => false]);
    $granted = createEntity('ru', $work, ['name' => 'Granted RU', 'is_restricted' => true]);
    $granted->grantedUsers()->attach($user->id);
    createEntity('ru', $work, ['name' => 'Secret RU', 'is_restricted' => true]);
    createEntity('en', null, ['name' => 'Other work entity', 'is_restricted' => false]);

    $this->actingAs($user)
        ->get("/works/{$work->id}/entities")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Library/WorkEntities')
            ->where('work.title', 'Shown')
            ->where('work.id', $work->id)
            ->has('entities', 2)
            ->where('entities.0.name', 'Granted RU')
            ->where('entities.0.language.code', 'ru')
            ->where('entities.1.name', 'Open EN')
            ->where('entities.1.language.code', 'en')
            ->where('meta.total', 2));

    expect($open->work_id)->toBe($work->id);
});

test('the work entities page renders an empty work with zero entities', function () {
    createLanguages();
    $work = createWork(['title' => 'Empty']);

    $this->actingAs(approvedUser())
        ->get("/works/{$work->id}/entities")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('work.title', 'Empty')
            ->has('entities', 0)
            ->where('meta.total', 0));
});

test('the work entities page search matches entities by name or label', function () {
    createLanguages();
    $work = createWork(['title' => 'Searched']);
    createEntity('en', $work, ['name' => 'Alpha', 'label' => 'Garnett translation', 'is_restricted' => false]);
    createEntity('en', $work, ['name' => 'Beta', 'is_restricted' => false]);

    $this->actingAs(approvedUser())
        ->get("/works/{$work->id}/entities?q=alpha")
        ->assertInertia(fn ($page) => $page
            ->has('entities', 1)
            ->where('entities.0.name', 'Alpha'));

    $this->actingAs(approvedUser())
        ->get("/works/{$work->id}/entities?q=garnett")
        ->assertInertia(fn ($page) => $page
            ->has('entities', 1)
            ->where('entities.0.name', 'Alpha'));
});

test('the work alignments page search matches either side entity name', function () {
    createLanguages();
    $work = createWork(['title' => 'Aligned']);
    $user = approvedUser();

    $match = createEntityMatch(
        createEntity('en', $work, ['name' => 'Garnett Translation']),
        createEntity('ru', $work, ['name' => 'Русский перевод']),
    );

    $this->actingAs($user)
        ->get("/works/{$work->id}/alignments?q=garnett")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Library/WorkAlignments')
            ->has('alignments', 1)
            ->where('alignments.0.id', $match->id)
            ->where('q', 'garnett'));

    $this->actingAs($user)
        ->get("/works/{$work->id}/alignments?q=".rawurlencode('перевод'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('alignments', 1));

    $this->actingAs($user)
        ->get("/works/{$work->id}/alignments?q=nothing")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('alignments', 0));
});

test('the work alignments page paginates', function () {
    createLanguages();
    $work = createWork(['title' => 'Long shelf']);

    foreach (range(1, 16) as $i) {
        createEntityMatch(
            createEntity('en', $work, ['name' => "Pair {$i} EN"]),
            createEntity('ru', $work, ['name' => "Pair {$i} RU"]),
        );
    }

    $this->actingAs(approvedUser())
        ->get("/works/{$work->id}/alignments")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('alignments', 15)
            ->where('alignments_meta.current_page', 1)
            ->where('alignments_meta.last_page', 2)
            ->where('alignments_meta.total', 16));

    $this->actingAs(approvedUser())
        ->get("/works/{$work->id}/alignments?page=2")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('alignments', 1)
            ->where('alignments_meta.current_page', 2));
});

test('alignment reader target prefers the language the user is learning', function () {
    createLanguages();
    $work = createWork(['title' => 'Native', 'original_language_id' => Language::query()->where('code', 'en')->value('id')]);
    $en = createEntity('en', $work, ['name' => 'EN side']);
    $ru = createEntity('ru', $work, ['name' => 'RU side']);
    createEntityMatch($en, $ru);

    // Native English → read the Russian side.
    $this->actingAs(approvedUser())
        ->get("/works/{$work->id}/alignments")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('alignments.0.reader_target.lang', 'ru')
            ->where('alignments.0.reader_target.entity_id', $ru->id));

    // Native Russian → read the English side.
    $russian = approvedUser();
    $russian->settings()->update(['native_language_id' => Language::query()->where('code', 'ru')->value('id')]);

    $this->actingAs($russian)
        ->get("/works/{$work->id}/alignments")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('alignments.0.reader_target.lang', 'en')
            ->where('alignments.0.reader_target.entity_id', $en->id));
});

test('alignment reader target tiebreaks on the original language', function () {
    createLanguages();
    $fr = Language::query()->create([
        'code' => 'fr',
        'name' => 'French',
        'native_name' => 'Français',
        'is_enabled' => true,
        'sort_order' => 5,
    ]);

    // Both sides are non-native for a French reader; the original side wins.
    $work = createWork(['title' => 'Tiebreak', 'original_language_id' => Language::query()->where('code', 'en')->value('id')]);
    $en = createEntity('en', $work, ['name' => 'EN original']);
    $ru = createEntity('ru', $work, ['name' => 'RU translation']);
    createEntityMatch($en, $ru);

    $french = approvedUser();
    $french->settings()->update(['native_language_id' => $fr->id]);

    $this->actingAs($french)
        ->get("/works/{$work->id}/alignments")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('alignments.0.reader_target.lang', 'en')
            ->where('alignments.0.reader_target.entity_id', $en->id));
});

test('alignment reader target falls back to the a side for same-language pairs', function () {
    createLanguages();
    $work = createWork(['title' => 'Exercises']);
    $first = createEntity('en', $work, ['name' => 'Exercises']);
    $second = createEntity('en', $work, ['name' => 'Answer key']);
    createEntityMatch($first, $second);

    // Both sides are the reader's native language: no learning side exists,
    // so the original side (a, for this all-English work) is opened.
    $this->actingAs(approvedUser())
        ->get("/works/{$work->id}/alignments")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('alignments.0.reader_target.lang', 'en')
            ->where('alignments.0.reader_target.entity_id', fn ($id) => in_array($id, [$first->id, $second->id], true)));
});

test('create work form renders with enabled languages', function () {
    createLanguages();

    $this->actingAs(approvedUser())
        ->get('/works/create')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Library/CreateWork')
            ->has('languages', 2));
});

test('store work creates the work and redirects to its landing page', function () {
    createLanguages();
    $languageId = Language::query()->where('code', 'en')->value('id');

    $response = $this->actingAs(approvedUser())
        ->post('/works', [
            'title' => 'New Work',
            'author' => 'Someone',
            'description' => 'Freshly added',
            'original_language_id' => $languageId,
        ]);

    $work = Work::query()->where('title', 'New Work')->firstOrFail();
    expect($work->author)->toBe('Someone')
        ->and($work->original_language_id)->toBe($languageId);

    $response->assertRedirect("/works/{$work->id}");
});

test('store work validates title and original language', function () {
    createLanguages();

    $this->actingAs(approvedUser())
        ->post('/works', [])
        ->assertSessionHasErrors(['title', 'original_language_id']);

    $disabled = Language::query()->create([
        'code' => 'de',
        'name' => 'German',
        'native_name' => 'Deutsch',
        'is_enabled' => false,
        'sort_order' => 99,
    ]);

    $this->actingAs(approvedUser())
        ->post('/works', ['title' => 'Valid', 'original_language_id' => $disabled->id])
        ->assertSessionHasErrors('original_language_id');
});

test('create entity form renders scoped to the work', function () {
    createLanguages();
    $work = createWork(['title' => 'Scoped']);

    $this->actingAs(approvedUser())
        ->get("/works/{$work->id}/entities/create")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Library/CreateEntity')
            ->where('work.title', 'Scoped')
            ->has('languages', 2));
});

test('store entity creates a restricted entity under the work with a creator grant', function () {
    createLanguages();
    $work = createWork(['title' => 'Host']);
    $user = approvedUser();
    $languageId = Language::query()->where('code', 'en')->value('id');

    $response = $this->actingAs($user)
        ->post("/works/{$work->id}/entities", [
            'language_id' => $languageId,
            'name' => 'Library Entity',
            'description' => 'From the library form',
        ]);

    $entity = Entity::query()->where('name', 'Library Entity')->firstOrFail();
    expect($entity->work_id)->toBe($work->id)
        ->and($entity->language_id)->toBe($languageId)
        ->and($entity->is_restricted)->toBeTrue()
        ->and($entity->grantedUsers()->whereKey($user->id)->exists())->toBeTrue()
        ->and($entity->grantedUsers()->whereKey($user->id)->first()->pivot->similarity)->toBeNull();

    $response->assertRedirect("/entities/en/{$entity->id}");
});

test('store entity with a file stores it and dispatches the pipeline', function () {
    Storage::fake('local');
    Queue::fake();
    createLanguages();
    $work = createWork(['title' => 'File Host']);
    $languageId = Language::query()->where('code', 'en')->value('id');

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/embed')) {
            return Http::response(['vector' => [0.1, 0.2, 0.3]], 200);
        }

        return Http::response(['similarities' => [0.0]], 200);
    });

    $file = UploadedFile::fake()->create('text.txt', 20, 'text/plain');

    $response = $this->actingAs(approvedUser())
        ->post("/works/{$work->id}/entities", [
            'language_id' => $languageId,
            'name' => 'With File',
            'file' => $file,
        ]);

    $entity = Entity::query()->where('name', 'With File')->firstOrFail();
    expect($entity->file_path)->not->toBeNull()
        ->and($entity->work_id)->toBe($work->id);
    Storage::disk('local')->assertExists($entity->file_path);
    Queue::assertPushed(ProcessEntityFile::class);

    $response->assertRedirect("/entities/en/{$entity->id}");
});

test('store entity validates the language and name', function () {
    createLanguages();
    $work = createWork(['title' => 'Validation Host']);

    $this->actingAs(approvedUser())
        ->post("/works/{$work->id}/entities", ['name' => 'No Language'])
        ->assertSessionHasErrors('language_id');

    $this->actingAs(approvedUser())
        ->post("/works/{$work->id}/entities", [
            'language_id' => Language::query()->where('code', 'en')->value('id'),
        ])
        ->assertSessionHasErrors('name');

    $this->actingAs(approvedUser())
        ->post('/works/999999/entities', [
            'language_id' => Language::query()->where('code', 'en')->value('id'),
            'name' => 'Nowhere',
        ])
        ->assertNotFound();
});
