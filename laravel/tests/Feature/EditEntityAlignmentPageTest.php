<?php

use App\Classes\SparseOrderService;
use App\Filament\Resources\EnRuEntityMatchResource\Pages\EditEntityAlignment;
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

function createEditableAlignment(): EnRuEntityMatch
{
    $sentenceType = SentenceType::create(['name' => 'sentence']);

    $enEntity = EnEntity::create(['name' => 'English chapter']);
    $ruEntity = RuEntity::create(['name' => 'Russian chapter']);

    $enSentence = EnEntitySentence::create([
        'en_entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'The first English sentence.',
        'order' => 0,
    ]);

    $ruSentence = RuEntitySentence::create([
        'ru_entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'Первое русское предложение.',
        'order' => 0,
    ]);

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'completed',
        'en_total_sentences' => 1,
        'ru_total_sentences' => 1,
        'linked_count' => 1,
    ]);

    $meaningMatch = EnRuMeaningMatch::create([
        'en_ru_entity_match_id' => $entityMatch->id,
        'order' => 0,
        'similarity' => 0.9345,
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
    $enSentence = EnEntitySentence::query()->first();

    Livewire::actingAs($user)
        ->test(EditEntityAlignment::class, ['record' => $entityMatch->id])
        ->assertSet('isDirty', false)
        ->call('updateSentenceContent', 'en', 's-'.$enSentence->id, 'Updated English sentence.')
        ->assertSet('isDirty', true)
        ->call('save')
        ->assertSet('isDirty', false);

    expect($enSentence->fresh()->content)->toBe('Updated English sentence.');
});

test('alignment editor inserts paired empty row below meaning row', function () {
    $user = User::factory()->create();
    $entityMatch = createEditableAlignment();
    $meaningMatch = EnRuMeaningMatch::query()->first();

    Livewire::actingAs($user)
        ->test(EditEntityAlignment::class, ['record' => $entityMatch->id])
        ->call('insertMeaningRowAfter', 'mm-'.$meaningMatch->id)
        ->assertSet('isDirty', true)
        ->assertSet('meaningRowsTotal', 2)
        ->tap(function ($component) {
            $rows = $component->get('visibleMeaningRows');

            expect($rows)->toHaveCount(2)
                ->and($rows[1]['id'])->toBeNull()
                ->and($rows[1]['en_sentences'])->toHaveCount(1)
                ->and($rows[1]['ru_sentences'])->toHaveCount(1)
                ->and($rows[1]['en_sentences'][0]['content'])->toBe('')
                ->and($rows[1]['ru_sentences'][0]['content'])->toBe('')
                ->and($rows[1]['en_sentences'][0]['order'])->toBe(SparseOrderService::STRIDE)
                ->and($rows[1]['ru_sentences'][0]['order'])->toBe(SparseOrderService::STRIDE);
        });
});

test('alignment editor row save persists inserted paired sentences and link', function () {
    $user = User::factory()->create();
    $entityMatch = createEditableAlignment();
    $meaningMatch = EnRuMeaningMatch::query()->first();

    $component = Livewire::actingAs($user)
        ->test(EditEntityAlignment::class, ['record' => $entityMatch->id])
        ->call('insertMeaningRowAfter', 'mm-'.$meaningMatch->id);

    $rows = $component->get('visibleMeaningRows');
    $newRow = $rows[1];
    $enSentence = $newRow['en_sentences'][0];
    $ruSentence = $newRow['ru_sentences'][0];

    $component
        ->call('updateSentenceContent', 'en', $enSentence['key'], 'Inserted English sentence.')
        ->call('updateSentenceContent', 'ru', $ruSentence['key'], 'Вставленное русское предложение.')
        ->call('saveMeaningRow', $newRow['key'])
        ->assertSet('isDirty', false);

    $insertedEn = EnEntitySentence::query()->where('content', 'Inserted English sentence.')->first();
    $insertedRu = RuEntitySentence::query()->where('content', 'Вставленное русское предложение.')->first();
    $insertedMeaningMatch = EnRuMeaningMatch::query()
        ->where('en_ru_entity_match_id', $entityMatch->id)
        ->where('order', SparseOrderService::STRIDE)
        ->first();

    expect($insertedEn)->not->toBeNull()
        ->and($insertedEn->order)->toBe(SparseOrderService::STRIDE)
        ->and($insertedRu)->not->toBeNull()
        ->and($insertedRu->order)->toBe(SparseOrderService::STRIDE)
        ->and($insertedMeaningMatch)->not->toBeNull()
        ->and($insertedMeaningMatch->enSentenceMatches()->count())->toBe(1)
        ->and($insertedMeaningMatch->ruSentenceMatches()->count())->toBe(1);
});

test('alignment editor row save requires both sides for inserted row', function () {
    $user = User::factory()->create();
    $entityMatch = createEditableAlignment();
    $meaningMatch = EnRuMeaningMatch::query()->first();

    $component = Livewire::actingAs($user)
        ->test(EditEntityAlignment::class, ['record' => $entityMatch->id])
        ->call('insertMeaningRowAfter', 'mm-'.$meaningMatch->id);

    $rows = $component->get('visibleMeaningRows');
    $newRow = $rows[1];
    $enSentence = $newRow['en_sentences'][0];

    $component
        ->call('updateSentenceContent', 'en', $enSentence['key'], 'Only English was entered.')
        ->call('saveMeaningRow', $newRow['key'])
        ->assertSet('isDirty', true);

    expect(EnRuMeaningMatch::query()->where('en_ru_entity_match_id', $entityMatch->id)->count())->toBe(1)
        ->and(EnEntitySentence::query()->where('content', 'Only English was entered.')->exists())->toBeFalse();
});

