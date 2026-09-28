<?php

use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use Illuminate\Support\Facades\DB;

function seedRepairSentence($entity, SentenceType $sentenceType, int $order, string $label): EntitySentence
{
    return EntitySentence::create([
        'entity_id' => $entity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => $label,
        'order' => $order,
    ]);
}

/**
 * Duplicate junctions cannot be seeded while the strict unique constraint
 * holds — suspend it to stage the legacy state the command repairs.
 */
function suspendRepairJunctionUniqueness(): void
{
    DB::statement('ALTER TABLE sentence_meaning_matches DROP CONSTRAINT smm_match_sentence_unique');
}

it('repairs duplicate junctions, backfills single-sided rows on both sides and resequences', function () {
    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    $enSentences = [];
    $ruSentences = [];

    foreach (range(1, 3) as $order) {
        $enSentences[] = seedRepairSentence($enEntity, $sentenceType, $order, "English {$order}.");
        $ruSentences[] = seedRepairSentence($ruEntity, $sentenceType, $order, "Russian {$order}.");
    }

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'completed',
        'a_total_sentences' => 3,
        'b_total_sentences' => 3,
        'linked_count' => 1,
    ]);

    // Legacy garbage: two rows claim EN 1 (the weaker one also holds RU 2),
    // EN 3 and RU 3 are junction-less (RU is the translation side — the old
    // original-completeness repair never covered it), and the stored order is
    // reversed relative to document position.
    suspendRepairJunctionUniqueness();

    $strongRow = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id, 'order' => 4096, 'similarity' => 0.5, 'alignment_chunk' => 0,
    ]);
    SentenceMeaningMatch::create(['entity_sentence_id' => $enSentences[0]->id, 'meaning_match_id' => $strongRow->id, 'side' => 'a']);
    SentenceMeaningMatch::create(['entity_sentence_id' => $ruSentences[0]->id, 'meaning_match_id' => $strongRow->id, 'side' => 'b']);

    $weakRow = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id, 'order' => 2048, 'similarity' => 0.3, 'alignment_chunk' => 1,
    ]);
    SentenceMeaningMatch::create(['entity_sentence_id' => $enSentences[0]->id, 'meaning_match_id' => $weakRow->id, 'side' => 'a']);
    SentenceMeaningMatch::create(['entity_sentence_id' => $ruSentences[1]->id, 'meaning_match_id' => $weakRow->id, 'side' => 'b']);

    $this->artisan('alignments:repair', ['entityMatch' => $entityMatch->id])->assertSuccessful();

    // Strict uniqueness: every sentence junctioned at most once.
    foreach ([...$enSentences, ...$ruSentences] as $sentence) {
        expect($sentence->refresh()->meaningJunctions()->count())->toBe(1, "sentence {$sentence->id} must have exactly one junction");
    }

    $weakRow->refresh();
    expect($weakRow->sentenceMeaningMatches->pluck('side')->all())->toBe(['b'], 'the weaker row keeps only its own junction')
        ->and($entityMatch->refresh()->linked_count)->toBe(5, 'two original rows + three backfilled single-sided rows (EN 2, EN 3, RU 3)')
        ->and(EntitySentence::whereDoesntHave('meaningJunctions')->whereIn('entity_id', [$enEntity->id, $ruEntity->id])->count())->toBe(0, 'total completeness: both sides fully junctioned');

    // The backfilled single-sided rows carry similarity 0.0 and sit in
    // document order among the other rows.
    $enOnly = $enSentences[2]->meaningJunctions()->first()->meaningMatch;
    $ruOnly = $ruSentences[2]->meaningJunctions()->first()->meaningMatch;

    expect((float) $enOnly->similarity)->toBe(0.0)
        ->and((float) $ruOnly->similarity)->toBe(0.0)
        ->and($enOnly->sentenceMeaningMatches->pluck('side')->all())->toBe(['a'])
        ->and($ruOnly->sentenceMeaningMatches->pluck('side')->all())->toBe(['b'])
        ->and($strongRow->refresh()->order)->toBe(0, 'document-first row resequenced to the front')
        ->and($enOnly->order)->toBeGreaterThan($strongRow->order)
        ->and($ruOnly->order)->toBeGreaterThan($enOnly->order);

    // Idempotent: a second repair reports zero removed/created.
    $this->artisan('alignments:repair', ['entityMatch' => $entityMatch->id])
        ->expectsOutputToContain('0 duplicate junction row(s) removed, 0 single-sided row(s) created')
        ->assertSuccessful();
});

it('repairs every match with --all and fails for a missing id', function () {
    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English', 'signature' => json_encode([1.0, 0.0])]);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian', 'signature' => json_encode([1.0, 0.0])]);

    $enSentence = seedRepairSentence($enEntity, $sentenceType, 1, 'English 1.');
    seedRepairSentence($ruEntity, $sentenceType, 1, 'Russian 1.');

    createEntityMatch($enEntity, $ruEntity, ['status' => 'completed']);

    $this->artisan('alignments:repair', ['--all' => true])->assertSuccessful();

    expect($enSentence->refresh()->meaningJunctions()->count())->toBe(1, 'junction-less EN sentence backfilled');

    $this->artisan('alignments:repair', ['entityMatch' => 99999])->assertFailed();
});
