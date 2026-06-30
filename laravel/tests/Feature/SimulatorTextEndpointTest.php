<?php

use App\Models\EnEntity;
use App\Models\EnEntitySentence;
use App\Models\EnRuEntityMatch;
use App\Models\EnRuMeaningMatch;
use App\Models\EnSentenceMeaningMatch;
use App\Models\RuEntity;
use App\Models\RuEntitySentence;
use App\Models\RuSentenceMeaningMatch;
use App\Models\SentenceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns paginated alignment rows for en_ru_entity_match_id', function () {
    $user = User::factory()->create();
    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $enEntity = EnEntity::create(['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = RuEntity::create(['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    $en1 = EnEntitySentence::create([
        'en_entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'First EN.',
        'order' => 1,
    ]);
    $ru1 = RuEntitySentence::create([
        'ru_entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'First RU.',
        'order' => 1,
    ]);

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'completed',
    ]);

    $matchRow = EnRuMeaningMatch::create([
        'en_ru_entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 0.95,
        'alignment_chunk' => 0,
    ]);

    EnSentenceMeaningMatch::create([
        'en_entity_sentence_id' => $en1->id,
        'en_ru_meaning_match_id' => $matchRow->id,
        'order' => 0,
    ]);

    RuSentenceMeaningMatch::create([
        'ru_entity_sentence_id' => $ru1->id,
        'en_ru_meaning_match_id' => $matchRow->id,
        'order' => 0,
    ]);

    $skipRow = EnRuMeaningMatch::create([
        'en_ru_entity_match_id' => $entityMatch->id,
        'order' => 1,
        'similarity' => 0,
        'alignment_chunk' => 0,
    ]);

    RuSentenceMeaningMatch::create([
        'ru_entity_sentence_id' => $ru1->id,
        'en_ru_meaning_match_id' => $skipRow->id,
        'order' => 0,
    ]);

    $response = $this->actingAs($user)->postJson('/text', [
        'en_ru_entity_match_id' => $entityMatch->id,
        'page' => 1,
        'per_page' => 1,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.code', 200)
        ->assertJsonPath('data.data.meta.total', 2)
        ->assertJsonPath('data.data.meta.last_page', 2)
        ->assertJsonPath('data.data.meta.per_page', 1)
        ->assertJsonPath('data.data.meta.current_page', 1);

    $rows = $response->json('data.data.rows');
    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toBe(['First EN.', 'First RU.']);

    $page2 = $this->actingAs($user)->postJson('/text', [
        'en_ru_entity_match_id' => $entityMatch->id,
        'page' => 2,
        'per_page' => 1,
    ]);

    $page2->assertOk();
    expect($page2->json('data.data.rows'))->toHaveCount(1)
        ->and($page2->json('data.data.rows.0'))->toBe(['', 'First RU.'])
        ->and($page2->json('data.data.meta.current_page'))->toBe(2);
});

it('returns file-based rows when filename is provided without match id', function () {
    $user = User::factory()->create();

    $dir = public_path('texts/simulator');
    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $name = 'simulator_text_endpoint_fixture.txt';
    $path = $dir.'/'.$name;
    $content = "Line EN one\n\nLine RU one\n\n";
    file_put_contents($path, $content);

    try {
        $response = $this->actingAs($user)->postJson('/text', [
            'filename' => $name,
            'page' => 1,
            'per_page' => 10,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.code', 200);

        $rows = $response->json('data.data.rows');
        expect($rows)->toHaveCount(1)
            ->and($rows[0])->toBe(['Line EN one', 'Line RU one']);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('requires filename or en_ru_entity_match_id', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/text', [
        'page' => 1,
    ])->assertUnprocessable();
});
