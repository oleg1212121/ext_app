<?php

use App\Models\Entity;
use App\Models\EntitySentence;
use App\Models\SentenceType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

if (! function_exists('approvedUser')) {
    function approvedUser(): User
    {
        return User::factory()->create(['is_approved' => true]);
    }
}

if (! function_exists('grantAccess')) {
    function grantAccess(User $user, Entity $entity): void
    {
        $entity->grantedUsers()->attach($user->id);
    }
}

if (! function_exists('buildPng')) {
    /**
     * A genuine PNG built chunk by chunk (CRCs correct by construction), so
     * validation's finfo sniff and getimagesize both accept it.
     */
    function buildPng(int $width, int $height, string $pixelByte): string
    {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        $raw = '';

        for ($row = 0; $row < $height; $row++) {
            $raw .= "\x00".str_repeat($pixelByte, $width * 3);
        }

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('N2C5', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', (string) gzcompress($raw))
            .$chunk('IEND', '');
    }
}

if (! function_exists('pngUpload')) {
    function pngUpload(string $name = 'illustration.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, buildPng(1, 1, "\xff"));
    }
}

if (! function_exists('makeIllustrationType')) {
    function makeIllustrationType(): SentenceType
    {
        return SentenceType::firstOrCreate(
            ['name' => 'illustration'],
            ['description' => 'An inline illustration with an optional caption'],
        );
    }
}

beforeEach(function () {
    createLanguages();
    makeIllustrationType();
    Storage::fake('local');
});

// ─── upload ──────────────────────────────────────────────────────────────────

it('stores an illustration sentence with its image', function () {
    $entity = createEntity('en');
    $user = approvedUser();
    grantAccess($user, $entity);

    $response = $this->actingAs($user)->post("/works/{$entity->work_id}/entities/{$entity->id}/sentences", [
        'content' => 'The lighthouse',
        'sentence_type_id' => SentenceType::illustrationId(),
        'image' => pngUpload(),
    ]);

    $response->assertOk();

    $sentence = EntitySentence::query()->where('entity_id', $entity->id)->sole();

    expect($sentence->isIllustration())->toBeTrue()
        ->and($sentence->content)->toBe('The lighthouse')
        ->and($sentence->image_hash)->toBeString()->not->toBeEmpty()
        ->and($sentence->image_width)->toBe(1)
        ->and($sentence->image_height)->toBe(1)
        ->and($sentence->image_mime)->toBe('image/png')
        ->and($sentence->image_path)->toStartWith('illustrations/en/');

    Storage::disk('local')->assertExists($sentence->image_path);

    $payload = $response->json('sentence');

    expect($payload['image']['url'])->toBe(route('illustrations.show', ['sentence' => $sentence->id]))
        ->and($payload['image']['width'])->toBe(1)
        ->and($payload['content'])->toBe('The lighthouse');
});

it('stores an illustration without a caption', function () {
    $entity = createEntity('en');
    $user = approvedUser();

    $this->actingAs($user)->post("/works/{$entity->work_id}/entities/{$entity->id}/sentences", [
        'content' => '',
        'sentence_type_id' => SentenceType::illustrationId(),
        'image' => pngUpload(),
    ])->assertOk();

    expect(EntitySentence::query()->where('entity_id', $entity->id)->sole()->content)->toBe('');
});

it('rejects an illustration without an image file', function () {
    $entity = createEntity('en');
    $user = approvedUser();

    $this->actingAs($user)->postJson("/works/{$entity->work_id}/entities/{$entity->id}/sentences", [
        'content' => 'Caption only',
        'sentence_type_id' => SentenceType::illustrationId(),
    ])->assertStatus(422);
});

it('rejects a non-image file as an illustration', function () {
    $entity = createEntity('en');
    $user = approvedUser();

    $this->actingAs($user)->post("/works/{$entity->work_id}/entities/{$entity->id}/sentences", [
        'content' => '',
        'sentence_type_id' => SentenceType::illustrationId(),
        'image' => UploadedFile::fake()->createWithContent('notes.txt', 'just text'),
    ], ['Accept' => 'application/json'])->assertStatus(422);
});

it('keeps plain sentence rules for non-illustration types', function () {
    $entity = createEntity('en');
    $user = approvedUser();
    $sentenceType = SentenceType::firstOrCreate(['name' => 'sentence'], ['description' => 'A standard sentence']);

    $this->actingAs($user)->postJson("/works/{$entity->work_id}/entities/{$entity->id}/sentences", [
        'content' => '   ',
        'sentence_type_id' => $sentenceType->id,
    ])->assertStatus(422);

    $this->actingAs($user)->post("/works/{$entity->work_id}/entities/{$entity->id}/sentences", [
        'content' => 'A plain sentence.',
        'sentence_type_id' => $sentenceType->id,
    ])->assertOk();

    $sentence = EntitySentence::query()->where('entity_id', $entity->id)->sole();

    expect($sentence->isIllustration())->toBeFalse();
});

// ─── edit ────────────────────────────────────────────────────────────────────

it('updates an illustration caption without touching the image', function () {
    $entity = createEntity('en');
    $user = approvedUser();
    $this->actingAs($user)->post("/works/{$entity->work_id}/entities/{$entity->id}/sentences", [
        'content' => 'Old caption',
        'sentence_type_id' => SentenceType::illustrationId(),
        'image' => pngUpload(),
    ]);

    $sentence = EntitySentence::query()->where('entity_id', $entity->id)->sole();
    $path = $sentence->image_path;

    $this->actingAs($user)->patch("/works/{$entity->work_id}/entities/{$entity->id}/sentences/{$sentence->id}", [
        'content' => '',
        'sentence_type_id' => SentenceType::illustrationId(),
    ])->assertOk();

    $sentence->refresh();

    expect($sentence->content)->toBe('')
        ->and($sentence->image_path)->toBe($path);

    Storage::disk('local')->assertExists($path);
});

it('pins an illustration to its type', function () {
    $entity = createEntity('en');
    $user = approvedUser();
    $this->actingAs($user)->post("/works/{$entity->work_id}/entities/{$entity->id}/sentences", [
        'content' => '',
        'sentence_type_id' => SentenceType::illustrationId(),
        'image' => pngUpload(),
    ]);

    $sentence = EntitySentence::query()->where('entity_id', $entity->id)->sole();
    $plainType = SentenceType::firstOrCreate(['name' => 'sentence'], ['description' => 'x']);

    $this->actingAs($user)->patchJson("/works/{$entity->work_id}/entities/{$entity->id}/sentences/{$sentence->id}", [
        'content' => '',
        'sentence_type_id' => $plainType->id,
    ])->assertStatus(422);
});

it('replaces an illustration image and releases the orphaned file', function () {
    $entity = createEntity('en');
    $user = approvedUser();
    $this->actingAs($user)->post("/works/{$entity->work_id}/entities/{$entity->id}/sentences", [
        'content' => '',
        'sentence_type_id' => SentenceType::illustrationId(),
        'image' => pngUpload('first.png'),
    ]);

    $sentence = EntitySentence::query()->where('entity_id', $entity->id)->sole();
    $oldPath = $sentence->image_path;

    // A visually distinct (2x1) image hashes differently, so the replace
    // writes a new file and orphans the old one.
    $this->actingAs($user)->patch("/works/{$entity->work_id}/entities/{$entity->id}/sentences/{$sentence->id}", [
        'content' => 'New caption',
        'sentence_type_id' => SentenceType::illustrationId(),
        'image' => UploadedFile::fake()->createWithContent('second.png', buildPng(2, 1, "\x40")),
    ])->assertOk();

    $sentence->refresh();

    expect($sentence->image_path)->not->toBe($oldPath)
        ->and($sentence->content)->toBe('New caption');

    Storage::disk('local')->assertMissing($oldPath);
    Storage::disk('local')->assertExists($sentence->image_path);
});

// ─── storage dedupe & deletion ───────────────────────────────────────────────

it('shares one file between identical uploads and deletes it with the last referer', function () {
    $entityA = createEntity('en', null, ['name' => 'A']);
    $entityB = createEntity('en', null, ['name' => 'B']);
    $user = approvedUser();

    foreach ([$entityA, $entityB] as $entity) {
        $this->actingAs($user)->post("/works/{$entity->work_id}/entities/{$entity->id}/sentences", [
            'content' => '',
            'sentence_type_id' => SentenceType::illustrationId(),
            'image' => pngUpload(),
        ])->assertOk();
    }

    [$first, $second] = EntitySentence::query()->whereIn('entity_id', [$entityA->id, $entityB->id])
        ->orderBy('id')->get();

    expect($first->image_path)->toBe($second->image_path);

    // Content-hash naming dedupes: deleting one referer keeps the file.
    $first->delete();
    Storage::disk('local')->assertExists($first->image_path);

    $second->delete();
    Storage::disk('local')->assertMissing($second->image_path);
});

// ─── serving ─────────────────────────────────────────────────────────────────

it('serves an illustration through the access-checked route', function () {
    $entity = createEntity('en', null, ['is_restricted' => true]);
    $grantee = approvedUser();
    $outsider = approvedUser();
    grantAccess($grantee, $entity);

    $this->actingAs($grantee)->post("/works/{$entity->work_id}/entities/{$entity->id}/sentences", [
        'content' => 'Hidden picture',
        'sentence_type_id' => SentenceType::illustrationId(),
        'image' => pngUpload(),
    ]);

    $sentence = EntitySentence::query()->where('entity_id', $entity->id)->sole();

    $this->actingAs($grantee)
        ->get(route('illustrations.show', ['sentence' => $sentence->id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    // Restricted entity: no grant, no picture.
    $this->actingAs($outsider)
        ->get(route('illustrations.show', ['sentence' => $sentence->id]))
        ->assertForbidden();

    // Publishing the entity opens its illustrations to every approved user.
    $entity->update(['is_restricted' => false]);

    $this->actingAs($outsider)
        ->get(route('illustrations.show', ['sentence' => $sentence->id]))
        ->assertOk();
});

it('404s the illustration route for image-less sentences', function () {
    $entity = createEntity('en');
    $user = approvedUser();
    $sentence = EntitySentence::create([
        'entity_id' => $entity->id,
        'content' => 'Plain.',
        'order' => 1,
    ]);

    $this->actingAs($user)
        ->get(route('illustrations.show', ['sentence' => $sentence->id]))
        ->assertNotFound();
});
