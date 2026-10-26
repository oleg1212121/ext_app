<?php

use App\Classes\WordTestService;
use App\Models\Language;
use App\Models\Tag;
use App\Models\User;
use App\Models\UserWord;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const CEFR_LEVELS = ['a1', 'a2', 'b1', 'b2', 'c1', 'c2'];

/** Anchor-range starts per level: every headword's frequency sits inside its band, so the marking frontier stays exact. */
const CEFR_RANGE_STARTS = ['a1' => 1, 'a2' => 1001, 'b1' => 3001, 'b2' => 6001, 'c1' => 10001, 'c2' => 15001];

/**
 * The six CEFR tags (a1-c2) over a small English pool: twenty-two own
 * headwords per level (a retake draws 20 from one band) plus two spellings
 * shared with the previous level (the sampler must claim them at most once,
 * in the lower band), each spelling a noun+verb row pair the sampler must
 * collapse, frequency-ranked inside its band's anchor range. Also an
 * untagged English word nobody draws, and a Russian pool — ru carries no
 * CEFR tags at all.
 *
 * $levels limits how many levels get words attached; the tail tags exist
 * but stay empty (the tested-band-without-words case).
 *
 * @return array<string, Tag> slug => tag
 */
function seedCefrInventory(int $levels = 6): array
{
    $tags = [];
    foreach (CEFR_LEVELS as $slug) {
        $tags[$slug] = Tag::query()->create(['slug' => $slug, 'name' => 'CEFR '.strtoupper($slug)]);
    }

    foreach (CEFR_LEVELS as $index => $slug) {
        if ($index >= $levels) {
            break;
        }

        for ($k = 1; $k <= 22; $k++) {
            attachCefrHeadword($tags[$slug], "{$slug}word{$k}", CEFR_RANGE_STARTS[$slug] + $k);
        }
    }

    for ($i = 1; $i < $levels; $i++) {
        $lower = CEFR_LEVELS[$i - 1];
        $upper = CEFR_LEVELS[$i];
        attachSharedCefrHeadword($tags[$lower], $tags[$upper], "shared{$lower}{$upper}1", CEFR_RANGE_STARTS[$lower] + 997);
        attachSharedCefrHeadword($tags[$lower], $tags[$upper], "shared{$lower}{$upper}2", CEFR_RANGE_STARTS[$lower] + 998);
    }

    createWord('en', 'untagged filler');
    createWord('ru', 'русское', 'noun', ['frequency' => 1]);

    return $tags;
}

/** One headword spelling as a noun+verb row pair, both rows tagged. */
function attachCefrHeadword(Tag $tag, string $lWord, int $frequency): void
{
    $noun = createWord('en', $lWord, 'noun', ['frequency' => $frequency]);
    $verb = createWord('en', $lWord, 'verb', ['frequency' => $frequency + 1]);
    $tag->words()->attach([$noun->id, $verb->id]);
}

/** A spelling present in two levels: both row pairs carry both tags. */
function attachSharedCefrHeadword(Tag $lower, Tag $upper, string $lWord, int $frequency): void
{
    $noun = createWord('en', $lWord, 'noun', ['frequency' => $frequency]);
    $verb = createWord('en', $lWord, 'verb', ['frequency' => $frequency + 1]);
    $lower->words()->attach([$noun->id, $verb->id]);
    $upper->words()->attach([$noun->id, $verb->id]);
}

function enLanguage(): Language
{
    return Language::query()->where('code', 'en')->firstOrFail();
}

