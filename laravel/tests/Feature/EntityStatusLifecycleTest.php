<?php

use App\Classes\EntityTextHasher;
use App\Jobs\FinalizeEntityDerivations;
use App\Jobs\GenerateEntitySignature;
use App\Jobs\ProcessEntityFile;
use App\Jobs\SplitEntityFileSentences;
use App\Models\Entity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => fakePython()->embedding([0.1, 0.2, 0.3]));

if (! function_exists('storedTextEntity')) {
    function storedTextEntity(array $attributes = []): Entity
    {
        Storage::fake('local');

        return createEntity('en', null, [
            'created_by' => User::factory()->create()->id,
            'file_path' => 'entities/en/stored.txt',
            'status' => 'processing',
            ...$attributes,
        ]);
    }
}

test('an entity created without a file is born completed', function () {
    Queue::fake();
    createLanguages();

    $this->actingAs(User::factory()->create())
        ->post('/entities/en', [
            'name' => 'Metadata Only',
            'new_work_title' => 'Metadata Work',
        ]);

    $entity = Entity::query()->where('name', 'Metadata Only')->firstOrFail();
    expect($entity->status)->toBe('completed');
    Queue::assertNothingPushed();
});

test('an entity created with a file is born processing and dispatches the pipeline', function () {
    Storage::fake('local');
    Queue::fake();
    createLanguages();

    $this->actingAs(User::factory()->create())
        ->post('/entities/en', [
            'name' => 'With File',
            'new_work_title' => 'File Work',
            'file' => UploadedFile::fake()->create('text.txt', 5, 'text/plain'),
        ]);

    $entity = Entity::query()->where('name', 'With File')->firstOrFail();
    expect($entity->status)->toBe('processing');
    Queue::assertPushed(ProcessEntityFile::class);
});

test('the finalize stage marks the entity completed with its signature', function () {
    $entity = storedTextEntity();
    Storage::disk('local')->put($entity->file_path, 'Chapter text.');

    (new FinalizeEntityDerivations($entity->id, $entity->file_path))
        ->handle(new EntityTextHasher);

    $entity->refresh();
    expect($entity->status)->toBe('completed')
        ->and($entity->signature)->not->toBeNull();
});

test('a failed finalize marks a signature-less entity failed', function () {
    $entity = storedTextEntity();

    (new SplitEntityFileSentences($entity->id, $entity->file_path))
        ->failed(new RuntimeException('split gave up'));

    expect($entity->refresh()->status)->toBe('failed');
});

test('a failed finalize never marks a signed entity failed', function () {
    $entity = storedTextEntity(['signature' => json_encode([1.0])]);

    (new FinalizeEntityDerivations($entity->id, $entity->file_path))
        ->failed(new RuntimeException('enrichment gave up'));

    expect($entity->refresh()->status)->toBe('processing');
});

test('a failed signature pass marks the entity failed', function () {
    $entity = storedTextEntity();

    (new GenerateEntitySignature($entity->id, $entity->file_path))
        ->failed(new RuntimeException('embed gave up'));

    expect($entity->refresh()->status)->toBe('failed');
});

test('a completed signature pass marks the entity completed', function () {
    $entity = storedTextEntity();
    Storage::disk('local')->put($entity->file_path, 'Chapter text.');

    (new GenerateEntitySignature($entity->id, $entity->file_path))->handle();

    $entity->refresh();
    expect($entity->status)->toBe('completed')
        ->and($entity->signature)->not->toBeNull();
});

test('a failed start stage marks the entity failed', function () {
    $entity = storedTextEntity();

    (new ProcessEntityFile($entity->id, $entity->file_path))
        ->failed(new RuntimeException('queue died'));

    expect($entity->refresh()->status)->toBe('failed');
});

test('re-generating a signature re-enters processing via the sweep', function () {
    Queue::fake();
    $entity = storedTextEntity(['status' => 'failed']);

    $this->artisan('entity:generate-signatures')->assertSuccessful();

    expect($entity->refresh()->status)->toBe('processing');
    Queue::assertPushed(GenerateEntitySignature::class);
});
