<?php

use App\Models\EnEntity;
use App\Models\EnRuEntityMatch;
use App\Models\EnRuMeaningMatch;
use App\Models\RuEntity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function restrictedEditorWorld(): array
{
    $en = EnEntity::create([
        'name' => 'Restricted EN',
        'is_restricted' => true,
    ]);

    $ru = RuEntity::create([
        'name' => 'Restricted RU',
        'is_restricted' => true,
    ]);

    $match = EnRuEntityMatch::create([
        'en_entity_id' => $en->id,
        'ru_entity_id' => $ru->id,
        'status' => 'completed',
    ]);

    $row = EnRuMeaningMatch::create([
        'en_ru_entity_match_id' => $match->id,
        'order' => 1,
        'similarity' => 0.42,
        'alignment_chunk' => 0,
    ]);

    return compact('en', 'ru', 'match', 'row');
}

test('non-granted user is forbidden from every editor read and mutation endpoint', function (Closure $case) {
    $world = restrictedEditorWorld();

    $user = User::factory()->create();

    [$method, $uri, $payload] = $case($world);

    $response = actingAs($user)->{$method}($uri, $payload);

    $response->assertForbidden();
})->with([
    'rows' => fn (array $w) => ['getJson', "/alignments/{$w['match']->id}/rows?page=1&per_page=25", []],
    'unmatched' => fn (array $w) => ['getJson', "/alignments/{$w['match']->id}/unmatched?lang=en&page=1", []],
    'needs-review' => fn (array $w) => ['getJson', "/alignments/{$w['match']->id}/needs-review?page=1", []],
    'storeRow' => fn (array $w) => ['postJson', "/alignments/{$w['match']->id}/rows", ['after_row_id' => null]],
    'approveRow' => fn (array $w) => ['postJson', "/alignments/{$w['match']->id}/rows/{$w['row']->id}/approve", []],
    'storeSentence' => fn (array $w) => ['postJson', "/alignments/{$w['match']->id}/sentences", [
        'lang' => 'en',
        'meaning_match_id' => $w['row']->id,
        'content' => 'A new sentence.',
    ]],
]);

test('granted user may read and mutate a restricted editor match after both-side grant', function () {
    $world = restrictedEditorWorld();

    $user = User::factory()->create();

    // Granted on both sides → access is restored.
    $world['en']->grantedUsers()->attach($user->id);
    $world['ru']->grantedUsers()->attach($user->id);

    // Reads are allowed.
    actingAs($user)
        ->getJson("/alignments/{$world['match']->id}/rows?page=1&per_page=25")
        ->assertOk();

    actingAs($user)
        ->getJson("/alignments/{$world['match']->id}/unmatched?lang=en&page=1")
        ->assertOk();

    actingAs($user)
        ->getJson("/alignments/{$world['match']->id}/needs-review?page=1")
        ->assertOk();

    // The approve mutation is allowed and flips similarity to 1.0.
    actingAs($user)
        ->postJson("/alignments/{$world['match']->id}/rows/{$world['row']->id}/approve")
        ->assertOk()
        ->assertJsonPath('rows.0.similarity', 1);
});
