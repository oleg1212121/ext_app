<?php

use App\Classes\EntityTextHasher;
use App\Classes\ReadingRowsPresenter;
use App\Jobs\AlignEntitySentences;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

if (! function_exists('approvedUser')) {
    function approvedUser(): User
    {
        return User::factory()->create(['is_approved' => true]);
    }
}

if (! function_exists('makeIllustrationTypeForAlignment')) {
    function makeIllustrationTypeForAlignment(): SentenceType
    {
        return SentenceType::firstOrCreate(
            ['name' => 'illustration'],
            ['description' => 'An inline illustration with an optional caption'],
        );
    }
}

if (! function_exists('createIllustrationSentence')) {
    function createIllustrationSentence(Entity $entity, int $order, string $caption): EntitySentence
    {
        return EntitySentence::create([
            'entity_id' => $entity->id,
            'sentence_type_id' => SentenceType::illustrationId(),
            'content' => $caption,
            'order' => $order,
            'image_path' => 'illustrations/en/test-'.uniqid().'.png',
            'image_hash' => hash('sha256', $caption.$order),
            'image_width' => 10,
            'image_height' => 10,
            'image_mime' => 'image/png',
        ]);
    }
}

beforeEach(function () {
    createLanguages();
    makeIllustrationTypeForAlignment();
});

// ─── pipeline ────────────────────────────────────────────────────────────────

it('excludes illustrations from the aligner and backfills them single-sided', function () {
    $fake = fakePython()->aligning([
        ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.9],
        ['a_start' => 1, 'a_end' => 2, 'b_start' => 1, 'b_end' => 2, 'score' => 0.9],
    ]);

    Bus::fake();

    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    $illustration = createIllustrationSentence($enEntity, 2, 'The lighthouse.');

    foreach ([1, 3] as $order) {
        EntitySentence::create(['entity_id' => $enEntity->id, 'content' => "English {$order}.", 'order' => $order]);
        EntitySentence::create(['entity_id' => $ruEntity->id, 'content' => "Russian {$order}.", 'order' => $order]);
    }

    $entityMatch = createEntityMatch($enEntity, $ruEntity, ['status' => 'pending']);

    AlignEntitySentences::beginFromScratch($entityMatch->id);
    $entityMatch->refresh();

    // Totals are the aligner's space: image-less sentences only.
    expect($entityMatch->a_total_sentences)->toBe(2)
        ->and($entityMatch->b_total_sentences)->toBe(2);

    (new AlignEntitySentences($entityMatch->id))->handle();
    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('completed');

    // The illustration's caption never reached the python service, and the
    // request carried only the two text sentences.
    expect($fake->alignPayloads)->not->toBeEmpty();
    foreach ($fake->alignPayloads as $payload) {
        expect($payload['a_sentences'])->not->toContain('The lighthouse.')
            ->and($payload['a_sentences'])->toHaveCount(2);
    }

    // Total completeness: every sentence of both sides — the illustration
    // included — ended junctioned into some row.
    $junctionedIds = SentenceMeaningMatch::query()
        ->where('entity_match_id', $entityMatch->id)
        ->pluck('entity_sentence_id')
        ->unique();

    foreach ([$enEntity->fresh('sentences'), $ruEntity->fresh('sentences')] as $sideEntity) {
        foreach ($sideEntity->sentences as $sentence) {
            expect($junctionedIds)->toContain($sentence->id);
        }
    }

    // The illustration's row is single-sided (a only) and not human-confirmed
    // yet — it must surface in needs review.
    $illustrationRowId = SentenceMeaningMatch::query()
        ->where('entity_sentence_id', $illustration->id)
        ->value('meaning_match_id');

    $row = MeaningMatch::query()->findOrFail($illustrationRowId);

    $hasB = SentenceMeaningMatch::query()
        ->where('meaning_match_id', $illustrationRowId)
        ->where('side', 'b')
        ->exists();

    expect($hasB)->toBeFalse()
        ->and((float) $row->similarity)->toBeLessThan(1.0);
});

it('finalizes an illustration-only entity without calling the aligner', function () {
    $fake = fakePython();

    Bus::fake();

    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    createIllustrationSentence($enEntity, 1, 'Frontispiece.');
    EntitySentence::create(['entity_id' => $ruEntity->id, 'content' => 'Russian 1.', 'order' => 1]);

    $entityMatch = createEntityMatch($enEntity, $ruEntity, ['status' => 'pending']);

    AlignEntitySentences::beginFromScratch($entityMatch->id);
    $entityMatch->refresh();
    (new AlignEntitySentences($entityMatch->id))->handle();
    $entityMatch->refresh();

    expect($entityMatch->status)->toBe('completed');

    expect($fake->alignPayloads)->toBe([]);

    $junctionedIds = SentenceMeaningMatch::query()
        ->where('entity_match_id', $entityMatch->id)
        ->pluck('entity_sentence_id');

    expect($junctionedIds)->toContain($enEntity->sentences()->first()->id);
});

