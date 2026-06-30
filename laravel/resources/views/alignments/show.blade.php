<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-100">
                    {{ $alignmentRun->enEntity->name ?? __('English') }}
                    <span class="mx-2 text-gray-400 dark:text-gray-500">/</span>
                    {{ $alignmentRun->ruEntity->name ?? __('Russian') }}
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

    @php
        $colors = [
            'bg-green-50 dark:bg-green-900/15',
            'bg-blue-50 dark:bg-blue-900/15',
            'bg-yellow-50 dark:bg-yellow-900/15',
            'bg-purple-50 dark:bg-purple-900/15',
            'bg-pink-50 dark:bg-pink-900/15',
            'bg-orange-50 dark:bg-orange-900/15',
        ];

        $statusClasses = match($alignmentRun->status) {
            'pending' => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
            'verifying' => 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300',
            'aligning' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
            'completed' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
            'failed' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
            default => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
        };
    @endphp

    <div class="py-10" x-data="{ showFullText: false }">
        <div class="mx-auto flex max-w-7xl flex-col gap-6 px-4 sm:px-6 lg:px-8">
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="flex flex-wrap items-center gap-6">
                    <div>
                        <div class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Status') }}</div>
                        <span class="mt-2 inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClasses }}">
                            {{ ucfirst($alignmentRun->status) }}
                        </span>
                    </div>
                    <div>
                        <div class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Entity Similarity') }}</div>
                        <div class="mt-2 text-lg font-semibold {{ $alignmentRun->entity_similarity >= 0.85 ? 'text-emerald-600 dark:text-emerald-400' : ($alignmentRun->entity_similarity >= 0.70 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400') }}">
                            {{ $alignmentRun->entity_similarity !== null ? number_format((float) $alignmentRun->entity_similarity, 4) : '—' }}
                        </div>
                    </div>
                    <div>
                        <div class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('EN Sentences') }}</div>
                        <div class="mt-2 text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $alignmentRun->en_total_sentences }}</div>
                    </div>
                    <div>
                        <div class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('RU Sentences') }}</div>
                        <div class="mt-2 text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $alignmentRun->ru_total_sentences }}</div>
                    </div>
                    <div>
                        <div class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Links Created') }}</div>
                        <div class="mt-2 text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $alignmentRun->linked_count }}</div>
                    </div>
                    <div class="sm:ml-auto">
                        <button
                            type="button"
                            @click="showFullText = !showFullText"
                            class="inline-flex items-center rounded-lg border border-orange-200 bg-orange-50 px-3 py-2 text-sm font-medium text-orange-700 transition hover:bg-orange-100 dark:border-orange-900/60 dark:bg-orange-900/30 dark:text-orange-300 dark:hover:bg-orange-900/50"
                        >
                            <span x-show="!showFullText">{{ __('Expand text') }}</span>
                            <span x-show="showFullText" x-cloak>{{ __('Collapse text') }}</span>
                        </button>
                    </div>
                </div>

                @if($alignmentRun->error_message)
                    <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900/50 dark:bg-red-900/20 dark:text-red-300">
                        {{ $alignmentRun->error_message }}
                    </div>
                @endif
            </div>

            @if(empty($rows))
                <div class="rounded-xl border border-gray-200 bg-white px-6 py-12 text-center shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        @if(in_array($alignmentRun->status, ['pending', 'verifying', 'aligning']))
                            {{ __('Alignment is in progress. Sentences will appear here as they are processed.') }}
                        @elseif($alignmentRun->status === 'failed')
                            {{ __('Alignment failed. No results to display.') }}
                        @else
                            {{ __('No alignment data available.') }}
                        @endif
                    </p>
                </div>
            @else
                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <div class="overflow-x-auto">
                        <table class="min-w-full table-fixed border-collapse">
                            <thead>
                                <tr class="border-b border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900/70">
                                    <th class="w-12 px-3 py-3 text-center text-xs font-semibold text-gray-600 dark:text-gray-300">#</th>
                                    <th class="w-12 px-2 py-3 text-center text-xs font-semibold text-gray-600 dark:text-gray-300">EN</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300">
                                        {{ $alignmentRun->enEntity->name ?? __('English') }}
                                    </th>
                                    <th class="w-12 px-2 py-3 text-center text-xs font-semibold text-gray-600 dark:text-gray-300">RU</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300">
                                        {{ $alignmentRun->ruEntity->name ?? __('Russian') }}
                                    </th>
                                    <th class="w-20 px-3 py-3 text-center text-xs font-semibold text-gray-600 dark:text-gray-300">
                                        {{ __('Score') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($rows as $index => $row)
                                    @php
                                        $bgClass = match($row['type']) {
                                            'match' => $colors[$row['color_index'] % count($colors)],
                                            'skip_en' => 'bg-red-50 dark:bg-red-900/20',
                                            'skip_ru' => 'bg-blue-50 dark:bg-blue-900/20',
                                            default => '',
                                        };
                                    @endphp
                                    <tr class="border-b border-gray-100 align-top hover:brightness-95 dark:border-gray-700 {{ $bgClass }}">
                                        <td class="px-3 py-3 text-center text-xs text-gray-400 dark:text-gray-500">
                                            {{ $index + 1 }}
                                        </td>
                                        <td class="px-2 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400">
                                            @foreach($row['en'] as $en)
                                                <div>{{ $en['order'] }}</div>
                                            @endforeach
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-800 dark:text-gray-200">
                                            @forelse($row['en'] as $en)
                                                <div class="py-1">
                                                    <span x-show="!showFullText">{{ \Illuminate\Support\Str::limit($en['content'], 300) }}</span>
                                                    <span x-show="showFullText" x-cloak>{{ $en['content'] }}</span>
                                                </div>
                                            @empty
                                                <span class="text-gray-300 dark:text-gray-600">—</span>
                                            @endforelse
                                        </td>
                                        <td class="px-2 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400">
                                            @foreach($row['ru'] as $ru)
                                                <div>{{ $ru['order'] }}</div>
                                            @endforeach
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-800 dark:text-gray-200">
                                            @forelse($row['ru'] as $ru)
                                                <div class="py-1">
                                                    <span x-show="!showFullText">{{ \Illuminate\Support\Str::limit($ru['content'], 300) }}</span>
                                                    <span x-show="showFullText" x-cloak>{{ $ru['content'] }}</span>
                                                </div>
                                            @empty
                                                <span class="text-gray-300 dark:text-gray-600">—</span>
                                            @endforelse
                                        </td>
                                        <td class="px-3 py-3 text-center text-xs">
                                            @if($row['similarity'] !== null)
                                                <span class="font-medium {{ $row['similarity'] >= 0.85 ? 'text-emerald-600 dark:text-emerald-400' : ($row['similarity'] >= 0.70 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400') }}">
                                                    {{ number_format((float) $row['similarity'], 2) }}
                                                </span>
                                            @else
                                                <span class="text-gray-300 dark:text-gray-600">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
