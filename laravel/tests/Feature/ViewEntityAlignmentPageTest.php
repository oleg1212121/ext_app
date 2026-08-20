<?php

use App\Filament\Resources\EnRuEntityMatchResource\Pages\ViewEnRuEntityMatch;
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
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('alignment view paginates meaning rows and shows page input', function () {
    $user = User::factory()->create();
    $sentenceType = SentenceType::create(['name' => 'sentence']);
    $enEntity = EnEntity::create(['name' => 'English']);
    $ruEntity = RuEntity::create(['name' => 'Russian']);

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'completed',
        'en_total_sentences' => 60,
        'ru_total_sentences' => 60,
        'linked_count' => 60,
    ]);

    for ($i = 1; $i <= 60; $i++) {
        $enSentence = EnEntitySentence::create([
            'en_entity_id' => $enEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "EN sentence {$i}.",
            'order' => $i,
        ]);

        $ruSentence = RuEntitySentence::create([
            'ru_entity_id' => $ruEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "RU sentence {$i}.",
            'order' => $i,
        ]);

        $meaningMatch = EnRuMeaningMatch::create([
            'en_ru_entity_match_id' => $entityMatch->id,
            'order' => $i - 1,
            'similarity' => 1.0,
            'alignment_chunk' => 0,
        ]);

        EnSentenceMeaningMatch::create([
            'en_entity_sentence_id' => $enSentence->id,
            'en_ru_meaning_match_id' => $meaningMatch->id,
        ]);

        RuSentenceMeaningMatch::create([
            'ru_entity_sentence_id' => $ruSentence->id,
            'en_ru_meaning_match_id' => $meaningMatch->id,
        ]);
    }

    Livewire::actingAs($user)
        ->test(ViewEnRuEntityMatch::class, ['record' => $entityMatch->id])
        ->assertSee('page-input-goToDisplayPage', false)
        ->assertSee('EN sentence 1.')
        ->assertDontSee('EN sentence 51.')
        ->call('goToDisplayPage', 2)
        ->assertSet('displayPage', 2)
        ->assertSee('EN sentence 51.')
        ->assertDontSee('EN sentence 1.');
});
