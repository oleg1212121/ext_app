<?php

use App\Classes\SparseOrderService;
use App\Filament\Resources\EntityMatchResource\Pages\EditEntityAlignment;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * The EN entity is created first, so it is the match's 'a' side and RU is 'b'.
 */
function createEditableAlignment(): EntityMatch
{
    $sentenceType = SentenceType::create(['name' => 'sentence']);

    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English chapter']);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian chapter']);

    $enSentence = EntitySentence::create([
        'entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'The first English sentence.',
        'order' => 0,
    ]);

    $ruSentence = EntitySentence::create([
        'entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'Первое русское предложение.',
        'order' => 0,
    ]);

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'completed',
        'a_total_sentences' => 1,
        'b_total_sentences' => 1,
        'linked_count' => 1,
    ]);

    $meaningMatch = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 0.9345,
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

    return $entityMatch;
}

test('authenticated users can open the alignment editor page', function () {
    $user = User::factory()->create();
    $entityMatch = createEditableAlignment();

    Livewire::actingAs($user)
        ->test(EditEntityAlignment::class, ['record' => $entityMatch->id])
        ->assertSuccessful()
        ->assertSee('Actions')
        ->assertSee('Save row')
        ->assertSee('Insert row below')
        ->assertSee('Remove link')
        ->assertDontSee('Move up')
        ->assertDontSee('Move down')
        ->assertSee('The first English sentence.')
        ->assertSee('Первое русское предложение.');
});

test('alignment editor marks dirty and saves content changes', function () {
    $user = User::factory()->create();
    $entityMatch = createEditableAlignment();
    $enSentence = EntitySentence::query()->where('entity_id', $entityMatch->a_entity_id)->first();

    Livewire::actingAs($user)
        ->test(EditEntityAlignment::class, ['record' => $entityMatch->id])
        ->assertSet('isDirty', false)
        ->call('updateSentenceContent', 'a', 's-'.$enSentence->id, 'Updated English sentence.')
        ->assertSet('isDirty', true)
        ->call('save')
        ->assertSet('isDirty', false);

    expect($enSentence->fresh()->content)->toBe('Updated English sentence.');
});

test('alignment editor inserts paired empty row below meaning row', function () {
    $user = User::factory()->create();
    $entityMatch = createEditableAlignment();
    $meaningMatch = MeaningMatch::query()->first();

    Livewire::actingAs($user)
        ->test(EditEntityAlignment::class, ['record' => $entityMatch->id])
        ->call('insertMeaningRowAfter', 'mm-'.$meaningMatch->id)
        ->assertSet('isDirty', true)
        ->assertSet('meaningRowsTotal', 2)
        ->tap(function ($component) {
            $rows = $component->get('visibleMeaningRows');

            expect($rows)->toHaveCount(2)
                ->and($rows[1]['id'])->toBeNull()
                ->and($rows[1]['a_sentences'])->toHaveCount(1)
                ->and($rows[1]['b_sentences'])->toHaveCount(1)
                ->and($rows[1]['a_sentences'][0]['content'])->toBe('')
                ->and($rows[1]['b_sentences'][0]['content'])->toBe('')
                ->and($rows[1]['a_sentences'][0]['order'])->toBe(SparseOrderService::STRIDE)
                ->and($rows[1]['b_sentences'][0]['order'])->toBe(SparseOrderService::STRIDE);
        });
});