function sampleForLanguage(string $code, int $currentLevel = 0): array
{
    $service = app(WordTestService::class);
    $language = Language::query()->where('code', $code)->firstOrFail();
    $sample = $service->sample($language, $currentLevel);

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
    seedCefrInventory();

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
                ->has('sample.words', 60)
                ->has('sample.groups', 6);
            $sample = $page->toArray()['props']['sample'];
        });

    // Flat, shuffled presentation: ids are unique headwords, no band order.
    expect(count($sample['words']))->toBe(60)
        ->and(count(array_unique(array_column($sample['words'], 'id'))))->toBe(60)
        ->and($sample['groups'])->toBe(['a1', 'a2', 'b1', 'b2', 'c1', 'c2']);

    // The server keeps the band layout: six bands of ten drawn words, base
    // 0 for a first test, exactly the ids served to the page.
    $payload = cachedPayload($sample['token']);
    expect($payload['language_id'])->toBe(enLanguage()->id)
        ->and($payload['base'])->toBe(0)
        ->and(array_keys($payload['bands']))->toBe(['a1', 'a2', 'b1', 'b2', 'c1', 'c2'])
        ->and(array_map('count', $payload['bands']))->toBe(['a1' => 10, 'a2' => 10, 'b1' => 10, 'b2' => 10, 'c1' => 10, 'c2' => 10])
        ->and($payload['all_word_ids'])->toEqualCanonicalizing(array_column($sample['words'], 'id'));
});

it('draws one word per headword and only tagged words of the tested language', function () {
    $user = User::factory()->create();
    seedCefrInventory();

    $this->actingAs($user)
        ->get(route('word-test.show'))
        ->assertInertia(fn ($page) => $page->has('sample.words', 60));

    $sample = app(WordTestService::class)->sample(enLanguage());
    $sampledIds = array_column($sample['words'], 'id');

    // The noun+verb rows of a headword collapse to one sampled word, and
    // untagged / other-language words never enter the sample.
    $sampledHeadwords = Word::query()->whereIn('id', $sampledIds)->pluck('l_word')->unique();
    $untaggedId = (int) Word::query()->where('l_word', 'untagged filler')->value('id');
    $ruId = (int) Word::query()->where('l_word', 'русское')->value('id');
    expect($sampledHeadwords)->toHaveCount(60)
        ->and($sampledIds)->not->toContain($untaggedId)
        ->and($sampledIds)->not->toContain($ruId);
});

it('samples adaptively for the stored level and passes it as the level prop', function () {
    $user = User::factory()->create(['vocabulary_level' => 10000]);
    seedCefrInventory();

    $this->actingAs($user)
        ->get(route('word-test.show'))
        ->assertSuccessful()
        ->assertInertia(function ($page) {
            $page->component('WordTest/WordTest')
                ->where('level', 10000)
                ->has('sample.words', 60);

            // A stored 10000 skips a1-c1's bands: b2/c1/c2 only.
            expect($page->toArray()['props']['sample']['groups'])->toBe(['b2', 'c1', 'c2']);
        });
});

