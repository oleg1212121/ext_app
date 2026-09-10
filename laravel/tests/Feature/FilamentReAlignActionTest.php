<?php

use App\Filament\Resources\EntityMatchResource\Pages\ListEntityMatches;
use App\Jobs\AlignEntitySentences;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

/**
 * The EN entity is created first, so it is the match's 'a' side and RU is 'b'.
 */
function createFilamentReAlignFixture(): EntityMatch
{
    $sentenceType = SentenceType::create(['name' => 'sentence']);

    $work = createWork();
    $enEntity = createEntity('en', $work, [
        'name' => 'English',
        'signature' => json_encode([1.0, 0.0]),
    ]);
    $ruEntity = createEntity('ru', $work, [
        'name' => 'Russian',
        'signature' => json_encode([1.0, 0.0]),
    ]);

    $enSentence = EntitySentence::create([
        'entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'English.',
        'order' => 1,
    ]);
    $ruSentence = EntitySentence::create([
        'entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'Russian.',
        'order' => 1,
    ]);

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'completed',
        'chunk_size' => 75,
        'max_n' => 2,
        'a_total_sentences' => 1,
        'b_total_sentences' => 1,
        'linked_count' => 2,
    ]);

    $humanRow = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 1.0,
        'alignment_chunk' => -1,
    ]);
    SentenceMeaningMatch::create([
        'entity_sentence_id' => $enSentence->id,
        'meaning_match_id' => $humanRow->id,
        'side' => 'a',
    ]);
    SentenceMeaningMatch::create([
        'entity_sentence_id' => $ruSentence->id,
        'meaning_match_id' => $humanRow->id,
        'side' => 'b',
    ]);

    MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 1024,
        'similarity' => 0.95,
        'alignment_chunk' => 0,
    ]);

    return $entityMatch;
}

test('re-align and run-from-scratch actions are visible for completed matches and hidden while aligning', function () {
    $user = User::factory()->create();
    $entityMatch = createFilamentReAlignFixture();

    $aligningWork = createWork();
    $aligningEnEntity = createEntity('en', $aligningWork, ['name' => 'Aligning English', 'signature' => json_encode([1.0, 0.0])]);
    $aligningRuEntity = createEntity('ru', $aligningWork, ['name' => 'Aligning Russian', 'signature' => json_encode([1.0, 0.0])]);

    $aligningMatch = createEntityMatch($aligningEnEntity, $aligningRuEntity, [
        'status' => 'aligning',
        'a_total_sentences' => 1,
        'b_total_sentences' => 1,
    ]);

    Livewire::actingAs($user)
        ->test(ListEntityMatches::class)
        ->assertTableActionVisible('realign', $entityMatch)
        ->assertTableActionVisible('rerunScratch', $entityMatch)
        ->assertTableActionHidden('realign', $aligningMatch)
        ->assertTableActionHidden('rerunScratch', $aligningMatch);
});

test('re-align modal counts preserved rows and dispatches begin', function () {
    Bus::fake();
    $user = User::factory()->create();
    $entityMatch = createFilamentReAlignFixture();

    $lowConfidence = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 2048,
        'similarity' => 0.5,
        'alignment_chunk' => 0,
    ]);

    Livewire::actingAs($user)
        ->test(ListEntityMatches::class)
        ->mountTableAction('realign', $entityMatch)
        ->assertMountedActionModalSee('1 human-made + 1 confident row(s) preserved; only low-confidence rows will be re-aligned.')
        ->callMountedTableAction();

    Bus::assertDispatched(AlignEntitySentences::class);

    expect($entityMatch->refresh()->status)->toBe('aligning')
        ->and($entityMatch->linked_count)->toBe(2)
        ->and(MeaningMatch::find($lowConfidence->id))->toBeNull()
        ->and(MeaningMatch::query()->where('entity_match_id', $entityMatch->id)->count())->toBe(2);
});

test('run-from-scratch modal warns about human rows and dispatches beginFromScratch', function () {
    Bus::fake();
    $user = User::factory()->create();
    $entityMatch = createFilamentReAlignFixture();

    Livewire::actingAs($user)
        ->test(ListEntityMatches::class)
        ->mountTableAction('rerunScratch', $entityMatch)
        ->assertMountedActionModalSee('This deletes ALL meaning matches (including human-made ones) and re-runs the alignment pipeline from scratch.')
        ->assertMountedActionModalSee('1 human-made row(s) will be deleted.')
        ->callMountedTableAction();

    Bus::assertDispatched(AlignEntitySentences::class);

    expect($entityMatch->refresh()->status)->toBe('aligning')
        ->and($entityMatch->a_total_sentences)->toBe(1)
        ->and($entityMatch->b_total_sentences)->toBe(1)
        ->and(MeaningMatch::query()->where('entity_match_id', $entityMatch->id)->count())->toBe(0);
});