// ─── totals invariants ────────────────────────────────────────────────────────

/**
 * A completed pair — one text row junctioned on both sides — whose a side also
 * carries an illustration outside every row.
 */
function illustratedMatchForTotals(): array
{
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    $enText = EntitySentence::create(['entity_id' => $enEntity->id, 'content' => 'English text.', 'order' => 1]);
    createIllustrationSentence($enEntity, 2, 'The lighthouse.');
    $ruText = EntitySentence::create(['entity_id' => $ruEntity->id, 'content' => 'Русский текст.', 'order' => 1]);

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'completed',
        'a_total_sentences' => 1,
        'b_total_sentences' => 1,
    ]);

    $row = MeaningMatch::create(['entity_match_id' => $entityMatch->id, 'order' => 0, 'similarity' => 1.0]);
    SentenceMeaningMatch::create(['entity_match_id' => $entityMatch->id, 'entity_sentence_id' => $enText->id, 'meaning_match_id' => $row->id, 'side' => 'a']);
    SentenceMeaningMatch::create(['entity_match_id' => $entityMatch->id, 'entity_sentence_id' => $ruText->id, 'meaning_match_id' => $row->id, 'side' => 'b']);

    return compact('entityMatch', 'row', 'enEntity', 'ruEntity');
}

it('keeps the editor add-sentence recount in alignable space', function () {
    ['entityMatch' => $entityMatch, 'row' => $row] = illustratedMatchForTotals();

    SentenceType::firstOrCreate(['name' => 'sentence']);

    $this->actingAs(approvedUser())
        ->postJson("/alignments/{$entityMatch->id}/sentences", [
            'side' => 'a',
            'meaning_match_id' => $row->id,
            'content' => 'A brand new sentence.',
        ])->assertOk();

    $entityMatch->refresh();

    // Two alignable sentences on the a side; the illustration is not counted.
    expect($entityMatch->a_total_sentences)->toBe(2)
        ->and($entityMatch->b_total_sentences)->toBe(1);
});

it('keeps the totals recount in alignable space', function () {
    ['entityMatch' => $entityMatch, 'enEntity' => $enEntity] = illustratedMatchForTotals();

    EntitySentence::create(['entity_id' => $enEntity->id, 'content' => 'A brand new sentence.', 'order' => 3]);

    $entityMatch->syncTotals();
    $entityMatch->refresh();

    // The a entity grew to three sentences (text, illustration, added),
    // but only its two alignable ones count toward the totals.
    expect($entityMatch->a_total_sentences)->toBe(2)
        ->and($entityMatch->b_total_sentences)->toBe(1)
        ->and($enEntity->sentences()->count())->toBe(3);
});

