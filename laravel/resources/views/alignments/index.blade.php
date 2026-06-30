<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-100">
                    {{ __('Sentence Alignments') }}
                </h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Browse existing English and Russian alignment runs.') }}
                </p>
            </div>
        </div>
    </x-slot>

    <div id="alignments-app"></div>
    {{--    @dd($alignmentRuns)--}}
    @push('scripts')
        <script>
            window.__ALIGNMENT_DATA__ = {
                entityMatches: {{ Js::from($entityMatches->map(fn ($item) => [
                    'id' => $item->id,
                    'en_entity_name' => $item->enEntity?->name,
                    'ru_entity_name' => $item->ruEntity?->name,
                    'entity_similarity' => $item->entity_similarity !== null ? (float) $item->entity_similarity : null,
                    'linked_count' => $item->linked_count,
                    'en_total_sentences' => $item->en_total_sentences,
                    'ru_total_sentences' => $item->ru_total_sentences,
                    'status' => $item->status,
                    'created_at' => $item->created_at?->format('Y-m-d H:i'),
                ])) }},
                paginationHtml: {{ Js::from($entityMatches->links()->toHtml()) }},
                viewUrl: '{{ route('alignments.show', '__ID__') }}',
            };
        </script>
        @vite(['resources/js/pages/alignments.jsx'])
    @endpush
</x-app-layout>
