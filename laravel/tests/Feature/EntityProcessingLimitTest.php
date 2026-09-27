<?php

use App\Classes\EntityTextHasher;
use App\Jobs\GenerateEntitySignature;
use App\Jobs\ProcessEntityFile;
use App\Models\Entity;
use App\Models\EntitySentence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// Guard: anything leaking an HTTP call must hit a fake response instead of
// hanging on the Python service timeout.
beforeEach(function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/embed')) {
            return Http::response(['vector' => [0.1, 0.2, 0.3]], 200);
        }

        return Http::response(['similarities' => [0.0]], 200);
    });

    config([
        'limits.entities_processing_per_user' => 2,
        'limits.alignments_processing_per_user' => 1,
    ]);
});

if (! function_exists('limitUser')) {
    function limitUser(): User
    {
        return User::factory()->create(['is_approved' => true]);
    }
}

if (! function_exists('processingEntityFor')) {
    function processingEntityFor(User $user, string $languageCode = 'en'): Entity
    {
        return createEntity($languageCode, null, [
            'created_by' => $user->id,
            'file_path' => "entities/{$languageCode}/stored.txt",
            'status' => 'processing',
        ]);
    }
}

test('a non-admin at the entity limit is refused on the work store', function () {
    Storage::fake('local');
    Queue::fake();
    $user = limitUser();
    $work = createWork();
    $languages = createLanguages();

    processingEntityFor($user);
    processingEntityFor($user);

    $response = $this->actingAs($user)
        ->from("/works/{$work->id}/entities/create")
        ->post("/works/{$work->id}/entities", [
            'language_id' => $languages['en']->id,
            'name' => 'Third Entity',
            'file' => UploadedFile::fake()->create('third.txt', 5, 'text/plain'),
        ]);

    $response->assertRedirect("/works/{$work->id}/entities/create");
    $response->assertSessionHasErrors('limit');

    expect(Entity::query()->where('name', 'Third Entity')->exists())->toBeFalse()
        ->and(Entity::query()->where('created_by', $user->id)->count())->toBe(2)
        ->and(Queue::pushed(ProcessEntityFile::class))->toHaveCount(0);
});

test('a non-admin at the entity limit is refused on the language-first store', function () {
    Storage::fake('local');
    Queue::fake();
    $user = limitUser();
    createLanguages();

    processingEntityFor($user);
    processingEntityFor($user);

    $response = $this->actingAs($user)
        ->post('/entities/en', [
            'name' => 'Third Entity',
            'new_work_title' => 'Third Work',
            'file' => UploadedFile::fake()->create('third.txt', 5, 'text/plain'),
        ]);

    $response->assertSessionHasErrors('limit');

    expect(Entity::query()->where('name', 'Third Entity')->exists())->toBeFalse();
});

test('completed and failed entities hold no slot', function () {
    Storage::fake('local');
    Queue::fake();
    $user = limitUser();
    $work = createWork();

    createEntity('en', $work, ['created_by' => $user->id, 'status' => 'completed']);
    createEntity('ru', $work, ['created_by' => $user->id, 'file_path' => 'entities/ru/done.txt', 'signature' => json_encode([1.0]), 'status' => 'completed']);
    createEntity('en', $work, ['created_by' => $user->id, 'file_path' => 'entities/en/dead.txt', 'status' => 'failed']);

    $response = $this->actingAs($user)
        ->post("/works/{$work->id}/entities", [
            'language_id' => $work->original_language_id,
            'name' => 'Fourth Entity',
            'file' => UploadedFile::fake()->create('fourth.txt', 5, 'text/plain'),
        ]);

    $response->assertSessionHasNoErrors();

    $entity = Entity::query()->where('name', 'Fourth Entity')->firstOrFail();
    expect($entity->status)->toBe('processing');
    Queue::assertPushed(ProcessEntityFile::class);
});

test('a no-file entity is born completed and needs no slot even at the limit', function () {
    Queue::fake();
    $user = limitUser();
    createLanguages();

    processingEntityFor($user);
    processingEntityFor($user);

    $response = $this->actingAs($user)->post('/entities/en', [
        'name' => 'Metadata Only',
        'new_work_title' => 'Metadata Work',
    ]);

    $response->assertSessionHasNoErrors();

    $entity = Entity::query()->where('name', 'Metadata Only')->firstOrFail();
    expect($entity->status)->toBe('completed')
        ->and($entity->file_path)->toBeNull();
    Queue::assertNothingPushed();
});

