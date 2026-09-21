<?php

use App\Classes\EntityTextHasher;
use App\Jobs\ProcessEntityFile;
use App\Models\Entity;
use App\Models\Language;
use App\Models\User;
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

if (! function_exists('makeLanguage')) {
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
}

test('edit page alignment count excludes matches with an unreadable other side', function () {
    makeLanguage('en');
    makeLanguage('ru');
    $work = createWork();
    $user = approvedUser();

    $en = createEntity('en', $work, ['name' => 'Open EN', 'is_restricted' => false]);
    $ru = createEntity('ru', $work, ['name' => 'Open RU', 'is_restricted' => false]);
    $secret = createEntity('ru', $work, ['name' => 'Secret RU', 'is_restricted' => true]);

    createEntityMatch($en, $ru);
    createEntityMatch($en, $secret);

    $this->actingAs($user)
        ->get("/entities/en/{$en->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('alignmentCount', 1));
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
        ->post('/entities/en', [
            'name' => 'New Entity',
            'description' => 'A note',
            'new_work_title' => 'New Work',
        ]);

    $entity = Entity::query()->where('name', 'New Entity')->firstOrFail();
    expect($entity->exists)->toBeTrue()
        ->and($entity->work->title)->toBe('New Work');

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
        ->post('/entities/en', [
            'name' => 'With File',
            'new_work_title' => 'With File Work',
            'file' => $file,
        ]);

    $entity = Entity::query()->where('name', 'With File')->firstOrFail();
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
    $entity = createEntity('en', null, ['name' => 'Detail', 'description' => 'Body']);

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
    $entity = createEntity('en', null, ['name' => 'Open', 'is_restricted' => false]);

    $this->actingAs(approvedUser())
        ->get("/entities/en/{$entity->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can_edit', true));
});

test('show page hides can_edit for a restricted entity without a grant', function () {
    makeLanguage('en');
    $entity = createEntity('en', null, ['name' => 'Secret', 'is_restricted' => true]);

    $this->actingAs(approvedUser())
        ->get("/entities/en/{$entity->id}")
        ->assertForbidden();
});

test('show page exposes can_edit for a restricted entity with a grant', function () {
    makeLanguage('en');
    $entity = createEntity('en', null, ['name' => 'Secret', 'is_restricted' => true]);
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

test('store clones derivations when the uploaded file is an exact copy', function () {
    Storage::fake('local');
    Queue::fake();
    makeLanguage('en');

    $path = 'entities/en/original.txt';
    Storage::disk('local')->put($path, "One. Two. Three.\nFour.");
    $existing = createEntity('en', null, [
        'name' => 'Original Text',
        'is_restricted' => true,
        'file_path' => $path,
        'file_hash' => EntityTextHasher::hashStoredFile($path),
        'signature' => json_encode([0.9, 0.1, 0.2]),
    ]);
    $existing->sentences()->createMany([
        ['sentence_type_id' => null, 'content' => 'One.', 'order' => 1024],
        ['sentence_type_id' => null, 'content' => 'Two.', 'order' => 2048],
    ]);
    $existing->entityWords()->create(['word_id' => null, 'l_word' => 'one', 'token' => 'one', 'count' => 1]);

    // No python fake needed: the exact-copy path must not call any service.
    Http::fake(fn () => Http::response(['error' => 'unexpected'], 500));

    $user = approvedUser();
    $file = UploadedFile::fake()->createWithContent('text.txt', "One. Two. Three.\nFour.");

    $response = $this->actingAs($user)
        ->post('/entities/en', [
            'name' => 'Duplicate Upload',
            'new_work_title' => 'Duplicate Work',
            'file' => $file,
        ]);

    $clone = Entity::query()->where('name', 'Duplicate Upload')->first();
    expect($clone)->not->toBeNull()
        ->and($clone->created_by)->toBe($user->id)
        ->and($clone->is_restricted)->toBeTrue()
        ->and($clone->signature)->toBe($existing->signature)
        ->and($clone->file_hash)->toBe($existing->file_hash)
        ->and($clone->sentences()->count())->toBe(2)
        ->and($clone->entityWords()->count())->toBe(1)
        ->and($existing->sentences()->count())->toBe(2);

    // The uploader keeps access to their own clone.
    expect($clone->grantedUsers()->whereKey($user->id)->exists())->toBeTrue();

    Http::assertSentCount(0);
    Queue::assertNotPushed(ProcessEntityFile::class);

    $response->assertRedirect("/entities/en/{$clone->id}")
        ->assertSessionHas('status');
});

test('store survives an embedding-service outage and queues the pipeline', function () {
    Storage::fake('local');
    Queue::fake();
    makeLanguage('en');

    Http::fake(fn () => Http::response('bad gateway', 502));

    $file = UploadedFile::fake()->createWithContent('text.txt', 'Some unique content.');

    $response = $this->actingAs(approvedUser())
        ->post('/entities/en', [
            'name' => 'No Service',
            'new_work_title' => 'No Service Work',
            'file' => $file,
        ]);

    $entity = Entity::query()->where('name', 'No Service')->first();
    expect($entity)->not->toBeNull()
        ->and($entity->signature)->toBeNull()
        ->and($entity->file_hash)->not->toBeNull();

    Queue::assertPushed(ProcessEntityFile::class);

    $response->assertRedirect("/entities/en/{$entity->id}");
});

test('user cannot read a restricted entity without a grant', function () {
    makeLanguage('en');
    $entity = createEntity('en', null, ['name' => 'Secret', 'is_restricted' => true]);

    $this->actingAs(approvedUser())
        ->get("/entities/en/{$entity->id}")
        ->assertForbidden();
});

test('user can read a restricted entity they have a grant for', function () {
    makeLanguage('en');
    $entity = createEntity('en', null, ['name' => 'Secret', 'is_restricted' => true]);
    $user = approvedUser();
    $entity->grantedUsers()->attach($user->id);

    $this->actingAs($user)
        ->get("/entities/en/{$entity->id}")
        ->assertOk();
});

test('user can read a public entity', function () {
    makeLanguage('en');
    $entity = createEntity('en', null, ['name' => 'Open', 'is_restricted' => false]);

    $this->actingAs(approvedUser())
        ->get("/entities/en/{$entity->id}")
        ->assertOk();
});

test('admin can read any restricted entity', function () {
    makeLanguage('en');
    $entity = createEntity('en', null, ['name' => 'Secret', 'is_restricted' => true]);

    $admin = User::factory()->create(['is_approved' => true, 'role' => 'admin']);

    $this->actingAs($admin)
        ->get("/entities/en/{$entity->id}")
        ->assertOk();
});
