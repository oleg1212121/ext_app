<?php

use App\Jobs\ProcessEntityFile;
use App\Models\EnEntity;
use App\Models\Language;
use App\Models\RuEntity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function approvedUser(): User
{
    return User::factory()->create(['is_approved' => true]);
}

function makeLanguage(string $code, bool $enabled = true): Language
{
    return Language::create([
        'code' => $code,
        'name' => ucfirst($code),
        'native_name' => $code,
        'is_enabled' => $enabled,
        'sort_order' => $enabled ? 0 : 99,
    ]);
}

test('guest is redirected from the entities index', function () {
    $this->get('/entities')->assertRedirect();
});

test('unapproved user is redirected from the entities index', function () {
    $user = User::factory()->create(['is_approved' => false]);

    $this->actingAs($user)->get('/entities')->assertRedirect('/pending-approval');
});

test('picker lists enabled languages with entity counts', function () {
    makeLanguage('en');
    makeLanguage('ru');
    EnEntity::create(['name' => 'Alpha']);
    EnEntity::create(['name' => 'Beta']);
    RuEntity::create(['name' => 'Гамма']);

    $this->actingAs(approvedUser())
        ->get('/entities')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Entities/Index')
            ->has('languages', 2)
            ->where('languages.0.code', 'en')
            ->where('languages.0.entity_count', 2)
            ->where('languages.1.code', 'ru')
            ->where('languages.1.entity_count', 1));
});

test('picker ignores disabled languages', function () {
    makeLanguage('en');
    makeLanguage('de', false);

    $this->actingAs(approvedUser())
        ->get('/entities')
        ->assertInertia(fn ($page) => $page->has('languages', 1));
});

test('list page renders entities for a language', function () {
    makeLanguage('en');
    EnEntity::create(['name' => 'Alpha']);

    $this->actingAs(approvedUser())
        ->get('/entities/en')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Entities/List')
            ->has('entities', 1)
            ->where('entities.0.name', 'Alpha')
            ->where('meta.total', 1));
});

test('list page 404s for a disabled language', function () {
    makeLanguage('de', false);

    $this->actingAs(approvedUser())
        ->get('/entities/de')
        ->assertNotFound();
});

test('create form renders', function () {
    makeLanguage('en');

    $this->actingAs(approvedUser())
        ->get('/entities/en/create')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Entities/Create'));
});

test('store creates an entity without a file and redirects', function () {
    makeLanguage('en');

    $response = $this->actingAs(approvedUser())
        ->post('/entities/en', ['name' => 'New Entity', 'description' => 'A note']);

    $entity = EnEntity::query()->where('name', 'New Entity')->firstOrFail();
    expect($entity->exists)->toBeTrue();

    $response->assertRedirect("/entities/en/{$entity->id}");
});

test('store with a file stores the file and dispatches the pipeline', function () {
    Storage::fake('local');
    Queue::fake();
    makeLanguage('en');

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/embed')) {
            return Http::response(['vector' => [0.1, 0.2, 0.3]], 200);
        }

        return Http::response(['similarities' => [0.0]], 200);
    });

    $file = UploadedFile::fake()->create('text.txt', 20, 'text/plain');

    $response = $this->actingAs(approvedUser())
        ->post('/entities/en', ['name' => 'With File', 'file' => $file]);

    $entity = EnEntity::query()->where('name', 'With File')->firstOrFail();
    expect($entity->file_path)->not->toBeNull();
    expect($entity->is_restricted)->toBeTrue();
    Storage::disk('local')->assertExists($entity->file_path);
    Queue::assertPushed(ProcessEntityFile::class);

    $response->assertRedirect("/entities/en/{$entity->id}");
});

test('store validates the name and file type', function () {
    makeLanguage('en');

    $this->actingAs(approvedUser())
        ->post('/entities/en', ['name' => ''])
        ->assertSessionHasErrors('name');

    $bad = UploadedFile::fake()->create('image.png', 10, 'image/png');

    $this->actingAs(approvedUser())
        ->post('/entities/en', ['name' => 'Bad File', 'file' => $bad])
        ->assertSessionHasErrors('file');
});

test('show page renders a single entity', function () {
    makeLanguage('en');
    $entity = EnEntity::create(['name' => 'Detail', 'description' => 'Body']);

    $this->actingAs(approvedUser())
        ->get("/entities/en/{$entity->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Entities/Show')
            ->where('entity.name', 'Detail')
            ->where('entity.sentences_count', 0));
});