test('alignment editor row save persists inserted paired sentences and link', function () {
    $user = User::factory()->create();
    $entityMatch = createEditableAlignment();
    $meaningMatch = MeaningMatch::query()->first();

    $component = Livewire::actingAs($user)
        ->test(EditEntityAlignment::class, ['record' => $entityMatch->id])
        ->call('insertMeaningRowAfter', 'mm-'.$meaningMatch->id);

    $rows = $component->get('visibleMeaningRows');
    $newRow = $rows[1];
    $enSentence = $newRow['a_sentences'][0];
    $ruSentence = $newRow['b_sentences'][0];

    $component
        ->call('updateSentenceContent', 'a', $enSentence['key'], 'Inserted English sentence.')
        ->call('updateSentenceContent', 'b', $ruSentence['key'], 'Вставленное русское предложение.')
        ->call('saveMeaningRow', $newRow['key'])
        ->assertSet('isDirty', false);

    $insertedEn = EntitySentence::query()->where('content', 'Inserted English sentence.')->first();
    $insertedRu = EntitySentence::query()->where('content', 'Вставленное русское предложение.')->first();
    $insertedMeaningMatch = MeaningMatch::query()
        ->where('entity_match_id', $entityMatch->id)
        ->where('order', SparseOrderService::STRIDE)
        ->first();

    expect($insertedEn)->not->toBeNull()
        ->and($insertedEn->order)->toBe(SparseOrderService::STRIDE)
        ->and($insertedRu)->not->toBeNull()
        ->and($insertedRu->order)->toBe(SparseOrderService::STRIDE)
        ->and($insertedMeaningMatch)->not->toBeNull()
        ->and($insertedMeaningMatch->sideSentenceMeaningMatches('a')->count())->toBe(1)
        ->and($insertedMeaningMatch->sideSentenceMeaningMatches('b')->count())->toBe(1);
});

test('alignment editor row save requires both sides for inserted row', function () {
    $user = User::factory()->create();
    $entityMatch = createEditableAlignment();
    $meaningMatch = MeaningMatch::query()->first();

    $component = Livewire::actingAs($user)
        ->test(EditEntityAlignment::class, ['record' => $entityMatch->id])
        ->call('insertMeaningRowAfter', 'mm-'.$meaningMatch->id);

    $rows = $component->get('visibleMeaningRows');
    $newRow = $rows[1];
    $enSentence = $newRow['a_sentences'][0];

    $component
        ->call('updateSentenceContent', 'a', $enSentence['key'], 'Only English was entered.')
        ->call('saveMeaningRow', $newRow['key'])
        ->assertSet('isDirty', true);

    expect(MeaningMatch::query()->where('entity_match_id', $entityMatch->id)->count())->toBe(1)
        ->and(EntitySentence::query()->where('content', 'Only English was entered.')->exists())->toBeFalse();
});

test('alignment editor row save persists existing row content changes', function () {
    $user = User::factory()->create();
    $entityMatch = createEditableAlignment();
    $enSentence = EntitySentence::query()->where('entity_id', $entityMatch->a_entity_id)->first();
    $meaningMatch = MeaningMatch::query()->first();

    Livewire::actingAs($user)
        ->test(EditEntityAlignment::class, ['record' => $entityMatch->id])
        ->call('updateSentenceContent', 'a', 's-'.$enSentence->id, 'Saved from row action.')
        ->assertSet('isDirty', true)
        ->call('saveMeaningRow', 'mm-'.$meaningMatch->id)
        ->assertSet('isDirty', false);

    expect($enSentence->fresh()->content)->toBe('Saved from row action.');
});

test('alignment editor can remove a meaning link while keeping sentences', function () {
    $user = User::factory()->create();
    $entityMatch = createEditableAlignment();
    $enSentence = EntitySentence::query()->where('entity_id', $entityMatch->a_entity_id)->first();
    $ruSentence = EntitySentence::query()->where('entity_id', $entityMatch->b_entity_id)->first();
    $meaningMatch = MeaningMatch::query()->first();

    Livewire::actingAs($user)
        ->test(EditEntityAlignment::class, ['record' => $entityMatch->id])
        ->call('unlinkMeaningRow', 'mm-'.$meaningMatch->id)
        ->assertSet('isDirty', false)
        ->assertSet('meaningRowsTotal', 0)
        ->assertSet('unmatchedATotal', 1)
        ->assertSet('unmatchedBTotal', 1);

    expect($enSentence->fresh())->not->toBeNull()
        ->and($ruSentence->fresh())->not->toBeNull()
        ->and(MeaningMatch::query()->whereKey($meaningMatch->id)->exists())->toBeFalse()
        ->and(SentenceMeaningMatch::query()->where('entity_sentence_id', $enSentence->id)->exists())->toBeFalse()
        ->and(SentenceMeaningMatch::query()->where('entity_sentence_id', $ruSentence->id)->exists())->toBeFalse();
});