test('alignment editor row save persists existing row content changes', function () {
    $user = User::factory()->create();
    $entityMatch = createEditableAlignment();
    $enSentence = EnEntitySentence::query()->first();
    $meaningMatch = EnRuMeaningMatch::query()->first();

    Livewire::actingAs($user)
        ->test(EditEntityAlignment::class, ['record' => $entityMatch->id])
        ->call('updateSentenceContent', 'en', 's-'.$enSentence->id, 'Saved from row action.')
        ->assertSet('isDirty', true)
        ->call('saveMeaningRow', 'mm-'.$meaningMatch->id)
        ->assertSet('isDirty', false);

    expect($enSentence->fresh()->content)->toBe('Saved from row action.');
});

test('alignment editor can remove a meaning link while keeping sentences', function () {
    $user = User::factory()->create();
    $entityMatch = createEditableAlignment();
    $enSentence = EnEntitySentence::query()->first();
    $ruSentence = RuEntitySentence::query()->first();
    $meaningMatch = EnRuMeaningMatch::query()->first();

    Livewire::actingAs($user)
        ->test(EditEntityAlignment::class, ['record' => $entityMatch->id])
        ->call('unlinkMeaningRow', 'mm-'.$meaningMatch->id)
        ->assertSet('isDirty', false)
        ->assertSet('meaningRowsTotal', 0)
        ->assertSet('unmatchedEnTotal', 1)
        ->assertSet('unmatchedRuTotal', 1);

    expect($enSentence->fresh())->not->toBeNull()
        ->and($ruSentence->fresh())->not->toBeNull()
        ->and(EnRuMeaningMatch::query()->whereKey($meaningMatch->id)->exists())->toBeFalse()
        ->and(EnSentenceMeaningMatch::query()->where('en_entity_sentence_id', $enSentence->id)->exists())->toBeFalse()
        ->and(RuSentenceMeaningMatch::query()->where('ru_entity_sentence_id', $ruSentence->id)->exists())->toBeFalse();
});

test('alignment editor can add unmatched sentence and connect to meaning row', function () {
    $user = User::factory()->create();
    $entityMatch = createEditableAlignment();

    Livewire::actingAs($user)
        ->test(EditEntityAlignment::class, ['record' => $entityMatch->id])
        ->set('addLang', 'en')
        ->set('addAfterOrder', 0)
        ->set('addContent', 'New unmatched EN sentence.')
        ->call('addSentence')
        ->assertSet('isDirty', true)
        ->tap(function ($component) {
            $unmatched = $component->get('visibleUnmatchedEn');
            expect($unmatched)->toHaveCount(1);

            $component
                ->call('openConnectModal', 'en', $unmatched[0]['key'])
                ->set('connectMode', 0)
                ->call('connectSentence');
        })
        ->call('save');

    expect(EnEntitySentence::query()->where('content', 'New unmatched EN sentence.')->exists())->toBeTrue();

    $meaningMatch = EnRuMeaningMatch::query()->where('en_ru_entity_match_id', $entityMatch->id)->first();
    expect($meaningMatch->enSentenceMatches()->count())->toBe(2);
});

test('alignment editor discard reloads original draft', function () {
    $user = User::factory()->create();
    $entityMatch = createEditableAlignment();
    $enSentence = EnEntitySentence::query()->first();

    Livewire::actingAs($user)
        ->test(EditEntityAlignment::class, ['record' => $entityMatch->id])
        ->call('updateSentenceContent', 'en', 's-'.$enSentence->id, 'Temporary edit.')
        ->call('discardChanges')
        ->assertSet('isDirty', false);

    expect($enSentence->fresh()->content)->toBe('The first English sentence.');
});

test('simulator text endpoint returns saved alignment edits', function () {
    $user = User::factory()->create();
    $entityMatch = createEditableAlignment();
    $enSentence = EnEntitySentence::query()->first();

    Livewire::actingAs($user)
        ->test(EditEntityAlignment::class, ['record' => $entityMatch->id])
        ->call('updateSentenceContent', 'en', 's-'.$enSentence->id, 'Saved via editor.')
        ->call('save');

    $response = $this->actingAs($user)->postJson('/text', [
        'en_ru_entity_match_id' => $entityMatch->id,
        'page' => 1,
        'per_page' => 10,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.data.rows.0.0', 'Saved via editor.');
});

test('alignment editor paginates meaning rows', function () {
    $user = User::factory()->create();
    $sentenceType = SentenceType::create(['name' => 'sentence']);
    $enEntity = EnEntity::create(['name' => 'English']);
    $ruEntity = RuEntity::create(['name' => 'Russian']);

    $entityMatch = EnRuEntityMatch::create([
        'en_entity_id' => $enEntity->id,
        'ru_entity_id' => $ruEntity->id,
        'status' => 'completed',
        'en_total_sentences' => 30,
        'ru_total_sentences' => 30,
        'linked_count' => 30,
    ]);

    for ($i = 1; $i <= 30; $i++) {
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

    $enSentence26 = EnEntitySentence::where('content', 'EN sentence 26.')->first();

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
        ->call('updateSentenceContent', 'en', 's-'.$enSentence26->id, 'Updated EN sentence 26.')
        ->call('save')
        ->assertSet('meaningPage', 2)
        ->assertSee('Updated EN sentence 26.')
        ->assertDontSee('EN sentence 1.');
});
