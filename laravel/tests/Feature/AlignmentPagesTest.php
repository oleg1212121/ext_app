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
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('guests are redirected from alignment pages', function () {
    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => EnEntity::create([
            'name' => 'English text',
        ])->id,
        'ru_entity_id' => RuEntity::create([
            'name' => 'Russian text',
        ])->id,
        'status' => 'pending',
    ]);

    $this->get(route('alignments.index'))
        ->assertRedirect(route('login'));

    $this->get(route('alignments.show', $entityMatch))
        ->assertRedirect(route('login'));
});

test('authenticated users can view the alignments list', function () {
    $user = User::factory()->create();

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => EnEntity::create([
            'name' => 'English chapter',
        ])->id,
        'ru_entity_id' => RuEntity::create([
            'name' => 'Russian chapter',
        ])->id,
        'status' => 'completed',
        'entity_similarity' => 0.9123,
        'en_total_sentences' => 8,
        'ru_total_sentences' => 7,
        'linked_count' => 6,
    ]);

    $response = $this
        ->actingAs($user)
        ->get(route('alignments.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Alignments/Index')
        ->has('entityMatches', 1)
        ->where('entityMatches.0.en_entity_name', 'English chapter')
        ->where('entityMatches.0.ru_entity_name', 'Russian chapter'));
});

test('authenticated users can view alignment details', function () {
    $user = User::factory()->create();
    $sentenceType = SentenceType::create([
        'name' => 'Narration',
    ]);

    $enEntity = EnEntity::create([
        'name' => 'English chapter',
    ]);

    $ruEntity = RuEntity::create([
        'name' => 'Russian chapter',
    ]);

    $enSentence = EnEntitySentence::create([
        'en_entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'The first English sentence.',
        'order' => 1,
    ]);

    $ruSentence = RuEntitySentence::create([
        'ru_entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'Первое русское предложение.',
        'order' => 1,
    ]);

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'completed',
        'entity_similarity' => 0.9345,
        'en_total_sentences' => 1,
        'ru_total_sentences' => 1,
        'linked_count' => 1,
    ]);

    $meaningMatch = EnRuMeaningMatch::create([
        'en_ru_entity_match_id' => $entityMatch->id,
        'order' => 1,
        'similarity' => 0.9345,
        'alignment_chunk' => 0,
    ]);

    EnSentenceMeaningMatch::create([
        'en_entity_sentence_id' => $enSentence->id,
        'en_ru_meaning_match_id' => $meaningMatch->id,
        'order' => 0,
    ]);

    RuSentenceMeaningMatch::create([
        'ru_entity_sentence_id' => $ruSentence->id,
        'en_ru_meaning_match_id' => $meaningMatch->id,
        'order' => 0,
    ]);

    $response = $this
        ->actingAs($user)
        ->get(route('alignments.show', $entityMatch));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Alignments/Show')
        ->where('match.en_entity_name', 'English chapter')
        ->where('match.ru_entity_name', 'Russian chapter')
        ->has('rows.0.en_sentences', 1)
        ->where('rows.0.en_sentences.0.content', 'The first English sentence.')
        ->has('rows.0.ru_sentences', 1)
        ->where('rows.0.ru_sentences.0.content', 'Первое русское предложение.'));
});
