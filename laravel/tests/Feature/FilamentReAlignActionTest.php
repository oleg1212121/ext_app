<?php

use App\Filament\Resources\EnRuEntityMatchResource\Pages\ListEnRuEntityMatches;
use App\Jobs\AlignEntitySentences;
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
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

function createFilamentReAlignFixture(): EnRuEntityMatch
{
    $sentenceType = SentenceType::create(['name' => 'sentence']);

    $enEntity = EnEntity::create([
        'name' => 'English',
        'signature' => json_encode([1.0, 0.0]),
    ]);
    $ruEntity = RuEntity::create([
        'name' => 'Russian',
        'signature' => json_encode([1.0, 0.0]),
    ]);

    $enSentence = EnEntitySentence::create([
        'en_entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'English.',
        'order' => 1,
    ]);
    $ruSentence = RuEntitySentence::create([
        'ru_entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'Russian.',
        'order' => 1,
    ]);

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'completed',
        'chunk_size' => 75,
        'max_n' => 2,
        'en_total_sentences' => 1,
        'ru_total_sentences' => 1,
        'linked_count' => 2,
    ]);

    $humanRow = EnRuMeaningMatch::create([
        'en_ru_entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 1.0,
        'alignment_chunk' => -1,
    ]);
    EnSentenceMeaningMatch::create([
        'en_entity_sentence_id' => $enSentence->id,
        'en_ru_meaning_match_id' => $humanRow->id,
    ]);
    RuSentenceMeaningMatch::create([
        'ru_entity_sentence_id' => $ruSentence->id,
        'en_ru_meaning_match_id' => $humanRow->id,
    ]);

    EnRuMeaningMatch::create([
        'en_ru_entity_match_id' => $entityMatch->id,
        'order' => 1024,
        'similarity' => 0.95,
        'alignment_chunk' => 0,
    ]);

    return $entityMatch;
}

test('re-align and run-from-scratch actions are visible for completed matches and hidden while aligning', function () {
    $user = User::factory()->create();
    $entityMatch = createFilamentReAlignFixture();

    $aligningEnEntity = EnEntity::create(['name' => 'Aligning English', 'signature' => json_encode([1.0, 0.0])]);
    $aligningRuEntity = RuEntity::create(['name' => 'Aligning Russian', 'signature' => json_encode([1.0, 0.0])]);

    $aligningMatch = EnRuEntityMatch::create([
        'en_entity_id' => $aligningEnEntity->id,
        'ru_entity_id' => $aligningRuEntity->id,
        'status' => 'aligning',
        'en_total_sentences' => 1,
        'ru_total_sentences' => 1,
    ]);

    Livewire::actingAs($user)
        ->test(ListEnRuEntityMatches::class)
        ->assertTableActionVisible('realign', $entityMatch)
        ->assertTableActionVisible('rerunScratch', $entityMatch)
        ->assertTableActionHidden('realign', $aligningMatch)
        ->assertTableActionHidden('rerunScratch', $aligningMatch);
});

test('re-align modal counts preserved rows and dispatches begin', function () {
    Bus::fake();
    $user = User::factory()->create();
    $entityMatch = createFilamentReAlignFixture();

    $lowConfidence = EnRuMeaningMatch::create([
        'en_ru_entity_match_id' => $entityMatch->id,
        'order' => 2048,
        'similarity' => 0.5,
        'alignment_chunk' => 0,
    ]);

    Livewire::actingAs($user)
        ->test(ListEnRuEntityMatches::class)
        ->mountTableAction('realign', $entityMatch)
        ->assertMountedActionModalSee('1 human-made + 1 confident row(s) preserved; only low-confidence rows will be re-aligned.')
        ->callMountedTableAction();

    Bus::assertDispatched(AlignEntitySentences::class);

    expect($entityMatch->refresh()->status)->toBe('aligning')
        ->and($entityMatch->linked_count)->toBe(2)
        ->and(EnRuMeaningMatch::find($lowConfidence->id))->toBeNull()
        ->and(EnRuMeaningMatch::query()->where('en_ru_entity_match_id', $entityMatch->id)->count())->toBe(2);
});

test('run-from-scratch modal warns about human rows and dispatches beginFromScratch', function () {
    Bus::fake();
    $user = User::factory()->create();
    $entityMatch = createFilamentReAlignFixture();

    Livewire::actingAs($user)
        ->test(ListEnRuEntityMatches::class)
        ->mountTableAction('rerunScratch', $entityMatch)
        ->assertMountedActionModalSee('This deletes ALL meaning matches (including human-made ones) and re-runs the alignment pipeline from scratch.')
        ->assertMountedActionModalSee('1 human-made row(s) will be deleted.')
        ->callMountedTableAction();

    Bus::assertDispatched(AlignEntitySentences::class);

    expect($entityMatch->refresh()->status)->toBe('aligning')
        ->and($entityMatch->en_total_sentences)->toBe(1)
        ->and($entityMatch->ru_total_sentences)->toBe(1)
        ->and(EnRuMeaningMatch::query()->where('en_ru_entity_match_id', $entityMatch->id)->count())->toBe(0);
});