test('an admin ignores the entity limit', function () {
    Storage::fake('local');
    Queue::fake();
    $admin = User::factory()->admin()->approved()->create();
    $work = createWork();

    processingEntityFor($admin);
    processingEntityFor($admin);

    $response = $this->actingAs($admin)->post("/works/{$work->id}/entities", [
        'language_id' => $work->original_language_id,
        'name' => 'Admin Third',
        'file' => UploadedFile::fake()->create('third.txt', 5, 'text/plain'),
    ]);

    $response->assertSessionHasNoErrors();

    $entity = Entity::query()->where('name', 'Admin Third')->firstOrFail();
    expect($entity->status)->toBe('processing');
});

test('a clone of a mid-pipeline source consumes a slot and is refused at the limit', function () {
    Storage::fake('local');
    Queue::fake();
    $user = limitUser();
    $work = createWork();
    $languages = createLanguages();

    $content = 'Cloneable chapter text.';
    $tmp = tempnam(sys_get_temp_dir(), 'hash');
    file_put_contents($tmp, $content);
    $fileHash = EntityTextHasher::hashFile($tmp);
    unlink($tmp);

    // Exact-copy source: sentences extracted, signature not yet generated.
    $source = createEntity('en', $work, [
        'created_by' => User::factory()->create()->id,
        'file_hash' => $fileHash,
        'file_path' => 'entities/en/source.txt',
        'status' => 'processing',
    ]);
    EntitySentence::query()->create([
        'entity_id' => $source->id,
        'content' => $content,
        'order' => 1,
    ]);

    processingEntityFor($user);
    processingEntityFor($user);

    $response = $this->actingAs($user)
        ->post("/works/{$work->id}/entities", [
            'language_id' => $languages['en']->id,
            'name' => 'Clone Entity',
            'file' => UploadedFile::fake()->createWithContent('clone.txt', $content),
        ]);

    $response->assertSessionHasErrors('limit');
    expect(Entity::query()->where('name', 'Clone Entity')->exists())->toBeFalse();
});

test('a clone of a mid-pipeline source below the limit is born processing', function () {
    Storage::fake('local');
    Queue::fake();
    $user = limitUser();
    $work = createWork();
    $languages = createLanguages();

    $content = 'Cloneable chapter text.';
    $tmp = tempnam(sys_get_temp_dir(), 'hash');
    file_put_contents($tmp, $content);
    $fileHash = EntityTextHasher::hashFile($tmp);
    unlink($tmp);

    $source = createEntity('en', $work, [
        'created_by' => User::factory()->create()->id,
        'file_hash' => $fileHash,
        'file_path' => 'entities/en/source.txt',
        'status' => 'processing',
    ]);
    EntitySentence::query()->create([
        'entity_id' => $source->id,
        'content' => $content,
        'order' => 1,
    ]);

    $response = $this->actingAs($user)
        ->post("/works/{$work->id}/entities", [
            'language_id' => $languages['en']->id,
            'name' => 'Clone Entity',
            'file' => UploadedFile::fake()->createWithContent('clone.txt', $content),
        ]);

    $response->assertSessionHasNoErrors();

    $clone = Entity::query()->where('name', 'Clone Entity')->firstOrFail();
    expect($clone->status)->toBe('processing')
        ->and($clone->created_by)->toBe($user->id);
    Queue::assertPushed(GenerateEntitySignature::class);
});

test('a finished clone is born completed and needs no slot even at the limit', function () {
    Storage::fake('local');
    Queue::fake();
    $user = limitUser();
    $work = createWork();
    $languages = createLanguages();

    $content = 'Finished source text.';
    $tmp = tempnam(sys_get_temp_dir(), 'hash');
    file_put_contents($tmp, $content);
    $fileHash = EntityTextHasher::hashFile($tmp);
    unlink($tmp);

    createEntity('en', $work, [
        'created_by' => User::factory()->create()->id,
        'file_hash' => $fileHash,
        'signature' => json_encode([1.0, 0.0]),
        'status' => 'completed',
    ]);
    $source = createEntity('en', $work, [
        'created_by' => User::factory()->create()->id,
        'file_hash' => $fileHash,
        'signature' => json_encode([1.0, 0.0]),
        'status' => 'completed',
    ]);
    EntitySentence::query()->create([
        'entity_id' => $source->id,
        'content' => $content,
        'order' => 1,
    ]);

    processingEntityFor($user);
    processingEntityFor($user);

    $response = $this->actingAs($user)
        ->post("/works/{$work->id}/entities", [
            'language_id' => $languages['en']->id,
            'name' => 'Finished Clone',
            'file' => UploadedFile::fake()->createWithContent('clone.txt', $content),
        ]);

    $response->assertSessionHasNoErrors();

    $clone = Entity::query()->where('name', 'Finished Clone')->firstOrFail();
    expect($clone->status)->toBe('completed')
        ->and($clone->signature)->not->toBeNull();
    Queue::assertNothingPushed();
});
