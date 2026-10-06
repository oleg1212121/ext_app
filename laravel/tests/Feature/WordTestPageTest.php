<?php

use App\Classes\WordTestService;
use App\Models\Language;
use App\Models\User;
use App\Models\UserWord;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const HEADWORD_COUNT = 120;

/**
 * A ranked inventory: HEADWORD_COUNT headwords spread evenly over the
 * 0-20000 rank scale (167, 333, ... 20000), each with a second word-class
 * row the sampler must collapse and the marking must cover — plus an
 * unranked English word and a ranked Russian word the test must ignore.
 */
function seedRankedInventory(): void
{
    for ($i = 1; $i <= HEADWORD_COUNT; $i++) {
        $rank = (int) round($i * 20000 / HEADWORD_COUNT);
        createWord('en', "headword{$i}", 'noun', ['frequency' => $rank]);
        createWord('en', "headword{$i}", 'verb', ['frequency' => $rank]);
    }

    createWord('en', 'unranked filler');
    createWord('ru', 'русское', 'noun', ['frequency' => 1]);
}

function sampleForLanguage(string $code): array
{
    $service = app(WordTestService::class);
    $language = Language::query()->where('code', $code)->firstOrFail();
    $sample = $service->sample($language);

    expect($sample)->not->toBeNull();

    return $sample;
}

function cachedPayload(string $token): array
{
    return Cache::get("word-test:sample:{$token}");
}

it('redirects guests from the word test page', function () {
    $this->get(route('word-test.show'))->assertRedirect(route('login'));
});

it('renders the word test page with a fresh sample for the default language', function () {
    $user = User::factory()->create();
    seedRankedInventory();

    $sample = null;
    $this->actingAs($user)
        ->get(route('word-test.show'))
        ->assertSuccessful()
        ->assertInertia(function ($page) use (&$sample) {
            $page->component('WordTest/WordTest')
                ->has('languages', 2)
                ->where('languages.0.code', 'en')
                ->where('language', 'en')
                ->has('sample.token')
                ->has('sample.words', 50);
            $sample = $page->toArray()['props']['sample'];
        });

    // Flat, shuffled presentation: ids are unique headwords, no rank order.
    expect(count($sample['words']))->toBe(50)
        ->and(count(array_unique(array_column($sample['words'], 'id'))))->toBe(50);

    // The server keeps the bucket layout: 20 buckets whose drawn sizes sum
    // to 50, exactly the ids served to the page.
    $payload = cachedPayload($sample['token']);
    expect($payload['language_id'])->toBe(Language::query()->where('code', 'en')->value('id'))
        ->and(count($payload['buckets']))->toBe(20)
        ->and(array_sum(array_map('count', $payload['buckets'])))->toBe(50)
        ->and($payload['all_word_ids'])->toEqualCanonicalizing(array_column($sample['words'], 'id'));
});

it('draws one word per headword and only ranked words of the tested language', function () {
    $user = User::factory()->create();
    seedRankedInventory();

    $this->actingAs($user)
        ->get(route('word-test.show'))
        ->assertInertia(fn ($page) => $page->has('sample.words', 50));

    $sample = app(WordTestService::class)->sample(Language::query()->where('code', 'en')->firstOrFail());
    $sampledIds = array_column($sample['words'], 'id');

    // The noun+verb rows of a headword collapse to one sampled word, and
    // unranked / other-language words never enter the sample.
    $sampledHeadwords = Word::query()->whereIn('id', $sampledIds)->pluck('l_word')->unique();
    $unrankedId = (int) Word::query()->where('l_word', 'unranked filler')->value('id');
    $ruId = (int) Word::query()->where('l_word', 'русское')->value('id');
    expect($sampledHeadwords)->toHaveCount(50)
        ->and($sampledIds)->not->toContain($unrankedId)
        ->and($sampledIds)->not->toContain($ruId);
});

it('serves an empty state for a language without ranked words', function () {
    $user = User::factory()->create();
    seedRankedInventory();

    $this->actingAs($user)
        ->get(route('word-test.show', ['lang' => 'ru']))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('language', 'ru')
            ->where('sample', null));
});

