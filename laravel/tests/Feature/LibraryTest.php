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

test('guest is redirected from the library', function () {
    $this->get('/library')->assertRedirect();
});

test('unapproved user is redirected from the library', function () {
    $user = User::factory()->create(['is_approved' => false]);

    $this->actingAs($user)->get('/library')->assertRedirect('/pending-approval');
});

test('the legacy entities browse pages redirect to the library', function () {
    createLanguages();

    $this->actingAs(approvedUser())
        ->get('/entities')
        ->assertRedirect('/library');

    $this->actingAs(approvedUser())
        ->get('/entities/en')
        ->assertRedirect('/library');
});

test('index lists works including a work with no entities', function () {
    createLanguages();
    createWork(['title' => 'Alpha', 'author' => 'An Author']);
    createEntity('en', null, ['name' => 'Alpha EN']);

    $this->actingAs(approvedUser())
        ->get('/library')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Library/Index')
            ->has('works', 2)
            ->where('works.0.title', 'Alpha')
            ->where('works.0.author', 'An Author')
            ->where('works.0.original_language.name', 'English')
            ->where('works.0.entities_count', 0)
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
        ->get('/library')
        ->assertInertia($assertCount(2));

    $this->actingAs(approvedUser())
        ->get('/library')
        ->assertInertia($assertCount(1));

    $this->actingAs(adminUser())
        ->get('/library')
        ->assertInertia($assertCount(3));
});

test('index search matches works by title or author', function () {
    createLanguages();
    createWork(['title' => 'War and Peace', 'author' => 'Tolstoy']);
    createWork(['title' => 'Anna Karenina', 'author' => 'Tolstoy']);
    createWork(['title' => 'Crime and Punishment', 'author' => 'Dostoevsky']);

    $this->actingAs(approvedUser())
        ->get('/library?q=tolstoy')
        ->assertInertia(fn ($page) => $page
            ->where('q', 'tolstoy')
            ->has('works', 2)
            ->where('meta.total', 2));

    $this->actingAs(approvedUser())
        ->get('/library?q=crime')
        ->assertInertia(fn ($page) => $page
            ->has('works', 1)
            ->where('works.0.title', 'Crime and Punishment'));

    $this->actingAs(approvedUser())
        ->get('/library?q=nothing-matches')
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
        ->get('/library')
        ->assertInertia(fn ($page) => $page
            ->has('works', 15)
            ->where('meta.current_page', 1)
            ->where('meta.last_page', 2)
            ->where('meta.total', 16));

    $this->actingAs(approvedUser())
        ->get('/library?page=2')
        ->assertInertia(fn ($page) => $page
            ->has('works', 1)
            ->where('meta.current_page', 2));
});

test('work page lists only readable entities of the work', function () {
    createLanguages();
    $work = createWork(['title' => 'Shown', 'author' => 'Author', 'description' => 'A description']);
    $user = approvedUser();

    $open = createEntity('en', $work, ['name' => 'Open EN', 'is_restricted' => false]);
    $granted = createEntity('ru', $work, ['name' => 'Granted RU', 'is_restricted' => true]);
    $granted->grantedUsers()->attach($user->id);
    createEntity('ru', $work, ['name' => 'Secret RU', 'is_restricted' => true]);
    createEntity('en', null, ['name' => 'Other work entity', 'is_restricted' => false]);

    $this->actingAs($user)
        ->get("/library/{$work->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Library/ShowWork')
            ->where('work.title', 'Shown')
            ->where('work.author', 'Author')
            ->where('work.description', 'A description')
            ->has('entities', 2)
            ->where('entities.0.name', 'Granted RU')
            ->where('entities.0.language.code', 'ru')
            ->where('entities.1.name', 'Open EN')
            ->where('entities.1.language.code', 'en')
            ->where('meta.total', 2));

    expect($open->work_id)->toBe($work->id);
});

test('work page renders an empty work with zero entities', function () {
    createLanguages();
    $work = createWork(['title' => 'Empty']);

    $this->actingAs(approvedUser())
        ->get("/library/{$work->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('work.title', 'Empty')
            ->has('entities', 0)
            ->where('meta.total', 0));
});

test('work page search matches entities by name or label', function () {
    createLanguages();
    $work = createWork(['title' => 'Searched']);
    createEntity('en', $work, ['name' => 'Alpha', 'label' => 'Garnett translation', 'is_restricted' => false]);
    createEntity('en', $work, ['name' => 'Beta', 'is_restricted' => false]);

    $this->actingAs(approvedUser())
        ->get("/library/{$work->id}?q=alpha")
        ->assertInertia(fn ($page) => $page
            ->has('entities', 1)
            ->where('entities.0.name', 'Alpha'));

    $this->actingAs(approvedUser())
        ->get("/library/{$work->id}?q=garnett")
        ->assertInertia(fn ($page) => $page
            ->has('entities', 1)
            ->where('entities.0.name', 'Alpha'));
});

test('work page 404s for an unknown work', function () {
    $this->actingAs(approvedUser())
        ->get('/library/999999')
        ->assertNotFound();
});

test('create work form renders with enabled languages', function () {
    createLanguages();

    $this->actingAs(approvedUser())
        ->get('/library/create')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Library/CreateWork')
            ->has('languages', 2));
});

test('store work creates the work and redirects to its page', function () {
    createLanguages();
    $languageId = Language::query()->where('code', 'en')->value('id');

    $response = $this->actingAs(approvedUser())
        ->post('/library', [
            'title' => 'New Work',
            'author' => 'Someone',
            'description' => 'Freshly added',
            'original_language_id' => $languageId,
        ]);

    $work = Work::query()->where('title', 'New Work')->firstOrFail();
    expect($work->author)->toBe('Someone')
        ->and($work->original_language_id)->toBe($languageId);

    $response->assertRedirect("/library/{$work->id}");
});

test('store work validates title and original language', function () {
    createLanguages();

    $this->actingAs(approvedUser())
        ->post('/library', [])
        ->assertSessionHasErrors(['title', 'original_language_id']);

    $disabled = Language::query()->create([
        'code' => 'de',
        'name' => 'German',
        'native_name' => 'Deutsch',
        'is_enabled' => false,
        'sort_order' => 99,
    ]);

    $this->actingAs(approvedUser())
        ->post('/library', ['title' => 'Valid', 'original_language_id' => $disabled->id])
        ->assertSessionHasErrors('original_language_id');
});

test('create entity form renders scoped to the work', function () {
    createLanguages();
    $work = createWork(['title' => 'Scoped']);

    $this->actingAs(approvedUser())
        ->get("/library/{$work->id}/entities/create")
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
        ->post("/library/{$work->id}/entities", [
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
        ->post("/library/{$work->id}/entities", [
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
        ->post("/library/{$work->id}/entities", ['name' => 'No Language'])
        ->assertSessionHasErrors('language_id');

    $this->actingAs(approvedUser())
        ->post("/library/{$work->id}/entities", [
            'language_id' => Language::query()->where('code', 'en')->value('id'),
        ])
        ->assertSessionHasErrors('name');

    $this->actingAs(approvedUser())
        ->post('/library/999999/entities', [
            'language_id' => Language::query()->where('code', 'en')->value('id'),
            'name' => 'Nowhere',
        ])
        ->assertNotFound();
});
