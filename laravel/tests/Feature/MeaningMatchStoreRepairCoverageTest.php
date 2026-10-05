<?php

use App\Classes\MeaningMatchStore;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedCoverageSentence($entity, SentenceType $sentenceType, int $order, string $label): EntitySentence
{
    return EntitySentence::create([
        'entity_id' => $entity->id,
        'sentence_type_id' => $sentenceType->id,
        'content' => $label,
        'order' => $order,
    ]);
}

/**
 * Every sentence's junction count keyed by content, across both entities.
 *
 * @param  array<int, EntitySentence>  $sentences
 * @return array<string, int>
 */
function coverageJunctionCounts(array $sentences): array
{
    $counts = [];

    foreach ($sentences as $sentence) {
        $counts[$sentence->content] = $sentence->refresh()->meaningJunctions()->count();
    }

    return $counts;
}

it('backfills junction-less sentences on both sides and nudges colliding spread orders', function () {
    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English']);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian']);

    $sentences = [
        seedCoverageSentence($enEntity, $sentenceType, 1, 'EN 1'),
        seedCoverageSentence($enEntity, $sentenceType, 2, 'EN 2'),
        seedCoverageSentence($enEntity, $sentenceType, 3, 'EN 3'),
        seedCoverageSentence($ruEntity, $sentenceType, 1, 'RU 1'),
        seedCoverageSentence($ruEntity, $sentenceType, 2, 'RU 2'),
        seedCoverageSentence($ruEntity, $sentenceType, 3, 'RU 3'),
    ];

    $entityMatch = createEntityMatch($enEntity, $ruEntity, [
        'status' => 'aligning',
        'a_total_sentences' => 3,
        'b_total_sentences' => 3,
        'linked_count' => 1,
    ]);

    // One two-sided row; EN 2/3 and RU 2/3 are junction-less runs between
    // the same pair of anchors — without the claimed-orders set both sides'
    // backfills would compute identical spread orders and the second side
    // would violate unique(entity_match_id, order).
    $row = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id, 'order' => 0, 'similarity' => 0.9, 'alignment_chunk' => 0,
    ]);
    SentenceMeaningMatch::create(['entity_sentence_id' => $sentences[0]->id, 'meaning_match_id' => $row->id, 'side' => 'a']);
    SentenceMeaningMatch::create(['entity_sentence_id' => $sentences[3]->id, 'meaning_match_id' => $row->id, 'side' => 'b']);

    [$created, $resequenced] = MeaningMatchStore::create()->repairCoverage($entityMatch);

    expect($created)->toBe(4, 'one single-sided row per junction-less sentence')
        ->and($resequenced)->toBe(2, 'only the nudged RU rows move during normalization');

    // Total completeness: every sentence of either side junctioned exactly once.
    expect(coverageJunctionCounts($sentences))->toBe([
        'EN 1' => 1, 'EN 2' => 1, 'EN 3' => 1,
        'RU 1' => 1, 'RU 2' => 1, 'RU 3' => 1,
    ])->and(EntitySentence::query()->whereDoesntHave('meaningJunctions')
        ->whereIn('entity_id', [$enEntity->id, $ruEntity->id])->count())->toBe(0)
        ->and($entityMatch->refresh()->linked_count)->toBe(5);

    // Backfilled rows are single-sided with similarity 0.0 on the right side.
    foreach ([['EN 2', 'a'], ['EN 3', 'a'], ['RU 2', 'b'], ['RU 3', 'b']] as [$content, $side]) {
        $junction = EntitySentence::query()->where('content', $content)->firstOrFail()
            ->meaningJunctions()->first();

        expect($junction->side)->toBe($side)
            ->and((float) $junction->meaningMatch->similarity)->toBe(0.0)
            ->and($junction->meaningMatch->sentenceMeaningMatches->pluck('side')->all())->toBe([$side]);
    }

    // Document order survives normalization across both sides.
    $orderOf = fn (string $content): int => (int) EntitySentence::query()->where('content', $content)
        ->firstOrFail()->meaningJunctions()->first()->meaningMatch->order;

    expect($orderOf('EN 1'))->toBe(0)
        ->and($orderOf('EN 2'))->toBeLessThan($orderOf('EN 3'))
        ->and($orderOf('EN 3'))->toBeLessThan($orderOf('RU 2'))
        ->and($orderOf('RU 2'))->toBeLessThan($orderOf('RU 3'));

    // Idempotent: a second repair creates and changes nothing.
    expect(MeaningMatchStore::create()->repairCoverage($entityMatch))->toBe([0, 0]);
});

it('is a no-op for a fully junctioned match', function () {
    $sentenceType = SentenceType::create(['name' => 'Narration']);
    $work = createWork();
    $enEntity = createEntity('en', $work, ['name' => 'English']);
    $ruEntity = createEntity('ru', $work, ['name' => 'Russian']);

    $enSentence = seedCoverageSentence($enEntity, $sentenceType, 1, 'EN 1');
    $ruSentence = seedCoverageSentence($ruEntity, $sentenceType, 1, 'RU 1');

    $entityMatch = createEntityMatch($enEntity, $ruEntity, ['status' => 'completed']);

    $row = MeaningMatch::create([
        'entity_match_id' => $entityMatch->id, 'order' => 0, 'similarity' => 0.9, 'alignment_chunk' => 0,
    ]);
    SentenceMeaningMatch::create(['entity_sentence_id' => $enSentence->id, 'meaning_match_id' => $row->id, 'side' => 'a']);
    SentenceMeaningMatch::create(['entity_sentence_id' => $ruSentence->id, 'meaning_match_id' => $row->id, 'side' => 'b']);

    expect(MeaningMatchStore::create()->repairCoverage($entityMatch))->toBe([0, 0])
        ->and(MeaningMatch::query()->where('entity_match_id', $entityMatch->id)->count())->toBe(1);
});
