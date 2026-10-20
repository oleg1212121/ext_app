<?php

use App\Models\Form;
use App\Models\Language;
use App\Models\User;
use App\Models\Word;
use App\Models\WordAiFetch;
use App\Models\WordClass;
use App\Support\WordLookupKey;

/**
 * The popup's on-open re-resolution (popup-ai-enrich ticket 07): the
 * missing-mode popup resolves its RAW clicked surface server-side before
 * rendering the missing card, so a word a previous adopt-on-ask minted
 * (persisted, but never linked into the page's entity_words map) re-links
 * to the page and opens the full dictionary view — with ai_asked grey —
 * after any reload. Helper names differ from the enrich suites' on purpose:
 * parallel workers may load all of them into one process.
 */
function createLookupFixture(): array
{
    createWordClasses();

    return ['user' => approvedUser()];
}

function createLookupWord(string $surface, string $classSlug, string $languageCode = 'en'): Word
{
    return createWord($languageCode, $surface, $classSlug, [
        'l_word' => WordLookupKey::normalize($surface),
    ]);
}

it('resolves a properly classed row over the unknown placeholder', function () {
    $fixture = createLookupFixture();

    // The adopt-on-ask mint (unknown class) and a later properly classed
    // import row share the folded key: the classed row wins.
    $minted = createLookupWord('bank', 'unknown');
    $classed = createLookupWord('bank', 'noun');

    $response = $this->actingAs($fixture['user'])
        ->postJson('/words/lookup', ['surface' => 'Bank', 'language_code' => 'en']);

    $response->assertOk();
    expect($response->json('data.word_id'))->toBe($classed->id);
    expect($minted->id)->not->toBe($classed->id);
});

it('resolves a surface minted by a previous adopt-on-ask — the asked-once word stays found', function () {
    $fixture = createLookupFixture();

    // The report's exact shape (verified 2026-10-20): the mint carries
    // source='ai' content and a succeeded ledger row, but the page's word
    // map never learned it — after a reload the popup must resolve it, not
    // re-offer the ask.
    $minted = createLookupWord("He'd", 'unknown');
    WordAiFetch::query()->create([
        'word_id' => $minted->id,
        'status' => WordAiFetch::STATUS_SUCCEEDED,
        'provider' => 'openrouter',
        'model' => 'google/gemini-3-flash-preview',
        'attempts' => 1,
        'asked_at' => now(),
        'succeeded_at' => now(),
    ]);

    $response = $this->actingAs($fixture['user'])
        ->postJson('/words/lookup', ['surface' => "HE'D", 'language_code' => 'en']);

    $response->assertOk();
    expect($response->json('data.word_id'))->toBe($minted->id);
});

it('resolves case/ё/stress surface variants onto the keyed row', function () {
    $fixture = createLookupFixture();

    $homeland = createLookupWord('дом', 'noun', 'ru');
    $yomorok = createLookupWord('ёморок', 'noun', 'ru');

    $stressed = $this->actingAs($fixture['user'])
        ->postJson('/words/lookup', ['surface' => 'до́м', 'language_code' => 'ru']);
    $stressed->assertOk();
    expect($stressed->json('data.word_id'))->toBe($homeland->id);

    $yospelled = $this->actingAs($fixture['user'])
        ->postJson('/words/lookup', ['surface' => 'Ёморок', 'language_code' => 'ru']);
    $yospelled->assertOk();
    expect($yospelled->json('data.word_id'))->toBe($yomorok->id);
});

it('answers a null word_id for a genuinely unknown surface', function () {
    $fixture = createLookupFixture();
    createLookupWord('bank', 'noun');

    $response = $this->actingAs($fixture['user'])
        ->postJson('/words/lookup', ['surface' => 'flurbify', 'language_code' => 'en']);

    $response->assertOk();
    expect($response->json('data.word_id'))->toBeNull();
    expect(Word::query()->where('l_word', 'flurbify')->exists())->toBeFalse();
});

it('never creates the unknown class on the read path — a language without the row falls back to the first candidate', function () {
    $fixture = createLookupFixture();

    $classed = createLookupWord('bank', 'noun');

    // Simulate the language whose placeholder row was never minted: the
    // importer seeders all create it, but the read contract must not depend
    // on that (no write may ride on a lookup).
    $unknownId = WordClass::query()
        ->where('language_id', Language::query()->where('code', 'en')->value('id'))
        ->where('slug', 'unknown')
        ->value('id');
    WordClass::query()->whereKey($unknownId)->delete();

    $response = $this->actingAs($fixture['user'])
        ->postJson('/words/lookup', ['surface' => 'bank', 'language_code' => 'en']);

    $response->assertOk();
    expect($response->json('data.word_id'))->toBe($classed->id);
    expect(WordClass::query()->whereKey($unknownId)->exists())->toBeFalse();
    // The lookup's one job is resolving — it mints neither a class nor a
    // word row nor a lemma form.
    expect(Word::query()->count())->toBe(1);
    expect(Form::query()->count())->toBe(0);
});

it('validates the lookup payload: required fields, real language, non-empty key, bounded surface', function () {
    $fixture = createLookupFixture();

    $this->postJson('/words/lookup', ['surface' => 'bank', 'language_code' => 'en'])
        ->assertUnauthorized();

    $this->actingAs($fixture['user'])
        ->postJson('/words/lookup', ['surface' => 'bank'])
        ->assertJsonValidationErrors(['language_code']);

    $this->actingAs($fixture['user'])
        ->postJson('/words/lookup', ['language_code' => 'en'])
        ->assertJsonValidationErrors(['surface']);

    $this->actingAs($fixture['user'])
        ->postJson('/words/lookup', ['surface' => 'bank', 'language_code' => 'zz'])
        ->assertJsonValidationErrors(['language_code']);

    // A marks-only surface has no lookup key — the same rejection the
    // adoption path applies before anything could resolve.
    $this->actingAs($fixture['user'])
        ->postJson('/words/lookup', ['surface' => '́', 'language_code' => 'ru'])
        ->assertJsonValidationErrors(['surface']);

    $this->actingAs($fixture['user'])
        ->postJson('/words/lookup', ['surface' => str_repeat('a', 257), 'language_code' => 'en'])
        ->assertJsonValidationErrors(['surface']);

    expect(Word::query()->where('l_word', 'bank')->exists())->toBeFalse();
});

it('diverts an unapproved user to pending-approval — the endpoint never runs', function () {
    createLookupFixture();

    $unapproved = User::factory()->unapproved()->create();

    // EnsureUserIsApproved's contract: a redirect to the pending-approval
    // page, not a JSON 403 — the lookup never executes either way.
    $this->actingAs($unapproved)
        ->postJson('/words/lookup', ['surface' => 'bank', 'language_code' => 'en'])
        ->assertRedirect(route('pending-approval'));

    expect(Word::query()->where('l_word', 'bank')->exists())->toBeFalse();
});
