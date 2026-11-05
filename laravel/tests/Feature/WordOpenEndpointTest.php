<?php

use App\Models\User;
use App\Models\UserWord;
use App\Models\UserWordEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The popup-open penalty endpoint (word-popup-overhaul ticket 01): every
 * popup open of a dictionary-mapped word costs −5 familiarity, every time —
 * the user_word_event ledger's once-per-row rule belongs to the
 * sentence-reveal read credits alone. Words in the user's native language
 * cost nothing; an unset native language excludes nothing. Helpers come
 * from Pest.php (approvedUser, nativeRuUser, createWord) — file-local
 * helpers here would collide with the other word suites in parallel
 * workers, the WordLookupEndpointTest naming precedent.
 */
it('penalizes every popup open by five — repeats apply independently', function () {
    // The factory seeds native English — a ru native keeps the en word
    // outside the exclusion.
    $user = nativeRuUser();
    $cat = createWord('en', 'cat', 'noun');
    UserWord::create(['user_id' => $user->id, 'word_id' => $cat->id, 'familiarity' => 100]);

    $open = fn () => $this->actingAs($user)
        ->postJson(route('words.opens'), ['word_ids' => [$cat->id]]);

    expect($open()->assertOk()->json('data.familiarity'))->toBe([$cat->id => 95]);
    expect($open()->assertOk()->json('data.familiarity'))->toBe([$cat->id => 90]);
    expect(UserWord::query()
        ->where('user_id', $user->id)
        ->where('word_id', $cat->id)
        ->value('familiarity'))->toBe(90);
    // Opens never touch the ledger — the once-per-row rule is the read
    // credits' alone.
    expect(UserWordEvent::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('costs nothing for a word in the user’s native language — score unchanged', function () {
    $user = nativeRuUser();
    $dom = createWord('ru', 'дом', 'noun');
    UserWord::create(['user_id' => $user->id, 'word_id' => $dom->id, 'familiarity' => 40]);

    $response = $this->actingAs($user)
        ->postJson(route('words.opens'), ['word_ids' => [$dom->id]])
        ->assertOk();

    // The response still covers the referenced word (the client recolors
    // from the response alone) — with its unchanged reading.
    expect($response->json('data.familiarity'))->toBe([$dom->id => 40]);
    expect(UserWord::query()
        ->where('user_id', $user->id)
        ->where('word_id', $dom->id)
        ->value('familiarity'))->toBe(40);
});

it('covers a native-language word with no row — null reading, nothing created', function () {
    $user = nativeRuUser();
    $kot = createWord('ru', 'кот', 'noun');

    $response = $this->actingAs($user)
        ->postJson(route('words.opens'), ['word_ids' => [$kot->id]])
        ->assertOk();

    expect($response->json('data.familiarity.'.$kot->id))->toBeNull();
    expect(UserWord::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('filters per word — a batch penalizes the learning word and leaves the native word alone', function () {
    $user = nativeRuUser();
    $dom = createWord('ru', 'дом', 'noun');
    $cat = createWord('en', 'cat', 'noun');
    UserWord::create(['user_id' => $user->id, 'word_id' => $dom->id, 'familiarity' => 40]);
    UserWord::create(['user_id' => $user->id, 'word_id' => $cat->id, 'familiarity' => 10]);

    $response = $this->actingAs($user)
        ->postJson(route('words.opens'), ['word_ids' => [$dom->id, $cat->id]])
        ->assertOk();

    expect($response->json('data.familiarity.'.$dom->id))->toBe(40)
        ->and($response->json('data.familiarity.'.$cat->id))->toBe(5);
});

it('creates the first row at zero — the penalty clamps there, never negative', function () {
    $user = nativeRuUser();
    $cat = createWord('en', 'cat', 'noun');

    $first = $this->actingAs($user)
        ->postJson(route('words.opens'), ['word_ids' => [$cat->id]])
        ->assertOk();
    expect($first->json('data.familiarity'))->toBe([$cat->id => 0]);

    // Repeat opens keep clamping at the floor.
    $second = $this->actingAs($user)
        ->postJson(route('words.opens'), ['word_ids' => [$cat->id]])
        ->assertOk();
    expect($second->json('data.familiarity'))->toBe([$cat->id => 0]);

    expect(UserWord::query()
        ->where('user_id', $user->id)
        ->where('word_id', $cat->id)
        ->value('familiarity'))->toBe(0);
});

it('applies the penalty when no native language is set — nothing is provably native', function () {
    $user = User::factory()->create();
    // The factory seeds a settings row (native English) — the unset case
    // deletes it: nativeLanguage() resolves null and nothing is excluded.
    $user->settings()->delete();
    $dom = createWord('ru', 'дом', 'noun');
    UserWord::create(['user_id' => $user->id, 'word_id' => $dom->id, 'familiarity' => 10]);

    $response = $this->actingAs($user)
        ->postJson(route('words.opens'), ['word_ids' => [$dom->id]])
        ->assertOk();

    expect($response->json('data.familiarity'))->toBe([$dom->id => 5]);
});

it('validates the payload: a bounded word-ids list of real words', function () {
    $user = approvedUser();
    $cat = createWord('en', 'cat', 'noun');

    $this->postJson(route('words.opens'), ['word_ids' => [$cat->id]])
        ->assertUnauthorized();

    $this->actingAs($user)
        ->postJson(route('words.opens'), [])
        ->assertJsonValidationErrors(['word_ids']);

    $this->actingAs($user)
        ->postJson(route('words.opens'), ['word_ids' => []])
        ->assertJsonValidationErrors(['word_ids']);

    $this->actingAs($user)
        ->postJson(route('words.opens'), ['word_ids' => array_fill(0, 201, $cat->id)])
        ->assertJsonValidationErrors(['word_ids']);

    $this->actingAs($user)
        ->postJson(route('words.opens'), ['word_ids' => ['cat']])
        ->assertJsonValidationErrors(['word_ids.0']);

    $this->actingAs($user)
        ->postJson(route('words.opens'), ['word_ids' => [99999999]])
        ->assertJsonValidationErrors(['word_ids.0']);

    expect(UserWord::query()->count())->toBe(0);
});

it('diverts an unapproved user to pending-approval — the endpoint never runs', function () {
    $unapproved = User::factory()->unapproved()->create();
    $cat = createWord('en', 'cat', 'noun');

    // EnsureUserIsApproved's contract: a redirect to the pending-approval
    // page, not a JSON 403 — the penalty never executes either way.
    $this->actingAs($unapproved)
        ->postJson(route('words.opens'), ['word_ids' => [$cat->id]])
        ->assertRedirect(route('pending-approval'));

    expect(UserWord::query()->count())->toBe(0);
});
