<?php

use App\Classes\EntityTextHasher;
use App\Jobs\ProcessEntityFile;
use App\Models\Entity;
use App\Models\Language;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        ->get("/works/{$work->id}/entities/{$en->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('alignmentCount', 1));
});

test('show page renders a single entity', function () {
    makeLanguage('en');
    $entity = createEntity('en', null, ['name' => 'Detail', 'description' => 'Body']);

    $this->actingAs(approvedUser())
        ->get("/works/{$entity->work_id}/entities/{$entity->id}")
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
        ->get("/works/{$entity->work_id}/entities/{$entity->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can_edit', true));
});

test('show page hides can_edit for a restricted entity without a grant', function () {
    makeLanguage('en');
    $entity = createEntity('en', null, ['name' => 'Secret', 'is_restricted' => true]);

    $this->actingAs(approvedUser())
        ->get("/works/{$entity->work_id}/entities/{$entity->id}")
        ->assertForbidden();
});

test('show page exposes can_edit for a restricted entity with a grant', function () {
    makeLanguage('en');
    $entity = createEntity('en', null, ['name' => 'Secret', 'is_restricted' => true]);
    $user = approvedUser();
    $entity->grantedUsers()->attach($user->id);

    $this->actingAs($user)
        ->get("/works/{$entity->work_id}/entities/{$entity->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can_edit', true));
});

test('show page 404s for an unknown entity', function () {
    makeLanguage('en');
    $work = createWork();

    $this->actingAs(approvedUser())
        ->get("/works/{$work->id}/entities/999999")
        ->assertNotFound();
});

test('show page 404s when the URL names another work', function () {
    makeLanguage('en');
    $entity = createEntity('en', null, ['name' => 'Detail']);
    $otherWork = createWork(['title' => 'Other Work']);

    $this->actingAs(approvedUser())
        ->get("/works/{$otherWork->id}/entities/{$entity->id}")
        ->assertNotFound();
});

test('the flat language-segmented entity routes are gone', function () {
    makeLanguage('en');
    $entity = createEntity('en', null, ['name' => 'Detail']);
    $workId = $entity->work_id;

    $user = approvedUser();
    $this->actingAs($user)->get("/entities/en/{$entity->id}")->assertNotFound();
    $this->actingAs($user)->get("/entities/en/{$entity->id}/edit")->assertNotFound();
    $this->actingAs($user)->patch("/entities/en/{$entity->id}", ['name' => 'X'])->assertNotFound();
    $this->actingAs($user)->patch("/entities/en/{$entity->id}/approved", ['is_approved' => true])->assertNotFound();
    $this->actingAs($user)->get("/entities/en/{$entity->id}/sentences")->assertNotFound();
    $this->actingAs($user)->post("/entities/en/{$entity->id}/sentences", ['content' => 'One.'])->assertNotFound();
    $this->actingAs($user)->post("/entities/en/{$entity->id}/sentences/reorder", ['sentence_id' => 1])->assertNotFound();
    $this->actingAs($user)->patch("/entities/en/{$entity->id}/sentences/1", ['content' => 'One.'])->assertNotFound();
    $this->actingAs($user)->delete("/entities/en/{$entity->id}/sentences/1")->assertNotFound();
    $this->actingAs($user)->get('/entities/en/create')->assertNotFound();
    // The pre-existing browse redirect is method-agnostic, so a stray
    // mutation to /entities/{lang} follows it to /works/entities instead
    // of 404ing — unchanged by ADR 0073.
    $this->actingAs($user)->post('/entities/en', ['name' => 'X'])->assertRedirect('/works/entities');
});

test('store rejects a non-text file', function () {
    createLanguages();
    $work = createWork();
    $languageId = Language::query()->where('code', 'en')->value('id');

    $bad = UploadedFile::fake()->create('image.png', 10, 'image/png');

    $this->actingAs(approvedUser())
        ->post("/works/{$work->id}/entities", [
            'language_id' => $languageId,
            'name' => 'Bad File',
            'file' => $bad,
        ])
        ->assertSessionHasErrors('file');
});

test('store clones derivations when the uploaded file is an exact copy', function () {
    Storage::fake('local');
    Queue::fake();
    makeLanguage('en');
    $work = createWork();

    $path = 'entities/en/original.txt';
    Storage::disk('local')->put($path, "One. Two. Three.\nFour.");
    $existing = createEntity('en', $work, [
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
    $languageId = Language::query()->where('code', 'en')->value('id');

    $response = $this->actingAs($user)
        ->post("/works/{$work->id}/entities", [
            'language_id' => $languageId,
            'name' => 'Duplicate Upload',
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

    $response->assertRedirect("/works/{$work->id}/entities/{$clone->id}")
        ->assertSessionHas('status');
});

test('store survives an embedding-service outage and queues the pipeline', function () {
    Storage::fake('local');
    Queue::fake();
    createLanguages();
    $work = createWork();
    $languageId = Language::query()->where('code', 'en')->value('id');

    Http::fake(fn () => Http::response('bad gateway', 502));

    $file = UploadedFile::fake()->createWithContent('text.txt', 'Some unique content.');

    $response = $this->actingAs(approvedUser())
        ->post("/works/{$work->id}/entities", [
            'language_id' => $languageId,
            'name' => 'No Service',
            'file' => $file,
        ]);

    $entity = Entity::query()->where('name', 'No Service')->first();
    expect($entity)->not->toBeNull()
        ->and($entity->signature)->toBeNull()
        ->and($entity->file_hash)->not->toBeNull();

    Queue::assertPushed(ProcessEntityFile::class);

    $response->assertRedirect("/works/{$work->id}/entities/{$entity->id}");
});

test('user cannot read a restricted entity without a grant', function () {
    makeLanguage('en');
    $entity = createEntity('en', null, ['name' => 'Secret', 'is_restricted' => true]);

    $this->actingAs(approvedUser())
        ->get("/works/{$entity->work_id}/entities/{$entity->id}")
        ->assertForbidden();
});

test('user can read a restricted entity they have a grant for', function () {
    makeLanguage('en');
    $entity = createEntity('en', null, ['name' => 'Secret', 'is_restricted' => true]);
    $user = approvedUser();
    $entity->grantedUsers()->attach($user->id);

    $this->actingAs($user)
        ->get("/works/{$entity->work_id}/entities/{$entity->id}")
        ->assertOk();
});

test('user can read a public entity', function () {
    makeLanguage('en');
    $entity = createEntity('en', null, ['name' => 'Open', 'is_restricted' => false]);

    $this->actingAs(approvedUser())
        ->get("/works/{$entity->work_id}/entities/{$entity->id}")
        ->assertOk();
});

test('admin can read any restricted entity', function () {
    makeLanguage('en');
    $entity = createEntity('en', null, ['name' => 'Secret', 'is_restricted' => true]);

    $admin = User::factory()->create(['is_approved' => true, 'role' => 'admin']);

    $this->actingAs($admin)
        ->get("/works/{$entity->work_id}/entities/{$entity->id}")
        ->assertOk();
});
