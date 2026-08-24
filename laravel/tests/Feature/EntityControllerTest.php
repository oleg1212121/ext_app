<?php

use App\Jobs\ProcessEntityFile;
use App\Models\EnEntity;
use App\Models\Language;
use App\Models\RuEntity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

    $file = UploadedFile::fake()->create('text.txt', 20, 'text/plain');

    $response = $this->actingAs(approvedUser())
        ->post('/entities/en', ['name' => 'With File', 'file' => $file]);

    $entity = EnEntity::query()->where('name', 'With File')->firstOrFail();
    expect($entity->file_path)->not->toBeNull();
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

test('show page 404s for an unknown entity', function () {
    makeLanguage('en');

    $this->actingAs(approvedUser())
        ->get('/entities/en/999999')
        ->assertNotFound();
});