it('serves an empty state for a language without CEFR tags', function () {
    $user = User::factory()->create();
    seedCefrInventory();

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

it('redistributes the draw over the tested bands for a stored level', function () {
    $user = User::factory()->create();
    seedCefrInventory();

    // Stored 10000: the bands at or above it are b2/c1/c2, 20 words each;
    // the untested lower bands contribute their floor anchor as the base.
    $sample = app(WordTestService::class)->sample(enLanguage(), 10000);

    expect(count($sample['words']))->toBe(60)
        ->and($sample['groups'])->toBe(['b2', 'c1', 'c2']);

    $payload = cachedPayload($sample['token']);
    expect($payload['base'])->toBe(6000)
        ->and(array_keys($payload['bands']))->toBe(['b2', 'c1', 'c2'])
        ->and(array_map('count', $payload['bands']))->toBe(['b2' => 20, 'c1' => 20, 'c2' => 20])
        ->and(count(array_unique($payload['all_word_ids'])))->toBe(60);
});

it('tests only the top band with its whole quota at the scale top', function () {
    $user = User::factory()->create();
    seedCefrInventory();

    // Stored 20000: c2 alone is tested; the fixture's 24-spelling pool is
    // smaller than the 60-word quota, so the band draws all of it.
    $sample = app(WordTestService::class)->sample(enLanguage(), 20000);

    expect($sample['groups'])->toBe(['c2'])
        ->and(count($sample['words']))->toBe(24);

    $payload = cachedPayload($sample['token']);
    expect($payload['base'])->toBe(15000)
        ->and(array_keys($payload['bands']))->toBe(['c2'])
        ->and(count($payload['bands']['c2']))->toBe(24);
});

it('claims a shared spelling in one band only', function () {
    $user = User::factory()->create();
    seedCefrInventory();

    $sample = app(WordTestService::class)->sample(enLanguage());
    $payload = cachedPayload($sample['token']);

    // 60 drawn ids over 60 distinct spellings — the shared* headwords are
    // tagged in two levels, so a band overlap would show up here.
    $bandOf = [];
    foreach ($payload['bands'] as $slug => $ids) {
        foreach (Word::query()->whereIn('id', $ids)->pluck('l_word') as $lWord) {
            $bandOf[$lWord][] = $slug;
        }
    }

    expect(count($bandOf))->toBe(60);
    expect(collect($bandOf)->filter(fn (array $slugs) => count($slugs) > 1)->all())->toBe([]);
});

it('returns null when the language carries no CEFR tags', function () {
    $user = User::factory()->create();
    seedCefrInventory();

    $ru = Language::query()->where('code', 'ru')->firstOrFail();

    expect(app(WordTestService::class)->sample($ru))->toBeNull();
});

it('returns null when a tested band has no tagged words', function () {
    $user = User::factory()->create();
    seedCefrInventory(levels: 3); // a1, a2, b1 populated — b2/c1/c2 empty

    // Stored 10000 tests b2/c1/c2, none of which has tagged headwords.
    expect(app(WordTestService::class)->sample(enLanguage(), 10000))->toBeNull();
});

it('scores the sample and marks the presumed-known range', function () {
    $user = User::factory()->create();
    seedCefrInventory();
    $enId = enLanguage()->id;

    $sample = sampleForLanguage('en');
    $bands = cachedPayload($sample['token'])['bands'];

    // Full a1 + full a2 (their widths are 1000 + 2000) plus one lucky guess
    // in c2 (a tenth of its 5000 width): the score lands at 3500.
    $known = [...$bands['a1'], ...$bands['a2'], $bands['c2'][0]];

    // The marking frontier is exact: every row at frequency <= 3500.
    $expected = Word::query()->where('language_id', $enId)->where('frequency', '<=', 3500)->pluck('id');

    $this->actingAs($user)
        ->post(route('word-test.submit'), ['token' => $sample['token'], 'known' => $known])
        ->assertOk()
        ->assertJson(['data' => ['score' => 3500, 'level' => 3500, 'marked' => $expected->count()]]);

    // The score persists as the user's vocabulary level.
    expect($user->refresh()->vocabulary_level)->toBe(3500);

    // The lucky guess sits above the score: checked, but never marked.
    $lucky = Word::query()->find($bands['c2'][0]);
    expect((float) $lucky->frequency)->toBeGreaterThan(3500)
        ->and(UserWord::query()->where('user_id', $user->id)->where('word_id', $lucky->id)->exists())->toBeFalse();

    // Exactly the rows at frequency <= score are marked 50 — all word-class
    // rows of a qualifying headword, and nothing else.
    foreach ($expected as $wordId) {
        expect(UserWord::query()->where('user_id', $user->id)->where('word_id', $wordId)->value('familiarity'))->toBe(50);
    }
    expect(UserWord::query()->where('user_id', $user->id)->count())->toBe($expected->count());
});

it('raises existing progress to the baseline but never lowers it', function () {
    $user = User::factory()->create();
    seedCefrInventory();

    $w1 = Word::query()->where('l_word', 'a1word1')->orderBy('id')->first(); // frequency 2
    $w2 = Word::query()->where('l_word', 'a1word2')->orderBy('id')->first(); // frequency 3
    UserWord::query()->create(['user_id' => $user->id, 'word_id' => $w1->id, 'familiarity' => 80]);
    UserWord::query()->create(['user_id' => $user->id, 'word_id' => $w2->id, 'familiarity' => 30]);

    $sample = sampleForLanguage('en');
    $bands = cachedPayload($sample['token'])['bands'];

    // A fully-known a1: its width 1000 is the whole score (base 0).
    $this->actingAs($user)
        ->post(route('word-test.submit'), ['token' => $sample['token'], 'known' => $bands['a1']])
        ->assertOk()
        ->assertJson(['data' => ['score' => 1000]]);

    expect(UserWord::query()->where('user_id', $user->id)->where('word_id', $w1->id)->value('familiarity'))->toBe(80)
        ->and(UserWord::query()->where('user_id', $user->id)->where('word_id', $w2->id)->value('familiarity'))->toBe(50);
});

it('marks nothing when the user knows none of the sample', function () {
    $user = User::factory()->create();
    seedCefrInventory();

    $sample = sampleForLanguage('en');

    $this->actingAs($user)
        ->post(route('word-test.submit'), ['token' => $sample['token'], 'known' => []])
        ->assertOk()
        ->assertJson(['data' => ['score' => 0, 'level' => 0, 'marked' => 0]]);

    expect($user->refresh()->vocabulary_level)->toBe(0)
        ->and(UserWord::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('never lowers the stored level on a weak retake but raises it on a strong one', function () {
    $user = User::factory()->create(['vocabulary_level' => 10000]);
    seedCefrInventory();
    $enId = enLanguage()->id;

    // Weak retake at stored 10000: two of b2's twenty drawn words — the base
    // 6000 plus 400 of b2's width lands at 6400, far below the stored level.
    $sample = sampleForLanguage('en', 10000);
    $bands = cachedPayload($sample['token'])['bands'];
    $weak = [$bands['b2'][0], $bands['b2'][1]];
    $weakMarked = Word::query()->where('language_id', $enId)->where('frequency', '<=', 6400)->count();

    $this->actingAs($user)
        ->post(route('word-test.submit'), ['token' => $sample['token'], 'known' => $weak])
        ->assertOk()
        ->assertJson(['data' => ['score' => 6400, 'level' => 10000, 'marked' => $weakMarked]]);

    // Raise-only: a bad retake never lowers the stored level.
    expect($user->refresh()->vocabulary_level)->toBe(10000);

    // Strong retake: all of b2 + c2 (widths 4000 + 5000 over the base 6000)
    // scores 15000 — above the stored level, so the column raises.
    $sample = sampleForLanguage('en', 10000);
    $bands = cachedPayload($sample['token'])['bands'];

    $this->actingAs($user)
        ->post(route('word-test.submit'), ['token' => $sample['token'], 'known' => [...$bands['b2'], ...$bands['c2']]])
        ->assertOk()
        ->assertJson(['data' => ['score' => 15000, 'level' => 15000]]);

    expect($user->refresh()->vocabulary_level)->toBe(15000);
});

it('rejects a stale or unknown token with 422', function () {
    $user = User::factory()->create();
    seedCefrInventory();

    $this->actingAs($user)
        ->postJson(route('word-test.submit'), ['token' => (string) Str::uuid(), 'known' => []])
        ->assertStatus(422);
});

it('rejects words that were not part of the served sample', function () {
    $user = User::factory()->create();
    seedCefrInventory();

    $sample = sampleForLanguage('en');
    $foreignId = (int) Word::query()->where('l_word', 'untagged filler')->value('id');

    $this->actingAs($user)
        ->postJson(route('word-test.submit'), ['token' => $sample['token'], 'known' => [$foreignId]])
        ->assertStatus(422);
});
