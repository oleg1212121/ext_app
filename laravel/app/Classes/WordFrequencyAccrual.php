<?php

namespace App\Classes;

use App\Models\Entity;
use App\Models\EntityWord;
use Illuminate\Support\Facades\DB;

/**
 * The one-time entity frequency correction: a word's frequency rank is
 * pulled 2% of its own value toward its position in the entity's word list
 * (position 1 = most frequent in that text), clamped so the pull never
 * overshoots the position and floored at rank 1. Every word-class row of
 * the headword moves together. Each entity is processed exactly once —
 * entities.frequency_counted_at is the marker — and later re-indexes do
 * not re-apply (entity_words is rebuilt wholesale, so positions drift).
 */
class WordFrequencyAccrual
{
    private const STEP_RATIO = 0.02;

    /**
     * Apply the entity's correction and stamp its marker. Returns the
     * number of linked word-list entries that were processed.
     */
    public function accrue(Entity $entity): int
    {
        $lWords = EntityWord::query()
            ->where('entity_id', $entity->getKey())
            ->whereNotNull('word_id')
            ->orderByDesc('count')
            ->orderBy('l_word')
            ->pluck('l_word');

        DB::transaction(function () use ($entity, $lWords): void {
            foreach ($lWords as $index => $lWord) {
                $this->pull((int) $index + 1, (int) $entity->language_id, $lWord);
            }

            $entity->forceFill(['frequency_counted_at' => now()])->save();
        });

        return $lWords->count();
    }

    /**
     * Single self-contained statement so the rank is read and written
     * atomically: step = 2% of the current frequency toward the position.
     */
    private function pull(int $position, int $languageId, string $lWord): void
    {
        DB::update(
            'update words set frequency = greatest(1, frequency'
            .' + least(greatest(?::numeric - frequency, -(abs(frequency) * '.self::STEP_RATIO.')), abs(frequency) * '.self::STEP_RATIO.'))'
            .' where language_id = ? and l_word = ?',
            [$position, $languageId, $lWord],
        );
    }
}
