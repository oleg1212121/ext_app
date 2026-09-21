<?php

use App\Classes\EntityTextHasher;
use App\Jobs\ComputeEntityTextHash;
use App\Jobs\FinalizeEntityDerivations;
use App\Models\Entity;
use App\Models\EntitySentence;
use App\Models\SentenceType;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    createLanguages();
    Storage::fake('local');
});

function hashFixtureEntity(string $name = 'Hashed'): Entity
{
    $type = SentenceType::firstOrCreate(['name' => 'sentence']);

    $entity = createEntity('en', null, ['name' => $name]);

    foreach (['Alpha.', 'Beta.'] as $index => $content) {
        EntitySentence::create([
            'entity_id' => $entity->id,
            'sentence_type_id' => $type->id,
            'content' => $content,
            'order' => ($index + 1) * 1024,
        ]);
    }

    return $entity->refresh();
}

test('sentence create, update and delete bump sentences_updated_at', function () {
    $entity = hashFixtureEntity();
    $type = SentenceType::firstWhere('name', 'sentence');
    expect($entity->sentences_updated_at)->not->toBeNull();

    $baseline = $entity->sentences_updated_at;

    $this->travel(1)->minutes();

    $entity->sentences()->create([
        'sentence_type_id' => $type->id,
        'content' => 'Gamma.',
        'order' => 3072,
    ]);
    expect($entity->refresh()->sentences_updated_at->gt($baseline))->toBeTrue();

    $afterCreate = $entity->sentences_updated_at;
    $this->travel(1)->minutes();

    $entity->sentences()->first()->update(['content' => 'Alpha changed.']);
    expect($entity->refresh()->sentences_updated_at->gt($afterCreate))->toBeTrue();

    $afterUpdate = $entity->sentences_updated_at;
    $this->travel(1)->minutes();

    $entity->sentences()->orderBy('order')->first()->delete();
    expect($entity->refresh()->sentences_updated_at->gt($afterUpdate))->toBeTrue();
});

test('reordering sentences through the editor bumps sentences_updated_at', function () {
    $entity = hashFixtureEntity();
    $entity->forceFill(['is_restricted' => false])->save();

    $baseline = $entity->sentences_updated_at;
    $this->travel(1)->minutes();

    $first = $entity->sentences()->orderBy('order')->first();
    $second = $entity->sentences()->orderBy('order')->skip(1)->first();

    $user = User::factory()->create(['is_approved' => true]);

    $this->actingAs($user)
        ->post("/entities/en/{$entity->id}/sentences/reorder", [
            'sentence_id' => $second->id,
            'after_sentence_id' => 0,
        ])
        ->assertOk();

    expect($entity->refresh()->sentences_updated_at->gt($baseline))->toBeTrue();
});

test('the refresh command dispatches unique rehash jobs for stale entities only', function () {
    $stale = hashFixtureEntity('Stale');
    $fresh = hashFixtureEntity('Fresh');
    $fresh->forceFill([
        'text_hash' => (new EntityTextHasher)->hash($fresh),
        'text_hashed_at' => now(),
        'sentences_updated_at' => now(),
    ])->save();

    Queue::fake();

    $this->artisan('entities:refresh-text-hashes', ['--limit' => 10])
        ->expectsOutputToContain('1 text-hash jobs.')
        ->assertSuccessful();

    Queue::assertPushed(ComputeEntityTextHash::class, 1);

    $this->artisan('entities:refresh-text-hashes', ['--limit' => 10, '--dry-run' => true])
        ->assertSuccessful();

    Queue::assertPushed(ComputeEntityTextHash::class, 1);
});

test('the rehash job recomputes a stale hash and skips a fresh one', function () {
    $hasher = new EntityTextHasher;
    $entity = hashFixtureEntity();
    expect($entity->text_hash)->toBeNull();

    (new ComputeEntityTextHash($entity->id))->handle(new EntityTextHasher);
    $entity->refresh();

    $expected = $hasher->hash($entity);
    expect($entity->text_hash)->toBe($expected)
        ->and($entity->text_hashed_at)->not->toBeNull();

    // Running again with a fresh hash is a no-op (job re-checks staleness).
    $entity->forceFill(['text_hash' => 'corrupted'])->save();
    $entity->forceFill([
        'text_hashed_at' => now(),
        'sentences_updated_at' => now(),
    ])->save();

    (new ComputeEntityTextHash($entity->id))->handle(new EntityTextHasher);

    expect($entity->refresh()->text_hash)->toBe('corrupted');
});

test('FinalizeEntityDerivations computes the hash and embeds when no exact copy exists', function () {
    $entity = hashFixtureEntity('Lone');
    $path = 'entities/en/lone.txt';
    Storage::disk('local')->put($path, 'Alpha. Beta.');

    Http::fake([
        '*/embed' => Http::response(['vector' => [0.1, 0.2, 0.3]]),
        '*' => Http::response(['error' => 'unexpected'], 500),
    ]);

    (new FinalizeEntityDerivations($entity->id, $path))->handle(new EntityTextHasher);
    $entity->refresh();

    expect($entity->text_hash)->toBe((new EntityTextHasher)->hash($entity))
        ->and($entity->signature)->toBe(json_encode([0.1, 0.2, 0.3]));
});

test('FinalizeEntityDerivations copies signature and word statistics from an exact copy', function () {
    $source = hashFixtureEntity('Source');
    $source->update([
        'signature' => json_encode([0.7, 0.7, 0.7]),
        'text_hash' => (new EntityTextHasher)->hash($source),
    ]);
    $source->entityWords()->create(['word_id' => null, 'l_word' => 'alpha', 'token' => 'alpha', 'count' => 1]);
    $source->update(['words_indexed_at' => now()]);

    // A second entity with identical sentence content: same text hash.
    $type = SentenceType::firstWhere('name', 'sentence');
    $target = createEntity('en', null, ['name' => 'Target']);
    foreach ($source->sentences()->orderBy('order')->get() as $sentence) {
        EntitySentence::create([
            'entity_id' => $target->id,
            'sentence_type_id' => $type->id,
            'content' => $sentence->content,
            'order' => $sentence->order,
        ]);
    }

    $path = 'entities/en/target.txt';
    Storage::disk('local')->put($path, 'Alpha. Beta.');

    Http::fake([
        '*/embed' => Http::response(['error' => 'must not embed'], 500),
        '*' => Http::response(['error' => 'unexpected'], 500),
    ]);

    (new FinalizeEntityDerivations($target->id, $path))->handle(new EntityTextHasher);
    $target->refresh();

    expect($target->text_hash)->toBe($source->text_hash)
        ->and($target->signature)->toBe($source->signature)
        ->and($target->entityWords()->count())->toBe(1)
        ->and($target->words_indexed_at)->not->toBeNull();

    Http::assertSentCount(0);
});
