<?php

namespace App\Classes;

use Illuminate\Database\Eloquent\Model;

class SparseOrderService
{
    public const STRIDE = 1024;

    public const WINDOW_SIZE = 100;

    public const BEGINNING_SENTINEL = PHP_INT_MIN;

    public function initial(int $index): int
    {
        return $index * self::STRIDE;
    }

    public function between(?int $previousOrder, ?int $nextOrder): ?int
    {
        if ($previousOrder === null && $nextOrder === null) {
            return 0;
        }

        if ($previousOrder === null) {
            return $nextOrder - self::STRIDE;
        }

        if ($nextOrder === null) {
            return $previousOrder + self::STRIDE;
        }

        if ($nextOrder - $previousOrder <= 1) {
            return null;
        }

        return $previousOrder + intdiv($nextOrder - $previousOrder, 2);
    }

    /**
     * @param  list<array{key: string, order: int}>  $items
     * @return array{order: int, items: list<array{key: string, order: int}>}
     */
    public function orderForInsertAfter(array $items, ?string $movingKey, int $afterOrder): array
    {
        $items = array_values(array_filter(
            $items,
            fn (array $item): bool => $movingKey === null || $item['key'] !== $movingKey,
        ));

        $this->sortItems($items);

        $insertIndex = $this->insertIndexAfter($items, $afterOrder);
        $order = $this->between(
            $insertIndex > 0 ? $items[$insertIndex - 1]['order'] : null,
            $insertIndex < count($items) ? $items[$insertIndex]['order'] : null,
        );

        if ($order !== null) {
            return ['order' => $order, 'items' => $items];
        }

        $items = $this->rebalanceWindow($items, $insertIndex);
        $this->sortItems($items);
        $insertIndex = $this->insertIndexAfter($items, $afterOrder);
        $order = $this->between(
            $insertIndex > 0 ? $items[$insertIndex - 1]['order'] : null,
            $insertIndex < count($items) ? $items[$insertIndex]['order'] : null,
        );

        if ($order !== null) {
            return ['order' => $order, 'items' => $items];
        }

        $items = $this->rebalanceItems($items);
        $insertIndex = $this->insertIndexAfter($items, $afterOrder);
        $order = $this->between(
            $insertIndex > 0 ? $items[$insertIndex - 1]['order'] : null,
            $insertIndex < count($items) ? $items[$insertIndex]['order'] : null,
        );

        return ['order' => $order ?? $this->initial($insertIndex), 'items' => $items];
    }

    /**
     * @param  list<array{key: string, order: int}>  $items
     * @return list<array{key: string, order: int}>
     */
    public function rebalanceItems(array $items): array
    {
        $this->sortItems($items);

        foreach ($items as $index => &$item) {
            $item['order'] = $this->initial($index);
        }
        unset($item);

        return $items;
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    public function rebalanceAll(string $modelClass, string $scopeColumn, int $scopeId, bool $dryRun = false): int
    {
        $rows = $modelClass::query()
            ->where($scopeColumn, $scopeId)
            ->orderBy('order')
            ->orderBy('id')
            ->get(['id', 'order']);

        $updates = [];

        foreach ($rows as $index => $row) {
            $order = $this->initial($index);

            if ((int) $row->order === $order) {
                continue;
            }

            $updates[] = [
                'id' => (int) $row->id,
                'order' => $order,
            ];
        }

        if ($dryRun || $updates === []) {
            return count($updates);
        }

        foreach (array_chunk($updates, 1000) as $chunk) {
            foreach ($chunk as $update) {
                $modelClass::query()
                    ->whereKey($update['id'])
                    ->update(['order' => -($update['id'] + 1_000_000_000)]);
            }
        }

        foreach (array_chunk($updates, 1000) as $chunk) {
            foreach ($chunk as $update) {
                $modelClass::query()
                    ->whereKey($update['id'])
                    ->update(['order' => $update['order']]);
            }
        }

        return count($updates);
    }

    /**
     * @param  list<array{key: string, order: int}>  $items
     * @return list<array{key: string, order: int}>
     */
    private function rebalanceWindow(array $items, int $insertIndex): array
    {
        $count = count($items);

        if ($count === 0) {
            return $items;
        }

        $start = max(0, $insertIndex - intdiv(self::WINDOW_SIZE, 2));
        $end = min($count - 1, $start + self::WINDOW_SIZE - 1);
        $start = max(0, $end - self::WINDOW_SIZE + 1);

        $previousAnchor = $start > 0 ? $items[$start - 1]['order'] : null;
        $nextAnchor = $end < $count - 1 ? $items[$end + 1]['order'] : null;
        $windowCount = $end - $start + 1;

        if ($previousAnchor !== null && $nextAnchor !== null) {
            $step = intdiv($nextAnchor - $previousAnchor, $windowCount + 1);

            if ($step <= 1) {
                return $items;
            }

            for ($index = $start; $index <= $end; $index++) {
                $items[$index]['order'] = $previousAnchor + ($step * ($index - $start + 1));
            }

            return $items;
        }

        if ($previousAnchor !== null) {
            for ($index = $start; $index <= $end; $index++) {
                $items[$index]['order'] = $previousAnchor + (self::STRIDE * ($index - $start + 1));
            }

            return $items;
        }

        if ($nextAnchor !== null) {
            for ($index = $end; $index >= $start; $index--) {
                $items[$index]['order'] = $nextAnchor - (self::STRIDE * ($end - $index + 1));
            }

            return $items;
        }

        return $this->rebalanceItems($items);
    }

    /**
     * @param  list<array{key: string, order: int}>  $items
     */
    private function insertIndexAfter(array $items, int $afterOrder): int
    {
        if ($afterOrder === self::BEGINNING_SENTINEL) {
            return 0;
        }

        $insertIndex = 0;

        foreach ($items as $index => $item) {
            if ($item['order'] <= $afterOrder) {
                $insertIndex = $index + 1;
            }
        }

        return $insertIndex;
    }

    /**
     * @param  list<array{key: string, order: int}>  $items
     */
    private function sortItems(array &$items): void
    {
        usort($items, fn (array $a, array $b): int => $a['order'] <=> $b['order'] ?: $a['key'] <=> $b['key']);
    }
}