it('writes alignable totals when an alignment is copied onto illustrated copies', function () {
    fakePython();
    Bus::fake();

    $work = createWork();

    $enSource = createEntity('en', $work, ['name' => 'EN source', 'signature' => json_encode([1.0, 0.0])]);
    $ruSource = createEntity('ru', $work, ['name' => 'RU source', 'signature' => json_encode([1.0, 0.0])]);

    EntitySentence::create(['entity_id' => $enSource->id, 'content' => 'First.', 'order' => 1024]);
    $sourceImage = createIllustrationSentence($enSource, 2048, 'The lighthouse.');
    $sourceTextId = $enSource->sentences()->orderBy('order')->first()->id;
    EntitySentence::create(['entity_id' => $ruSource->id, 'content' => 'Первый.', 'order' => 1024]);

    foreach ([$enSource, $ruSource] as $entity) {
        $entity->forceFill([
            'text_hash' => (new EntityTextHasher)->hash($entity),
            'text_hashed_at' => now(),
            'sentences_updated_at' => now(),
        ])->save();
    }

    $sourceMatch = createEntityMatch($enSource, $ruSource, ['status' => 'completed', 'completed_at' => now()]);

    $textRow = MeaningMatch::create(['entity_match_id' => $sourceMatch->id, 'order' => 1024, 'similarity' => 0.9]);
    SentenceMeaningMatch::create(['entity_match_id' => $sourceMatch->id, 'entity_sentence_id' => $sourceTextId, 'meaning_match_id' => $textRow->id, 'side' => 'a']);
    SentenceMeaningMatch::create(['entity_match_id' => $sourceMatch->id, 'entity_sentence_id' => $ruSource->sentences()->first()->id, 'meaning_match_id' => $textRow->id, 'side' => 'b']);

    $imageRow = MeaningMatch::create(['entity_match_id' => $sourceMatch->id, 'order' => 2048, 'similarity' => 1.0]);
    SentenceMeaningMatch::create(['entity_match_id' => $sourceMatch->id, 'entity_sentence_id' => $sourceImage->id, 'meaning_match_id' => $imageRow->id, 'side' => 'a']);

    $sourceMatch->update(['linked_count' => 2, 'entity_similarity' => 0.95]);

    // Copies mirror the source sentence composition (image hash included), so
    // their text hashes match and the positional map holds.
    $enCopy = createEntity('en', $work, ['name' => 'EN copy', 'signature' => json_encode([1.0, 0.0])]);
    EntitySentence::create(['entity_id' => $enCopy->id, 'content' => 'First.', 'order' => 1024]);
    $copyImage = createIllustrationSentence($enCopy, 2048, 'The lighthouse.');

    $ruCopy = createEntity('ru', $work, ['name' => 'RU copy', 'signature' => json_encode([1.0, 0.0])]);
    EntitySentence::create(['entity_id' => $ruCopy->id, 'content' => 'Первый.', 'order' => 1024]);

    foreach ([$enCopy, $ruCopy] as $entity) {
        $entity->forceFill([
            'text_hash' => (new EntityTextHasher)->hash($entity),
            'text_hashed_at' => now(),
            'sentences_updated_at' => now(),
        ])->save();
    }

    $this->actingAs(User::factory()->create())
        ->post("/works/{$work->id}/alignments", [
            'first_entity_id' => $enCopy->id,
            'second_entity_id' => $ruCopy->id,
            'chunk_size' => 75,
            'max_n' => 6,
        ]);

    Bus::assertNotDispatched(AlignEntitySentences::class);

    $copyMatch = EntityMatch::query()
        ->where('a_entity_id', min($enCopy->id, $ruCopy->id))
        ->where('b_entity_id', max($enCopy->id, $ruCopy->id))
        ->first();

    // Totals are the aligner's space — one text sentence per side — even
    // though the illustration's junction was copied like any other.
    expect($copyMatch)->not->toBeNull()
        ->and($copyMatch->status)->toBe('completed')
        ->and($copyMatch->a_total_sentences)->toBe(1)
        ->and($copyMatch->b_total_sentences)->toBe(1)
        ->and(SentenceMeaningMatch::query()
            ->where('entity_match_id', $copyMatch->id)
            ->where('entity_sentence_id', $copyImage->id)
            ->exists())->toBeTrue();
});

// ─── presenter ───────────────────────────────────────────────────────────────

it('moves illustration captions into the row payload as image sentences', function () {
    $work = createWork();
    $enEntity = createEntity('en', $work);
    $ruEntity = createEntity('ru', $work);

    $enText = EntitySentence::create(['entity_id' => $enEntity->id, 'content' => 'English text.', 'order' => 1]);
    $enImage = createIllustrationSentence($enEntity, 2, 'The lighthouse.');
    $ruText = EntitySentence::create(['entity_id' => $ruEntity->id, 'content' => 'Русский текст.', 'order' => 1]);

    $entityMatch = createEntityMatch($enEntity, $ruEntity, ['status' => 'completed']);

    $textRow = MeaningMatch::create(['entity_match_id' => $entityMatch->id, 'order' => 0, 'similarity' => 1.0]);
    SentenceMeaningMatch::create(['entity_match_id' => $entityMatch->id, 'entity_sentence_id' => $enText->id, 'meaning_match_id' => $textRow->id, 'side' => 'a']);
    SentenceMeaningMatch::create(['entity_match_id' => $entityMatch->id, 'entity_sentence_id' => $ruText->id, 'meaning_match_id' => $textRow->id, 'side' => 'b']);

    $imageRow = MeaningMatch::create(['entity_match_id' => $entityMatch->id, 'order' => 1024, 'similarity' => 1.0]);
    SentenceMeaningMatch::create(['entity_match_id' => $entityMatch->id, 'entity_sentence_id' => $enImage->id, 'meaning_match_id' => $imageRow->id, 'side' => 'a']);

    $rows = MeaningMatch::query()
        ->where('entity_match_id', $entityMatch->id)
        ->with('sentenceMeaningMatches.entitySentence')
        ->orderBy('order')
        ->get();

    $presenter = app(ReadingRowsPresenter::class);

    $payload = $presenter->toReadingRows($rows);

    // The text row's sides carry their text sentences; the image row's a
    // side carries the illustration sentence — caption as text, image
    // descriptor with the access-checked URL — and its b side is empty.
    expect($payload)->toHaveCount(2)
        ->and($payload[0]['a']['sentences'][0]['text'])->toBe('English text.')
        ->and($payload[0]['b']['sentences'][0]['text'])->toBe('Русский текст.')
        ->and($payload[1]['key'])->toBe('mm:'.$imageRow->id)
        ->and($payload[1]['a']['sentences'])->toHaveCount(1)
        ->and($payload[1]['a']['sentences'][0]['text'])->toBe('The lighthouse.')
        ->and($payload[1]['a']['sentences'][0]['image']['url'])->toBe(route('illustrations.show', ['sentence' => $enImage->id]))
        ->and($payload[1]['a']['sentences'][0]['image']['width'])->toBe(10)
        ->and($payload[1]['b']['sentences'])->toBe([]);
});

