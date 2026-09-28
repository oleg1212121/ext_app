<?php

use App\Classes\EntityTextHasher;
use App\Classes\MeaningMatchPresenter;
use App\Jobs\AlignEntitySentences;
use App\Models\Entity;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

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
    $capturedPayloads = [];

    Http::fake(function (Request $request) use (&$capturedPayloads) {
        $capturedPayloads[] = $request->data();

        return Http::response([
            'matches' => [
                ['a_start' => 0, 'a_end' => 1, 'b_start' => 0, 'b_end' => 1, 'score' => 0.9],
                ['a_start' => 1, 'a_end' => 2, 'b_start' => 1, 'b_end' => 2, 'score' => 0.9],
            ],
        ]);
    });

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
    expect($capturedPayloads)->not->toBeEmpty();
    foreach ($capturedPayloads as $payload) {
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
    Http::fake();

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

    Http::assertNothingSent();

    $junctionedIds = SentenceMeaningMatch::query()
        ->where('entity_match_id', $entityMatch->id)
        ->pluck('entity_sentence_id');

    expect($junctionedIds)->toContain($enEntity->sentences()->first()->id);
});

// ─── presenter ───────────────────────────────────────────────────────────────

it('moves illustration captions out of row text and into the image payload', function () {
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

    $presenter = new MeaningMatchPresenter;

    $text = $presenter->toSimulatorRows($rows);

    expect($text)->toBe([
        ['English text.', 'Русский текст.'],
        ['', ''],
    ]);

    $images = $presenter->toSimulatorImages($rows);

    expect($images)->toHaveCount(2)
        ->and($images[0])->toBe([[], []])
        ->and($images[1][0])->toHaveCount(1)
        ->and($images[1][0][0]['caption'])->toBe('The lighthouse.')
        ->and($images[1][0][0]['url'])->toBe(route('illustrations.show', ['sentence' => $enImage->id]))
        ->and($images[1][0][0]['width'])->toBe(10)
        ->and($images[1][1])->toBe([]);
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

it('ships row images in the reader for a single-language text', function () {
    $entity = createEntity('en', null, ['name' => 'Picture book']);

    EntitySentence::create(['entity_id' => $entity->id, 'content' => 'Once upon a time.', 'order' => 1]);
    createIllustrationSentence($entity, 2, 'The lighthouse.');

    $user = approvedUser();

    $this->actingAs($user)->get("/reader/{$entity->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reader')
            ->where('rows', [['Once upon a time.', ''], ['', '']])
            ->has('rowImages', 2)
            ->where('rowImages.0', [[], []])
            ->where('rowImages.1.0.0.caption', 'The lighthouse.')
            ->where('rowImages.1.0.0.width', 10)
        );
});

it('ships row_images in the simulator text payload', function () {
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
    ])->assertOk()->assertJsonPath('data.code', 200)
        ->assertJsonPath('data.data.rows.1', ['', ''])
        ->assertJsonPath('data.data.row_images.1.0.0.caption', 'The lighthouse.');
});