test('alignment editor can add unmatched sentence and connect to meaning row', function () {
    $user = User::factory()->create();
    $entityMatch = createEditableAlignment();

    Livewire::actingAs($user)
        ->test(EditEntityAlignment::class, ['record' => $entityMatch->id])
        ->set('addSide', 'a')
        ->set('addAfterOrder', 0)
        ->set('addContent', 'New unmatched EN sentence.')
        ->call('addSentence')
        ->assertSet('isDirty', true)
        ->tap(function ($component) {
            $unmatched = $component->get('visibleUnmatchedA');
            expect($unmatched)->toHaveCount(1);

            $component
                ->call('openConnectModal', 'a', $unmatched[0]['key'])
                ->set('connectMode', 0)
                ->call('connectSentence');
        })
        ->call('save');

    expect(EntitySentence::query()->where('content', 'New unmatched EN sentence.')->exists())->toBeTrue();

    $meaningMatch = MeaningMatch::query()->where('entity_match_id', $entityMatch->id)->first();
    expect($meaningMatch->sideSentenceMeaningMatches('a')->count())->toBe(2);
});

test('alignment editor discard reloads original draft', function () {
    $user = User::factory()->create();
    $entityMatch = createEditableAlignment();
    $enSentence = EntitySentence::query()->where('entity_id', $entityMatch->a_entity_id)->first();

    Livewire::actingAs($user)
        ->test(EditEntityAlignment::class, ['record' => $entityMatch->id])
        ->call('updateSentenceContent', 'a', 's-'.$enSentence->id, 'Temporary edit.')
        ->call('discardChanges')
        ->assertSet('isDirty', false);

    expect($enSentence->fresh()->content)->toBe('The first English sentence.');
});

test('simulator text endpoint returns saved alignment edits', function () {
    $user = User::factory()->create();
    $entityMatch = createEditableAlignment();
    $enSentence = EntitySentence::query()->where('entity_id', $entityMatch->a_entity_id)->first();

    Livewire::actingAs($user)
        ->test(EditEntityAlignment::class, ['record' => $entityMatch->id])
        ->call('updateSentenceContent', 'a', 's-'.$enSentence->id, 'Saved via editor.')
        ->call('save');

    $response = $this->actingAs($user)->postJson('/text', [
        'entity_match_id' => $entityMatch->id,
        'page' => 1,
        'per_page' => 10,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.data.rows.0.0', 'Saved via editor.');
});

test('alignment editor paginates meaning rows', function () {
    $user = User::factory()->create();
    $sentenceType = SentenceType::create(['name' => 'sentence']);
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English']);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian']);

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'completed',
        'a_total_sentences' => 30,
        'b_total_sentences' => 30,
        'linked_count' => 30,
    ]);

    for ($i = 1; $i <= 30; $i++) {
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

    $enSentence26 = EntitySentence::where('content', 'EN sentence 26.')->first();

    Livewire::actingAs($user)
        ->test(EditEntityAlignment::class, ['record' => $entityMatch->id])
        ->assertSet('meaningRowsTotal', 30)
        ->assertSet('meaningLastPage', 2)
        ->assertSee('page-input-goToMeaningPage', false)
        ->assertSee('EN sentence 1.')
        ->assertDontSee('EN sentence 26.')
        ->call('goToMeaningPage', 2)
        ->assertSee('EN sentence 26.')
        ->assertDontSee('EN sentence 1.')
        ->call('updateSentenceContent', 'a', 's-'.$enSentence26->id, 'Updated EN sentence 26.')
        ->call('save')
        ->assertSet('meaningPage', 2)
        ->assertSee('Updated EN sentence 26.')
        ->assertDontSee('EN sentence 1.');
});