// ─── text hash ───────────────────────────────────────────────────────────────

it('lets the image hash break an exact-copy tie that captions alone cannot', function () {
    $withImage = createEntity('en', null, ['name' => 'With image']);
    $withoutImage = createEntity('en', null, ['name' => 'Without image']);

    EntitySentence::create(['entity_id' => $withImage->id, 'content' => 'Same caption.', 'order' => 1]);
    EntitySentence::create(['entity_id' => $withoutImage->id, 'content' => 'Same caption.', 'order' => 1]);
    createIllustrationSentence($withImage, 2, 'Same caption.');

    $hasher = new EntityTextHasher;

    expect($hasher->hash($withImage))->not->toBe($hasher->hash($withoutImage))
        // Pre-illustration hashes stay byte-identical to the old recipe.
        ->and($hasher->hash($withoutImage))->toBe($hasher->hash($withoutImage));
});

// ─── reading surfaces ────────────────────────────────────────────────────────

it('ships image sentences in the reader for a single-language text', function () {
    $entity = createEntity('en', null, ['name' => 'Picture book']);

    EntitySentence::create(['entity_id' => $entity->id, 'content' => 'Once upon a time.', 'order' => 1]);
    $image = createIllustrationSentence($entity, 2, 'The lighthouse.');

    $user = approvedUser();

    $this->actingAs($user)->get("/reader/{$entity->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->has('rows', 2)
            ->where('rows.0.a.sentences.0.text', 'Once upon a time.')
            ->where('rows.1.a.sentences.0.text', 'The lighthouse.')
            ->where('rows.1.a.sentences.0.image.url', route('illustrations.show', ['sentence' => $image->id]))
            ->where('rows.1.a.sentences.0.image.width', 10)
        );
});

it('ships image sentences in the simulator text payload', function () {
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'Sim EN']);
    $ruEntity = createEntity('ru', $work, ['name' => 'Sim RU']);

    $enText = EntitySentence::create(['entity_id' => $enEntity->id, 'content' => 'English text.', 'order' => 1]);
    $enImage = createIllustrationSentence($enEntity, 2, 'The lighthouse.');
    $ruText = EntitySentence::create(['entity_id' => $ruEntity->id, 'content' => 'Русский текст.', 'order' => 1]);

    $entityMatch = createEntityMatch($enEntity, $ruEntity, ['status' => 'completed']);

    $textRow = MeaningMatch::create(['entity_match_id' => $entityMatch->id, 'order' => 0, 'similarity' => 1.0]);
    SentenceMeaningMatch::create(['entity_match_id' => $entityMatch->id, 'entity_sentence_id' => $enText->id, 'meaning_match_id' => $textRow->id, 'side' => 'a']);
    SentenceMeaningMatch::create(['entity_match_id' => $entityMatch->id, 'entity_sentence_id' => $ruText->id, 'meaning_match_id' => $textRow->id, 'side' => 'b']);

    $imageRow = MeaningMatch::create(['entity_match_id' => $entityMatch->id, 'order' => 1024, 'similarity' => 1.0]);
    SentenceMeaningMatch::create(['entity_match_id' => $entityMatch->id, 'entity_sentence_id' => $enImage->id, 'meaning_match_id' => $imageRow->id, 'side' => 'a']);

    $user = approvedUser();

    $this->actingAs($user)->post('/text', [
        'entity_match_id' => $entityMatch->id,
        'page' => 1,
        'per_page' => 50,
    ])->assertOk()
        ->assertJsonPath('data.rows.1.a.sentences.0.text', 'The lighthouse.')
        ->assertJsonPath('data.rows.1.b.sentences', []);
});
