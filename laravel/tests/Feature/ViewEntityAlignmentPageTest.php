<?php

use App\Filament\Resources\EntityMatchResource\Pages\ViewEntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('alignment view paginates meaning rows and shows page input', function () {
    $user = User::factory()->create();
    $sentenceType = SentenceType::create(['name' => 'sentence']);
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English']);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian']);

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'completed',
        'a_total_sentences' => 60,
        'b_total_sentences' => 60,
        'linked_count' => 60,
    ]);

    for ($i = 1; $i <= 60; $i++) {
        $enSentence = EntitySentence::create([
            'entity_id' => $enEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "EN sentence {$i}.",
            'order' => $i,
        ]);

        $ruSentence = EntitySentence::create([
            'entity_id' => $ruEntity->id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "RU sentence {$i}.",
            'order' => $i,
        ]);

        $meaningMatch = MeaningMatch::create([
            'entity_match_id' => $entityMatch->id,
            'order' => $i - 1,
            'similarity' => 1.0,
            'alignment_chunk' => 0,
        ]);

        SentenceMeaningMatch::create([
            'entity_sentence_id' => $enSentence->id,
            'meaning_match_id' => $meaningMatch->id,
            'side' => 'a',
        ]);

        SentenceMeaningMatch::create([
            'entity_sentence_id' => $ruSentence->id,
            'meaning_match_id' => $meaningMatch->id,
            'side' => 'b',
        ]);
    }

    Livewire::actingAs($user)
        ->test(ViewEntityMatch::class, ['record' => $entityMatch->id])
        ->assertSee('page-input-goToDisplayPage', false)
        ->assertSee('EN sentence 1.')
        ->assertDontSee('EN sentence 51.')
        ->call('goToDisplayPage', 2)
        ->assertSet('displayPage', 2)
        ->assertSee('EN sentence 51.')
        ->assertDontSee('EN sentence 1.');
});