it('rejects an unknown language code', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('word-test.show', ['lang' => 'zz']))
        ->assertStatus(302);
});

it('scores the sample and marks the presumed-known range', function () {
    $user = User::factory()->create();
    seedRankedInventory();
    $enId = (int) Language::query()->where('code', 'en')->value('id');

    $sample = sampleForLanguage('en');
    $buckets = cachedPayload($sample['token'])['buckets'];

    // Every drawn word of the two most common buckets (ranks <= 2000) plus
    // one lucky guess in the rarest bucket (rank ~19833): the guess lifts
    // its own bucket's credit to 500, so the score lands at 2500.
    $known = [...$buckets[0], ...$buckets[1], $buckets[19][0]];

    $this->actingAs($user)
        ->post(route('word-test.submit'), ['token' => $sample['token'], 'known' => $known])
        ->assertOk()
        ->assertJson(['data' => ['score' => 2500, 'marked' => 30]]);

    // The lucky guess sits above the score: checked, but never marked.
    $lucky = Word::query()->find($buckets[19][0]);
    expect((float) $lucky->frequency)->toBeGreaterThan(2500)
        ->and(UserWord::query()->where('user_id', $user->id)->where('word_id', $lucky->id)->exists())->toBeFalse();

    // Exactly the words at rank <= score are marked 50 — all word-class rows
    // of a qualifying headword, and nothing else.
    $expected = Word::query()->where('language_id', $enId)->where('frequency', '<=', 2500)->pluck('id');
    expect($expected)->toHaveCount(30); // 15 headwords × 2 word-class rows
    foreach ($expected as $wordId) {
        expect(UserWord::query()->where('user_id', $user->id)->where('word_id', $wordId)->value('familiarity'))->toBe(50);
    }
    expect(UserWord::query()->where('user_id', $user->id)->count())->toBe($expected->count());
});

it('raises existing progress to the baseline but never lowers it', function () {
    $user = User::factory()->create();
    seedRankedInventory();

    $w1 = Word::query()->where('l_word', 'headword1')->orderBy('id')->first(); // rank 167
    $w2 = Word::query()->where('l_word', 'headword2')->orderBy('id')->first(); // rank 333
    UserWord::query()->create(['user_id' => $user->id, 'word_id' => $w1->id, 'familiarity' => 80]);
    UserWord::query()->create(['user_id' => $user->id, 'word_id' => $w2->id, 'familiarity' => 30]);

    $sample = sampleForLanguage('en');
    $buckets = cachedPayload($sample['token'])['buckets'];

    $this->actingAs($user)
        ->post(route('word-test.submit'), ['token' => $sample['token'], 'known' => $buckets[0]])
        ->assertOk()
        ->assertJson(['data' => ['score' => 1000]]);

    expect(UserWord::query()->where('user_id', $user->id)->where('word_id', $w1->id)->value('familiarity'))->toBe(80)
        ->and(UserWord::query()->where('user_id', $user->id)->where('word_id', $w2->id)->value('familiarity'))->toBe(50);
});

it('marks nothing when the user knows none of the sample', function () {
    $user = User::factory()->create();
    seedRankedInventory();

    $sample = sampleForLanguage('en');

    $this->actingAs($user)
        ->post(route('word-test.submit'), ['token' => $sample['token'], 'known' => []])
        ->assertOk()
        ->assertJson(['data' => ['score' => 0, 'marked' => 0]]);

    expect(UserWord::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('rejects a stale or unknown token with 422', function () {
    $user = User::factory()->create();
    seedRankedInventory();

    $this->actingAs($user)
        ->postJson(route('word-test.submit'), ['token' => (string) Str::uuid(), 'known' => []])
        ->assertStatus(422);
});

it('rejects words that were not part of the served sample', function () {
    $user = User::factory()->create();
    seedRankedInventory();

    $sample = sampleForLanguage('en');
    $foreignId = (int) Word::query()->where('l_word', 'unranked filler')->value('id');

    $this->actingAs($user)
        ->postJson(route('word-test.submit'), ['token' => $sample['token'], 'known' => [$foreignId]])
        ->assertStatus(422);
});
