<?php

use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('guests are redirected from the alignment editor', function () {
    $work = createWork();
    $entityMatch = createEntityMatch(
        createEntity('en', $work, ['name' => 'English text']),
        createEntity('ru', $work, ['name' => 'Russian text']),
        ['status' => 'pending'],
    );

    $this->get(route('works.alignments.edit', ['work' => $work->id, 'entityMatch' => $entityMatch]))
        ->assertRedirect(route('login'));
});

test('the global alignments pages and the flat editor route are gone', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/alignments')->assertNotFound();
    $this->actingAs($user)->get('/alignments/create')->assertNotFound();
    $this->actingAs($user)->post('/alignments', [])->assertNotFound();
    $this->actingAs($user)->get('/alignments/18')->assertNotFound();
    $this->actingAs($user)->getJson('/alignments/18/rows')->assertNotFound();
});

test('the editor 404s when the match path names another work', function () {
    $user = User::factory()->create();

    $work = createWork();
    $otherWork = createWork(['title' => 'Other work']);
    $entityMatch = createEntityMatch(
        createEntity('en', $work, ['name' => 'English text']),
        createEntity('ru', $work, ['name' => 'Russian text']),
        ['status' => 'pending'],
    );

    $this->actingAs($user)
        ->get("/works/{$otherWork->id}/alignments/{$entityMatch->id}/edit")
        ->assertNotFound();
});

test('the per-work alignments page lists the work\'s readable entity matches', function () {
    $user = User::factory()->create();

    $work = createWork();
    $entityMatch = createEntityMatch(
        createEntity('en', $work, ['name' => 'English chapter']),
        createEntity('ru', $work, ['name' => 'Russian chapter']),
        [
            'status' => 'completed',
            'entity_similarity' => 0.9123,
            'a_total_sentences' => 8,
            'b_total_sentences' => 7,
            'linked_count' => 6,
        ],
    );
    createEntityMatch(
        createEntity('en', createWork(['title' => 'Other work']), ['name' => 'Other EN']),
        createEntity('ru', createWork(['title' => 'Other work 2']), ['name' => 'Other RU']),
        ['status' => 'completed'],
    );

    $response = $this
        ->actingAs($user)
        ->get("/works/{$work->id}/alignments");

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Library/WorkAlignments')
        ->has('alignments', 1)
        ->where('alignments.0.id', $entityMatch->id)
        ->where('alignments.0.a_entity_name', 'English chapter')
        ->where('alignments.0.b_entity_name', 'Russian chapter')
        ->where('alignments_meta.total', 1));
});

test('users cannot see restricted entity matches they are not granted', function () {
    $user = User::factory()->create();

    $work = createWork();
    $enEntity = createEntity('en', $work, [
        'name' => 'Restricted EN',
        'is_restricted' => true,
    ]);

    $ruEntity = createEntity('ru', $work, [
        'name' => 'Restricted RU',
        'is_restricted' => true,
    ]);

    $entityMatch = createEntityMatch($enEntity, $ruEntity, ['status' => 'completed']);

    // Not granted → the match must not leak into the work's alignments page.
    $this->actingAs($user)
        ->get("/works/{$work->id}/alignments")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('alignments', 0));

    // Not granted → opening the detail page is forbidden.
    $this->actingAs($user)
        ->get(route('works.alignments.edit', ['work' => $work->id, 'entityMatch' => $entityMatch]))
        ->assertForbidden();

    // Granted on both sides → the match becomes visible.
    $enEntity->grantedUsers()->attach($user->id);
    $ruEntity->grantedUsers()->attach($user->id);

    $this->actingAs($user)
        ->get("/works/{$work->id}/alignments")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('alignments', 1)
            ->where('alignments.0.a_entity_name', 'Restricted EN'));
});

test('authenticated users can view alignment details', function () {
    $user = User::factory()->create();
    $sentenceType = SentenceType::create([
        'name' => 'Narration',
    ]);

    $work = createWork();
    $enEntity = createEntity('en', $work, [
        'name' => 'English chapter',
    ]);

    $ruEntity = createEntity('ru', $work, [
        'name' => 'Russian chapter',
    ]);

    $enSentence = EntitySentence::create([
        'entity_id' => $enEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'The first English sentence.',
        'order' => 1,
    ]);

    $ruSentence = EntitySentence::create([
        'entity_id' => $ruEntity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'Первое русское предложение.',
        'order' => 1,
    ]);

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'completed',
        'entity_similarity' => 0.9345,
        'a_total_sentences' => 1,
        'b_total_sentences' => 1,
        'linked_count' => 1,
    ]);

    $meaningMatch = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 1,
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

    $response = $this
        ->actingAs($user)
        ->get(route('works.alignments.edit', ['work' => $work->id, 'entityMatch' => $entityMatch]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Alignments/Show')
        ->where('match.a_entity_name', 'English chapter')
        ->where('match.b_entity_name', 'Russian chapter')
        ->where('match.work_id', $work->id)
        ->has('rows.0.a_sentences', 1)
        ->where('rows.0.a_sentences.0.content', 'The first English sentence.')
        ->has('rows.0.b_sentences', 1)
        ->where('rows.0.b_sentences.0.content', 'Первое русское предложение.')
        ->where('needs_review.meta.total', 0));
});

