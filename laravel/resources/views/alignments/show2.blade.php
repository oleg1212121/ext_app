<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-100">
                    {{ $entityMatch->enEntity->name ?? __('English') }}
                    <span class="mx-2 text-gray-400 dark:text-gray-500">/</span>
                    {{ $entityMatch->ruEntity->name ?? __('Russian') }}
                </h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Sentence-level alignment view for this entity pair.') }}
                </p>
            </div>

            <a
                href="{{ route('alignments.index') }}"
                class="inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
            >
                {{ __('Back to alignments') }}
            </a>
        </div>
    </x-slot>

    <div id="alignments-show"></div>
    @push('scripts')
        <script>
            window.__ALIGNMENT_SHOW_DATA__ = {
                rows: {{ Js::from($rows) }},
                {{--alignmentRun: {{ Js::from($alignmentRun) }},--}}
                {{--paginationHtml: {{ Js::from($alignmentRuns->links()->toHtml()) }},--}}
                {{--viewUrl: '{{ route('alignments.show', '__ID__') }}',--}}
            };
        </script>
        @vite(['resources/js/Pages/alignments-show.jsx'])
    @endpush

</x-app-layout>
