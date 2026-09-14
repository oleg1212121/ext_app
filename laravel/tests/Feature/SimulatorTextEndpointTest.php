<?php

use App\Models\EntitySentence;
use App\Models\EntityWord;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns paginated alignment rows for entity_match_id', function () {
    $user = User::factory()->create();
    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    $en1 = EntitySentence::create([
        'entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'First EN.',
        'order' => 1,
    ]);
    $ru1 = EntitySentence::create([
        'entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'First RU.',
        'order' => 1,
    ]);

    $entityMatch = createEntityMatch($enEntity, $ruEntity, ['status' => 'completed']);

    $matchRow = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 0.95,
        'alignment_chunk' => 0,
    ]);

    SentenceMeaningMatch::create([
        'entity_sentence_id' => $en1->id,
        'meaning_match_id' => $matchRow->id,
        'side' => 'a',
    ]);

    SentenceMeaningMatch::create([
        'entity_sentence_id' => $ru1->id,
        'meaning_match_id' => $matchRow->id,
        'side' => 'b',
    ]);

    $skipRow = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 1,
        'similarity' => 0,
        'alignment_chunk' => 0,
    ]);

    SentenceMeaningMatch::create([
        'entity_sentence_id' => $ru1->id,
        'meaning_match_id' => $skipRow->id,
        'side' => 'b',
    ]);

    $response = $this->actingAs($user)->postJson('/text', [
        'entity_match_id' => $entityMatch->id,
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
        'entity_match_id' => $entityMatch->id,
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

it('requires filename or entity_match_id', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/text', [
        'page' => 1,
    ])->assertUnprocessable();
});

it('includes word maps for both sides of the match', function () {
    $user = User::factory()->create();
    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English']);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian']);

    $en1 = EntitySentence::create([
        'entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'The cat sleeps.',
        'order' => 1,
    ]);
    $ru1 = EntitySentence::create([
        'entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'Кот спит.',
        'order' => 1,
    ]);

    $entityMatch = createEntityMatch($enEntity, $ruEntity, ['status' => 'completed']);

    $matchRow = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 0.95,
        'alignment_chunk' => 0,
    ]);

    SentenceMeaningMatch::create(['entity_sentence_id' => $en1->id, 'meaning_match_id' => $matchRow->id, 'side' => 'a']);
    SentenceMeaningMatch::create(['entity_sentence_id' => $ru1->id, 'meaning_match_id' => $matchRow->id, 'side' => 'b']);

    $enCat = createWord('en', 'cat', 'noun');
    EntityWord::create([
        'entity_id' => $enEntity->id,
        'word_id' => $enCat->id,
        'l_word' => 'cat',
        'token' => 'cat',
        'count' => 1,
    ]);

    $ruCat = createWord('ru', 'кот', 'noun');
    EntityWord::create([
        'entity_id' => $ruEntity->id,
        'word_id' => $ruCat->id,
        'l_word' => 'кот',
        'token' => 'кот',
        'count' => 1,
    ]);
    $user->userWords()->create(['word_id' => $ruCat->id, 'status' => 'learning']);

    // Factory users are native English speakers: the EN side (a) is native,
    // so only the RU side (b) is highlightable.
    $response = $this->actingAs($user)->postJson('/text', [
        'entity_match_id' => $entityMatch->id,
        'page' => 1,
        'per_page' => 10,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.data.word_maps.a.cat.w', $enCat->id)
        ->assertJsonPath('data.data.word_maps.a.cat.s', null)
        ->assertJsonPath('data.data.word_maps.b.кот.s', 'learning')
        ->assertJsonPath('data.data.word_maps.highlightable.a', false)
        ->assertJsonPath('data.data.word_maps.highlightable.b', true);
});
