@php
    $page = $page ?? 1;
    $lastPage = $lastPage ?? 1;
    $total = $total ?? 0;
    $perPage = $perPage ?? 25;
    $action = $action ?? 'goToMeaningPage';
    $from = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
    $to = min($page * $perPage, $total);
@endphp

@if($lastPage > 1)
    <div class="flex items-center gap-3 overflow-x-auto whitespace-nowrap border-t border-gray-200 px-4 py-3 dark:border-gray-700">
        <p class="shrink-0 text-sm text-gray-600 dark:text-gray-400">
            Showing {{ $from }}–{{ $to }} of {{ $total }}
        </p>
        <div
            class="ml-auto flex shrink-0 items-center gap-2"
            wire:key="alignment-pagination-{{ $action }}-{{ $page }}"
            x-data="{
                pageInput: {{ $page }},
                lastPage: {{ $lastPage }},
                goToPage() {
                    const page = Math.min(Math.max(1, this.pageInput), this.lastPage);
                    this.pageInput = page;
                    $wire.{{ $action }}(page);
                },
            }"
        >
            <x-filament::button
                wire:click="{{ $action }}({{ max(1, $page - 1) }})"
                icon="heroicon-o-chevron-left"
                size="xs"
                color="gray"
                :disabled="$page <= 1"
                aria-label="Previous page"
                title="Previous page"
            />
            <div class="flex items-center gap-1.5 rounded-lg bg-gray-50 px-2 py-1 dark:bg-gray-900">
                <label for="page-input-{{ $action }}" class="sr-only">Page number</label>
                <input
                    id="page-input-{{ $action }}"
                    type="number"
                    min="1"
                    max="{{ $lastPage }}"
                    x-model.number="pageInput"
                    x-on:keydown.enter.prevent="goToPage()"
                    class="w-16 rounded-lg border-gray-300 px-2 py-1 text-center text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                />
                <span class="text-sm text-gray-600 dark:text-gray-400">
                    / {{ $lastPage }}
                </span>
                <x-filament::button
                    x-on:click="goToPage()"
                    icon="heroicon-o-arrow-right"
                    size="xs"
                    color="gray"
                    aria-label="Go to page"
                    title="Go to page"
                />
            </div>
            <x-filament::button
                wire:click="{{ $action }}({{ min($lastPage, $page + 1) }})"
                icon="heroicon-o-chevron-right"
                size="xs"
                color="gray"
                :disabled="$page >= $lastPage"
                aria-label="Next page"
                title="Next page"
            />
        </div>
    </div>
@endif