test('show page exposes can_edit for a public entity', function () {
    makeLanguage('en');
    $entity = EnEntity::create(['name' => 'Open', 'is_restricted' => false]);

    $this->actingAs(approvedUser())
        ->get("/entities/en/{$entity->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can_edit', true));
});

test('show page hides can_edit for a restricted entity without a grant', function () {
    makeLanguage('en');
    $entity = EnEntity::create(['name' => 'Secret', 'is_restricted' => true]);

    $this->actingAs(approvedUser())
        ->get("/entities/en/{$entity->id}")
        ->assertForbidden();
});

test('show page exposes can_edit for a restricted entity with a grant', function () {
    makeLanguage('en');
    $entity = EnEntity::create(['name' => 'Secret', 'is_restricted' => true]);
    $user = approvedUser();
    $entity->grantedUsers()->attach($user->id);

    $this->actingAs($user)
        ->get("/entities/en/{$entity->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can_edit', true));
});

test('show page 404s for an unknown entity', function () {
    makeLanguage('en');

    $this->actingAs(approvedUser())
        ->get('/entities/en/999999')
        ->assertNotFound();
});

test('store links the uploader to the existing entity when the text matches', function () {
    Storage::fake('local');
    Queue::fake();
    makeLanguage('en');

    $existing = EnEntity::query()->create([
        'name' => 'Original Text',
        'is_restricted' => true,
        'file_path' => 'entities/en/original.txt',
        'signature' => json_encode([0.9, 0.1, 0.2]),
    ]);

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/embed')) {
            return Http::response(['vector' => [0.9, 0.1, 0.2]], 200);
        }

        if (str_contains($request->url(), '/cosine/batch')) {
            return Http::response(['similarities' => [1.0]], 200);
        }

        return Http::response(['error' => 'unexpected'], 500);
    });

    $user = approvedUser();
    $file = UploadedFile::fake()->create('text.txt', 20, 'text/plain');

    $response = $this->actingAs($user)
        ->post('/entities/en', ['name' => 'Duplicate Upload', 'file' => $file]);

    expect(EnEntity::query()->where('name', 'Duplicate Upload')->exists())->toBeFalse();
    expect($existing->grantedUsers()->whereKey($user->id)->exists())->toBeTrue();
    expect($existing->grantedUsers()->whereKey($user->id)->first()->pivot->similarity)->toEqual(1.0);
    Storage::disk('local')->assertMissing('entities/en/'.$file->hashName());

    $response->assertRedirect("/entities/en/{$existing->id}")
        ->assertSessionHas('status');
});

test('store fails hard when the embedding service is unavailable', function () {
    Storage::fake('local');
    Queue::fake();
    makeLanguage('en');

    Http::fake(fn () => Http::response('bad gateway', 502));

    $file = UploadedFile::fake()->create('text.txt', 20, 'text/plain');

    $response = $this->actingAs(approvedUser())
        ->post('/entities/en', ['name' => 'No Service', 'file' => $file]);

    expect(EnEntity::query()->where('name', 'No Service')->exists())->toBeFalse();
    Queue::assertNotPushed(ProcessEntityFile::class);

    $response->assertRedirect();
});

test('user cannot read a restricted entity without a grant', function () {
    makeLanguage('en');
    $entity = EnEntity::query()->create(['name' => 'Secret', 'is_restricted' => true]);

    $this->actingAs(approvedUser())
        ->get("/entities/en/{$entity->id}")
        ->assertForbidden();
});

test('user can read a restricted entity they have a grant for', function () {
    makeLanguage('en');
    $entity = EnEntity::query()->create(['name' => 'Secret', 'is_restricted' => true]);
    $user = approvedUser();
    $entity->grantedUsers()->attach($user->id);

    $this->actingAs($user)
        ->get("/entities/en/{$entity->id}")
        ->assertOk();
});

test('user can read a public entity', function () {
    makeLanguage('en');
    $entity = EnEntity::query()->create(['name' => 'Open', 'is_restricted' => false]);

    $this->actingAs(approvedUser())
        ->get("/entities/en/{$entity->id}")
        ->assertOk();
});

test('admin can read any restricted entity', function () {
    makeLanguage('en');
    $entity = EnEntity::query()->create(['name' => 'Secret', 'is_restricted' => true]);

    $admin = User::factory()->create(['is_approved' => true, 'role' => 'admin']);

    $this->actingAs($admin)
        ->get("/entities/en/{$entity->id}")
        ->assertOk();
});

test('restricted entity is hidden from the list unless granted', function () {
    makeLanguage('en');
    EnEntity::query()->create(['name' => 'Secret', 'is_restricted' => true]);
    EnEntity::query()->create(['name' => 'Open', 'is_restricted' => false]);
    $user = approvedUser();
    $secret = EnEntity::query()->where('name', 'Secret')->firstOrFail();
    $secret->grantedUsers()->attach($user->id);

    $this->actingAs($user)
        ->get('/entities/en')
        ->assertInertia(fn ($page) => $page->has('entities', 2));
});

test('restricted entity is absent from the list without a grant', function () {
    makeLanguage('en');
    EnEntity::query()->create(['name' => 'Secret', 'is_restricted' => true]);
    EnEntity::query()->create(['name' => 'Open', 'is_restricted' => false]);

    $this->actingAs(approvedUser())
        ->get('/entities/en')
        ->assertInertia(fn ($page) => $page->has('entities', 1));
});
