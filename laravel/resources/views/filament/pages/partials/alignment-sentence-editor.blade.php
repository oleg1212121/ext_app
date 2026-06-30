@php
    $showUnlink = $showUnlink ?? true;
    $showActions = $showActions ?? true;
@endphp

<div class="mb-2 w-full max-w-full min-w-0 rounded border border-gray-200 bg-white/60 p-2 dark:border-gray-600 dark:bg-gray-900/40">
    <textarea
        rows="2"
        class="alignment-editor__textarea mb-2 rounded border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
        wire:change="updateSentenceContent('{{ $lang }}', '{{ $sentence['key'] }}', $event.target.value)"
    >{{ $sentence['content'] }}</textarea>
    @if($showActions)
        <div class="flex flex-wrap gap-1">
            <x-filament::button
                wire:click="moveSentence('{{ $lang }}', '{{ $sentence['key'] }}', 'up')"
                icon="heroicon-o-arrow-up"
                size="xs"
                color="gray"
                aria-label="Move up"
                title="Move up"
            />
            <x-filament::button
                wire:click="moveSentence('{{ $lang }}', '{{ $sentence['key'] }}', 'down')"
                icon="heroicon-o-arrow-down"
                size="xs"
                color="gray"
                aria-label="Move down"
                title="Move down"
            />
            @if($showUnlink)
                <x-filament::button
                    wire:click="unlinkSentence('{{ $lang }}', '{{ $sentence['key'] }}')"
                    icon="heroicon-o-link-slash"
                    size="xs"
                    color="warning"
                    aria-label="Unlink sentence"
                    title="Unlink sentence"
                />
            @endif
            <x-filament::button
                wire:click="deleteSentence('{{ $lang }}', '{{ $sentence['key'] }}')"
                wire:confirm="Delete this sentence?"
                icon="heroicon-o-trash"
                size="xs"
                color="danger"
                aria-label="Delete sentence"
                title="Delete sentence"
            />
        </div>
    @endif
</div>
