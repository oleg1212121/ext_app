<?php

namespace App\Classes;

use App\Enums\SentenceAnchor;
use App\Models\Entity;
use App\Models\EntitySentence;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * The entity-sentence placement pipeline: assign one sentence a document
 * order so it sorts exactly at the anchor position, rebalancing the
 * neighbourhood through SparseOrderService when the surrounding sparse gap
 * is exhausted, shifting the result non-negative, and persisting every
 * changed order two-phase — parked at unique negatives first — so the
 * (entity_id, order) unique index never sees a transient collision
 * mid-write.
 *
 * SparseOrderService stays the pure, model-agnostic primitive layer; this
 * class is everything entity-sentence specific. Meaning-match rows have
 * their own write paths (AlignmentEditorService for the editor,
 * MeaningMatchStore for the pipeline).
 *
 * Bulk order writes bypass model events, so the service bumps
 * sentences_updated_at itself whenever an order actually changed: the text
 * hash covers sentence contents in document order (ADR 0033).
 */
class SentenceOrderService
{
    public function __construct(private readonly SparseOrderService $sparseOrder) {}

    /**
     * Place (or move) a sentence at the anchor position of the entity's
     * document order. For a sentence being created, $movingSentenceId is
     * null and the returned order goes on the new row.
     *
     * @return int the order assigned to the sentence
     */
    public function place(int $entityId, SentenceAnchor $anchor, ?int $movingSentenceId = null): int
    {
        $orders = $this->currentOrders($entityId);

        return $this->placeWithin($entityId, $orders, $this->anchorOrder($anchor, $orders), $movingSentenceId);
    }

    /**
     * Order-anchored placement for callers that already reason in raw order
     * values — the alignment editor's placement engine clamps its anchors
     * against row boundaries rather than naming a sentence.
     *
     * @return int the order assigned to the sentence
     */
    public function placeAfterOrder(int $entityId, int $afterOrder, ?int $movingSentenceId = null): int
    {
        return $this->placeWithin($entityId, $this->currentOrders($entityId), $afterOrder, $movingSentenceId);
    }

    /**
     * @param  Collection<int, int>  $orders  sentence id => order
     */
    private function placeWithin(int $entityId, Collection $orders, int $afterOrder, ?int $movingSentenceId): int
    {
        $items = $orders
            ->map(fn (int $order, int $id): array => ['key' => 's-'.$id, 'order' => $order])
            ->values()
            ->all();

        $result = $this->sparseOrder->orderForInsertAfter(
            $items,
            $movingSentenceId !== null ? 's-'.$movingSentenceId : null,
            $afterOrder,
        );

        $result = $this->shiftOrdersNonNegative($result);

        $this->persistChanged($entityId, $orders, $result, $movingSentenceId);

        return $result['order'];
    }

    /**
     * @param  Collection<int, int>  $orders
     */
    private function anchorOrder(SentenceAnchor $anchor, Collection $orders): int
    {
        if ($anchor->sentenceId !== null) {
            $order = $orders->get($anchor->sentenceId);

            if ($order === null) {
                throw (new ModelNotFoundException)->setModel(EntitySentence::class, [$anchor->sentenceId]);
            }

            return $order;
        }

        if ($anchor->end) {
            return $orders->isEmpty()
                ? SparseOrderService::BEGINNING_SENTINEL
                : (int) $orders->max();
        }

        return SparseOrderService::BEGINNING_SENTINEL;
    }

    /**
     * @return Collection<int, int> sentence id => order
     */
    private function currentOrders(int $entityId): Collection
    {
        return EntitySentence::query()
            ->where('entity_id', $entityId)
            ->pluck('order', 'id')
            ->map(fn (mixed $order): int => (int) $order);
    }

    /**
     * Shift the whole result so the minimum order is non-negative,
     * preserving relative sequence — orders surface as display numbers.
     *
     * @param  array{order: int, items: list<array{key: string, order: int}>}  $result
     * @return array{order: int, items: list<array{key: string, order: int}>}
     */
    private function shiftOrdersNonNegative(array $result): array
    {
        $orders = array_column($result['items'], 'order');
        $orders[] = $result['order'];
        $minOrder = min($orders);

        if ($minOrder < 0) {
            $shift = -$minOrder;
            $result['order'] += $shift;

            foreach ($result['items'] as &$item) {
                $item['order'] += $shift;
            }
            unset($item);
        }

        return $result;
    }

    /**
     * Persist sentence orders two-phase: every changed row is parked at a
     * unique negative order before the finals are written. The final orders
     * are collision-free as a set, but one row's final may be another row's
     * current order, so a naive one-by-one write would trip the
     * (entity_id, order) unique index mid-write.
     *
     * @param  Collection<int, int>  $currentOrders
     * @param  array{order: int, items: list<array{key: string, order: int}>}  $result
     */
    private function persistChanged(int $entityId, Collection $currentOrders, array $result, ?int $movingSentenceId): void
    {
        $updates = [];

        foreach ($result['items'] as $item) {
            $id = (int) substr($item['key'], 2);

            if ((int) $currentOrders->get($id, $item['order']) !== $item['order']) {
                $updates[] = ['id' => $id, 'order' => $item['order']];
            }
        }

        if ($movingSentenceId !== null
            && (int) $currentOrders->get($movingSentenceId, $result['order']) !== $result['order']) {
            $updates[] = ['id' => $movingSentenceId, 'order' => $result['order']];
        }

        if ($updates === []) {
            return;
        }

        foreach ($updates as $update) {
            EntitySentence::query()->whereKey($update['id'])->update(['order' => -($update['id'] + 1_000_000_000)]);
        }

        foreach ($updates as $update) {
            EntitySentence::query()->whereKey($update['id'])->update(['order' => $update['order']]);
        }

        Entity::touchSentencesFor($entityId);
    }
}