test('the editor seeds each section\'s page from the URL query', function () {
    $user = User::factory()->create();
    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $entityMatch = createEntityMatch(
        createEntity('en', $work, ['name' => 'EN']),
        createEntity('ru', $work, ['name' => 'RU']),
        ['status' => 'pending'],
    );

    // 12 rows × 2 sentences, per_page=10 → rows page 2 exists. Row 12 sits on
    // rows page 2 and is low-similarity, so needs-review page 1 holds it and
    // review_page=2 is past the end (clamped back to 1).
    for ($i = 1; $i <= 12; $i++) {
        $mm = MeaningMatch::create([
            'entity_match_id' => $entityMatch->id,
            'order' => $i,
            'similarity' => 0.1,
            'alignment_chunk' => 0,
        ]);

        foreach (['a' => $entityMatch->a_entity_id, 'b' => $entityMatch->b_entity_id] as $side => $entityId) {
            SentenceMeaningMatch::create([
                'entity_sentence_id' => EntitySentence::create([
                    'entity_id' => $entityId,
                    'sentence_type_id' => $sentenceType->id,
                    'content' => "Sentence {$i} ({$side}).",
                    'order' => $i,
                ])->id,
                'meaning_match_id' => $mm->id,
                'side' => $side,
            ]);
        }
    }

    $response = $this
        ->actingAs($user)
        ->get("/works/{$work->id}/alignments/{$entityMatch->id}/edit?rows_page=2&rows_per_page=10&review_page=2");

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Alignments/Show')
        ->where('rows_meta.current_page', 2)
        ->where('rows_meta.per_page', 10)
        ->where('needs_review.meta.current_page', 1)
        ->where('needs_review.meta.total', 12));
});

test('the editor clamps a hand-edited out-of-range rows page to the last page', function () {
    $user = User::factory()->create();
    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $entityMatch = createEntityMatch(
        createEntity('en', $work, ['name' => 'EN']),
        createEntity('ru', $work, ['name' => 'RU']),
        ['status' => 'pending'],
    );

    for ($i = 1; $i <= 3; $i++) {
        MeaningMatch::create([
            'entity_match_id' => $entityMatch->id,
            'order' => $i,
            'similarity' => 1.0,
            'alignment_chunk' => 0,
        ]);
    }

    $response = $this
        ->actingAs($user)
        ->get("/works/{$work->id}/alignments/{$entityMatch->id}/edit?rows_page=99&rows_per_page=10");

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Alignments/Show')
        ->where('rows_meta.current_page', 1)
        ->where('rows_meta.last_page', 1));
});

test('rows endpoint returns sentences_before offset for page 1', function () {
    $user = User::factory()->create();
    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $entityMatch = createEntityMatch(
        createEntity('en', $work, ['name' => 'EN']),
        createEntity('ru', $work, ['name' => 'RU']),
        ['status' => 'pending'],
    );

    $mm = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id,
        'order' => 1,
        'similarity' => 1.0,
        'alignment_chunk' => 0,
    ]);

    $enSentence = EntitySentence::create([
        'entity_id' => $entityMatch->a_entity_id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'Hello.',
        'order' => 1,
    ]);

    $ruSentence = EntitySentence::create([
        'entity_id' => $entityMatch->b_entity_id,
        'sentence_type_id' => $sentenceType->id,
        'content' => 'Привет.',
        'order' => 1,
    ]);

    SentenceMeaningMatch::create([
        'entity_sentence_id' => $enSentence->id,
        'meaning_match_id' => $mm->id,
        'side' => 'a',
    ]);

    SentenceMeaningMatch::create([
        'entity_sentence_id' => $ruSentence->id,
        'meaning_match_id' => $mm->id,
        'side' => 'b',
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson("/works/{$work->id}/alignments/{$entityMatch->id}/rows?page=1&per_page=10");

    $response->assertOk();
    $response->assertJsonPath('sentences_before.a', 0);
    $response->assertJsonPath('sentences_before.b', 0);
});

test('rows endpoint returns correct sentences_before for page 2', function () {
    $user = User::factory()->create();
    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $entityMatch = createEntityMatch(
        createEntity('en', $work, ['name' => 'EN']),
        createEntity('ru', $work, ['name' => 'RU']),
        ['status' => 'pending'],
    );

    // Create 12 meaning matches each with 1 EN + 1 RU sentence.
    // per_page=10 → page 1 has 10 rows (20 sentences), page 2 has 2 rows (4 sentences).
    for ($i = 1; $i <= 12; $i++) {
        $mm = MeaningMatch::create([
            'entity_match_id' => $entityMatch->id,
            'order' => $i,
            'similarity' => 1.0,
            'alignment_chunk' => 0,
        ]);

        $enSentence = EntitySentence::create([
            'entity_id' => $entityMatch->a_entity_id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "EN sentence {$i}.",
            'order' => $i,
        ]);

        $ruSentence = EntitySentence::create([
            'entity_id' => $entityMatch->b_entity_id,
            'sentence_type_id' => $sentenceType->id,
            'content' => "RU sentence {$i}.",
            'order' => $i,
        ]);

        SentenceMeaningMatch::create([
            'entity_sentence_id' => $enSentence->id,
            'meaning_match_id' => $mm->id,
            'side' => 'a',
        ]);

        SentenceMeaningMatch::create([
            'entity_sentence_id' => $ruSentence->id,
            'meaning_match_id' => $mm->id,
            'side' => 'b',
        ]);
    }

    $response = $this
        ->actingAs($user)
        ->getJson("/works/{$work->id}/alignments/{$entityMatch->id}/rows?page=2&per_page=10");

    $response->assertOk();
    $response->assertJsonPath('sentences_before.a', 10);
    $response->assertJsonPath('sentences_before.b', 10);
    $response->assertJsonPath('meta.current_page', 2);
});
