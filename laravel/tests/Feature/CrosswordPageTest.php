<?php

use App\Models\Entity;
use App\Models\EntitySentence;
use App\Models\EntityWord;
use App\Models\User;
use App\Models\UserWord;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function seedCrosswordEntity(int $uniqueWords = 5): Entity
{
    $entity = createEntity('en');

    // Enough shared high-frequency words to exceed band cutoffs plus unique tail words.
    EntitySentence::query()->insert(array_map(fn ($i) => [
        'entity_id' => $entity->id,
        'content' => "The cat sat. The dog ran. Word{$i} appears here.",
        'order' => $i * 1024,
        'created_at' => now(),
        'updated_at' => now(),
    ], range(1, $uniqueWords)));

    return $entity;
}

function seedDictionary(array $entries): void
{
    foreach ($entries as $rank => $lWord) {
        $word = createWord('en', $lWord, 'noun');
        $word->update(['frequency' => $rank + 1]);
    }

    // Simulate the import pipeline: index the entity, then link to the dictionary.
    Artisan::call('crossword:index');
    Artisan::call('crossword:link');
}

it('redirects guests from the crossword page', function () {
    $this->get(route('crossword'))->assertRedirect(route('login'));
});

it('renders the crossword page for approved users', function () {
    $user = User::factory()->create();
    $entity = seedCrosswordEntity();

    $this->actingAs($user)
        ->get(route('crossword'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Crossword/Crossword')
            ->has('works', 1)
            ->where('works.0.id', $entity->work_id)
            ->where('works.0.title', 'Test Work')
            ->where('works.0.original_language_code', 'en')
            ->where('works.0.entities.0.id', $entity->id)
            ->where('works.0.entities.0.language_code', 'en')
            ->has('languages', 1)
            ->where('languages.0.code', 'en')
            ->has('levels', 8));
});

it('lists only readable entities on the page', function () {
    $user = User::factory()->create();
    $public = createEntity('en');
    $restricted = createEntity('en', null, ['is_restricted' => true]);

    $this->actingAs($user)
        ->get(route('crossword'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('works.0.entities.0.id', $public->id)
            ->missing('works.1'));

    DB::table('entity_user')->insert(['entity_id' => $restricted->id, 'user_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('crossword'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->has('works', 2)
            ->where('works.1.entities.0.id', $restricted->id));
});

it('groups same-language entities of one work under a single work', function () {
    $user = User::factory()->create();
    $work = createWork();
    createEntity('en', $work, ['name' => 'Alpha']);
    $competing = createEntity('en', $work, ['name' => 'Beta', 'label' => 'Kahn translation']);

    $this->actingAs($user)
        ->get(route('crossword'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->has('works', 1)
            ->where('works.0.id', $work->id)
            ->has('works.0.entities', 2)
            ->where('works.0.entities.0.name', 'Alpha')
            ->where('works.0.entities.1.id', $competing->id)
            ->where('works.0.entities.1.label', 'Kahn translation'));
});

it('offers the languages of readable entities for filtering', function () {
    $user = User::factory()->create();
    $work = createWork();
    createEntity('ru', $work);
    createEntity('en', $work);

    $this->actingAs($user)
        ->get(route('crossword'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->has('languages', 2)
            ->where('languages.0.code', 'en')
            ->where('languages.1.code', 'ru')
            ->where('languages.1.name', 'Russian')
            ->where('works.0.entities.0.language_code', 'en'));
});

it('generates a crossword for a readable entity', function () {
    $user = User::factory()->create();
    $entity = seedCrosswordEntity();
    seedDictionary(['the', 'cat', 'sat', 'dog', 'ran']);

    $response = $this->actingAs($user)
        ->post(route('crossword.generate'), ['entity_id' => $entity->id, 'level' => 0])
        ->assertOk()
        ->assertJsonStructure(['data' => ['crossword' => ['words', 'newGrid', 'dictionary']]]);

    $payload = $response->json('data.crossword');
    expect(count($payload['words']))->toBeGreaterThan(0);
    expect($payload['dictionary'])->toHaveKey('ran');

    // Selected words get a familiarity-0 marker row for the user.
    expect(UserWord::query()->where('user_id', $user->id)->where('familiarity', 0)->count())->toBeGreaterThan(0);
});

it('forbids generating for a restricted entity without a grant', function () {
    $user = User::factory()->create();
    $restricted = seedCrosswordEntity();
    $restricted->update(['is_restricted' => true]);

    $this->actingAs($user)
        ->post(route('crossword.generate'), ['entity_id' => $restricted->id, 'level' => 0])
        ->assertForbidden();
});

it('respects the level band cutoff', function () {
    $user = User::factory()->create();
    $entity = seedCrosswordEntity();
    seedDictionary(['the', 'cat', 'sat', 'dog', 'ran', 'uncommon']);

    // Push the last word beyond the level-0 band (rank > 100).
    Word::query()->where('l_word', 'uncommon')->update(['frequency' => 5000]);

    $response = $this->actingAs($user)
        ->post(route('crossword.generate'), ['entity_id' => $entity->id, 'level' => 0])
        ->assertOk();

    $dictionary = $response->json('data.crossword.dictionary');
    expect($dictionary)->toHaveKey('ran');
    expect($dictionary)->not->toHaveKey('uncommon');
});

it('excludes fully known words but keeps in-progress words in generation', function () {
    $user = User::factory()->create();
    $entity = seedCrosswordEntity();
    $words = seedDictionary(['the', 'cat', 'sat', 'dog', 'ran']);

    $this->actingAs($user)
        ->post(route('crossword.generate'), ['entity_id' => $entity->id, 'level' => 0])
        ->assertOk();

    // In-progress words (below the known threshold) stay eligible.
    UserWord::query()->where('user_id', $user->id)->update(['familiarity' => 50]);

    $this->actingAs($user)
        ->post(route('crossword.generate'), ['entity_id' => $entity->id, 'level' => 0])
        ->assertOk();

    UserWord::query()->where('user_id', $user->id)->update(['familiarity' => 100]);

    $this->actingAs($user)
        ->post(route('crossword.generate'), ['entity_id' => $entity->id, 'level' => 0])
        ->assertStatus(422);
});

it('returns 422 when too few words are available', function () {
    $user = User::factory()->create();
    $entity = seedCrosswordEntity();

    $this->actingAs($user)
        ->post(route('crossword.generate'), ['entity_id' => $entity->id, 'level' => 0])
        ->assertStatus(422);
});

it('reports a stale word list as still building instead of building inline', function () {
    $user = User::factory()->create();
    $entity = seedCrosswordEntity();
    createWord('en', 'cat', 'noun');

    $this->actingAs($user)
        ->post(route('crossword.generate'), ['entity_id' => $entity->id, 'level' => 0])
        ->assertStatus(422)
        ->assertJson(['message' => 'crossword.still_building']);

    // The background job owns the rebuild — generate must not touch it.
    expect(EntityWord::query()->where('entity_id', $entity->id)->count())->toBe(0)
        ->and($entity->refresh()->words_indexed_at)->toBeNull();
});

it('awards a crossword completion bonus that clamps at the known threshold', function () {
    $user = User::factory()->create();
    $word = createWord('en', 'complete', 'noun');
    UserWord::query()->create(['user_id' => $user->id, 'word_id' => $word->id, 'familiarity' => 0]);
    $knownWord = createWord('en', 'known', 'noun');
    UserWord::query()->create(['user_id' => $user->id, 'word_id' => $knownWord->id, 'familiarity' => 100]);
    $unknownWord = createWord('en', 'unknown', 'noun');

    $this->actingAs($user)
        ->post(route('crossword.complete'), ['word_ids' => [$word->id, $knownWord->id, $unknownWord->id]])
        ->assertOk()
        ->assertJson(['saved' => true]);

    expect(UserWord::query()->where('user_id', $user->id)->where('word_id', $word->id)->value('familiarity'))->toBe(5);
    expect(UserWord::query()->where('user_id', $user->id)->where('word_id', $knownWord->id)->value('familiarity'))->toBe(100);
    expect(UserWord::query()->where('user_id', $user->id)->where('word_id', $unknownWord->id)->exists())->toBeFalse();
});

it('awards the completion bonus only once per completion call', function () {
    $user = User::factory()->create();
    $word = createWord('en', 'complete', 'noun');
    UserWord::query()->create(['user_id' => $user->id, 'word_id' => $word->id, 'familiarity' => 98]);

    $this->actingAs($user)
        ->post(route('crossword.complete'), ['word_ids' => [$word->id]])
        ->assertOk();

    expect(UserWord::query()->where('user_id', $user->id)->where('word_id', $word->id)->value('familiarity'))->toBe(100);
});

it('validates generate input', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('crossword.generate'), ['entity_id' => 999999, 'level' => 99])
        ->assertStatus(302);
});
