<?php

use App\Models\Entity;
use App\Models\EntitySentence;
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
            ->has('entities', 1)
            ->where('entities.0.id', $entity->id)
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
            ->where('entities.0.id', $public->id)
            ->missing('entities.1'));

    DB::table('entity_user')->insert(['entity_id' => $restricted->id, 'user_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('crossword'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->has('entities', 2));
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

    // Selected words become learning for the user.
    expect(UserWord::query()->where('user_id', $user->id)->where('status', 'learning')->count())->toBeGreaterThan(0);
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

it('excludes solved and known words from generation', function () {
    $user = User::factory()->create();
    $entity = seedCrosswordEntity();
    $words = seedDictionary(['the', 'cat', 'sat', 'dog', 'ran']);

    $this->actingAs($user)
        ->post(route('crossword.generate'), ['entity_id' => $entity->id, 'level' => 0])
        ->assertOk();

    UserWord::query()->where('user_id', $user->id)->update(['status' => 'solved']);

    $response = $this->actingAs($user)
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

it('marks learning words solved on completion', function () {
    $user = User::factory()->create();
    $word = createWord('en', 'complete', 'noun');
    UserWord::query()->create(['user_id' => $user->id, 'word_id' => $word->id, 'status' => 'learning']);
    $knownWord = createWord('en', 'known', 'noun');
    UserWord::query()->create(['user_id' => $user->id, 'word_id' => $knownWord->id, 'status' => 'known']);

    $this->actingAs($user)
        ->post(route('crossword.complete'), ['word_ids' => [$word->id, $knownWord->id]])
        ->assertOk()
        ->assertJson(['saved' => true]);

    expect(UserWord::query()->where('user_id', $user->id)->where('word_id', $word->id)->value('status'))->toBe('solved');
    expect(UserWord::query()->where('user_id', $user->id)->where('word_id', $knownWord->id)->value('status'))->toBe('known');
});

it('marks a word known from the right panel', function () {
    $user = User::factory()->create();
    $word = createWord('en', 'perceive', 'verb');

    $this->actingAs($user)
        ->post(route('crossword.know'), ['word_id' => $word->id])
        ->assertOk()
        ->assertJson(['saved' => true]);

    expect(UserWord::query()->where('user_id', $user->id)->where('word_id', $word->id)->value('status'))->toBe('known');
});

it('validates generate input', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('crossword.generate'), ['entity_id' => 999999, 'level' => 99])
        ->assertStatus(302);
});
