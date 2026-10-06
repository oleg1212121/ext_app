<?php

namespace App\Classes;

use App\Enums\SentenceAnchor;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\SentenceType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * The entity-sentence write path: every insert / update / delete / reorder
 * of an entity's sentences that happens outside the alignment editor goes
 * through this store, and the store owns the whole mutation flow —
 * placement, the write itself, the stale flip, and the totals resync —
 * inside one transaction (ADR 0065). A sentence-set change made through
 * any door is therefore atomic and can never leave a match's stale flag
 * or totals half-applied.
 *
 * The alignment editor is exempt by design (ADR 0062): its row and
 * junction mutations have their own invariants and never flip a match
 * stale, so it writes through AlignmentEditorService. The importer is
 * exempt too: it wipes and rebuilds inside its own transaction and marks
 * the match completed itself.
 *
 * The text hash needs no store-level work: the sentence model events bump
 * sentences_updated_at and SentenceOrderService bumps it for order
 * changes (ADR 0033).
 */
class EntitySentenceStore
{
    public function __construct(
        private readonly SentenceOrderService $sentenceOrder,
        private readonly IllustrationStorage $illustrations,
    ) {}

    /**
     * Create a sentence at the anchor position of the entity's document
     * order. A stray image on a non-illustration type is ignored.
     */
    public function insert(Entity $entity, array $attributes, SentenceAnchor $anchor, ?UploadedFile $image = null): EntitySentence
    {
        $image = $this->isIllustrationType((int) ($attributes['sentence_type_id'] ?? 0)) ? $image : null;

        return DB::transaction(function () use ($entity, $attributes, $anchor, $image): EntitySentence {
            $order = $this->sentenceOrder->place($entity->id, $anchor);

            $sentence = EntitySentence::query()->create([
                ...$attributes,
                'entity_id' => $entity->id,
                'order' => $order,
                ...$this->imageAttributes($image, $entity->language->code),
            ]);

            $this->propagateSentenceSetChange($entity->id);

            return $sentence;
        });
    }

    /**
     * Update a sentence's content/type/image and, when $anchor is given,
     * move it to that document-order position first. A new image applies
     * only to sentences that are (or become) illustrations — a stray file
     * on a plain sentence is ignored, and the replaced image file is
     * released after the transaction commits.
     */
    public function update(EntitySentence $sentence, array $attributes, ?SentenceAnchor $anchor = null, ?UploadedFile $image = null): EntitySentence
    {
        $imageApplies = $sentence->isIllustration()
            || $this->isIllustrationType((int) ($attributes['sentence_type_id'] ?? $sentence->sentence_type_id));

        $newImage = $imageApplies ? $image : null;
        $oldPath = $sentence->image_path;

        DB::transaction(function () use ($sentence, $attributes, $anchor, $newImage): void {
            if ($anchor !== null) {
                $this->sentenceOrder->place((int) $sentence->entity_id, $anchor, $sentence->getKey());
            }

            $sentence->update([
                ...$attributes,
                ...$this->imageAttributes($newImage, $sentence->entity->language->code),
            ]);

            $this->propagateSentenceSetChange((int) $sentence->entity_id);
        });

        if ($newImage !== null && $oldPath !== null) {
            $this->illustrations->releaseIfOrphaned($oldPath);
        }

        return $sentence->refresh();
    }

    /**
     * Delete a sentence. The model's deleting/deleted events release the
     * junctions, delete emptied meaning matches, resync linked_count, and
     * bump sentences_updated_at; the stale flip and totals resync join
     * them in the same transaction.
     */
    public function delete(EntitySentence $sentence): void
    {
        DB::transaction(function () use ($sentence): void {
            $sentence->delete();

            $this->propagateSentenceSetChange((int) $sentence->entity_id);
        });
    }

    /**
     * Delete many sentences in one transaction — a bulk writer must still
     * go through the model (not a bulk query) so the deleting/deleted
     * events run per sentence, and every touched entity is propagated once.
     *
     * @param  iterable<EntitySentence>  $sentences
     */
    public function deleteMany(iterable $sentences): void
    {
        DB::transaction(function () use ($sentences): void {
            $entityIds = [];

            foreach ($sentences as $sentence) {
                $entityIds[(int) $sentence->entity_id] = true;

                $sentence->delete();
            }

            foreach (array_keys($entityIds) as $entityId) {
                $this->propagateSentenceSetChange($entityId);
            }
        });
    }

    /**
     * Move a sentence to the anchor position of the entity's document
     * order. The placement engine renumbers so the sentence sorts exactly
     * at the anchor and every order stays collision-free.
     */
    public function reorder(EntitySentence $sentence, SentenceAnchor $anchor): EntitySentence
    {
        DB::transaction(function () use ($sentence, $anchor): void {
            $this->sentenceOrder->place((int) $sentence->entity_id, $anchor, $sentence->getKey());

            $this->propagateSentenceSetChange((int) $sentence->entity_id);
        });

        return $sentence->refresh();
    }

    /**
     * The sentence-set-change propagation, owned here so no caller composes
     * it by hand: flip every EntityMatch involving the entity to stale and
     * resync its image-less totals.
     */
    private function propagateSentenceSetChange(int $entityId): void
    {
        $this->markMatchesStale($entityId);
        EntityMatch::syncTotalsForEntity($entityId);
    }

    /**
     * Flip every EntityMatch involving this entity to status = 'stale',
     * surfacing the need to re-align. Stale is display-only: the scheduler
     * never picks it up, so only an explicit Re-align re-aligns. Fresh
     * matches stay 'pending' (their one automatic run is the feature). See
     * ADR 0055, superseding ADR 0015's pending-on-edit rule.
     */
    private function markMatchesStale(int $entityId): void
    {
        EntityMatch::query()
            ->where(function (Builder $query) use ($entityId): void {
                $query->where('a_entity_id', $entityId)
                    ->orWhere('b_entity_id', $entityId);
            })
            ->whereIn('status', ['aligning', 'completed', 'failed'])
            ->update(['status' => 'stale']);
    }

    /**
     * Whether the submitted type is the seeded illustration type.
     */
    private function isIllustrationType(int $typeId): bool
    {
        $illustrationId = SentenceType::illustrationId();

        return $illustrationId !== null && $typeId === $illustrationId;
    }

    /**
     * Sentence columns for an uploaded illustration; empty for plain
     * sentences (a stray file on a non-illustration type is ignored).
     *
     * @return array{image_path?: string, image_hash?: string, image_width?: ?int, image_height?: ?int, image_mime?: ?string}
     */
    private function imageAttributes(?UploadedFile $image, string $langCode): array
    {
        if ($image === null) {
            return [];
        }

        $stored = $this->illustrations->store($image, $langCode);

        return [
            'image_path' => $stored['path'],
            'image_hash' => $stored['hash'],
            'image_width' => $stored['width'],
            'image_height' => $stored['height'],
            'image_mime' => $stored['mime'],
        ];
    }
}
