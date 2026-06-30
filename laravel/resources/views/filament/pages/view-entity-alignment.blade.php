<x-filament-panels::page>
    @php
        $run = $this->record;
        $display = $this->getDisplayData();
        $rows = $display['rows'];
        $rowOffset = ($display['page'] - 1) * $display['per_page'];
        $colors = ['bg-green-50', 'bg-blue-50', 'bg-yellow-50', 'bg-purple-50', 'bg-pink-50', 'bg-orange-50'];
    @endphp

    {{-- Status Header --}}
    <div class="mb-4 rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
        <div class="flex flex-wrap items-center gap-6">
            <div>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Status</span>
                @php
                    $statusColor = match($run->status) {
                        'pending' => 'gray',
                        'verifying' => 'info',
                        'aligning' => 'warning',
                        'completed' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    };
                @endphp
                <x-filament::badge :color="$statusColor">
                    {{ ucfirst($run->status) }}
                </x-filament::badge>
            </div>
            <div>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Entity Similarity</span>
                <span class="ml-2 font-semibold {{ $run->entity_similarity >= 0.85 ? 'text-green-600' : ($run->entity_similarity >= 0.70 ? 'text-yellow-600' : 'text-red-600') }}">
                    {{ $run->entity_similarity !== null ? number_format($run->entity_similarity, 4) : '-' }}
                </span>
            </div>
            <div>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">EN Sentences</span>
                <span class="ml-2 font-semibold">{{ $run->en_total_sentences }}</span>
            </div>
            <div>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">RU Sentences</span>
                <span class="ml-2 font-semibold">{{ $run->ru_total_sentences }}</span>
            </div>
            <div>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Links Created</span>
                <span class="ml-2 font-semibold">{{ $run->linked_count }}</span>
            </div>
            @if($run->error_message)
                <div class="w-full">
                    <span class="text-sm font-medium text-red-500">Error: {{ $run->error_message }}</span>
                </div>
            @endif
        </div>
    </div>

    {{-- Bilingual View Table --}}
    @if($display['total'] === 0)
        <div class="rounded-lg bg-white p-8 text-center shadow-sm dark:bg-gray-800">
            <p class="text-gray-500 dark:text-gray-400">
                @if(in_array($run->status, ['pending', 'verifying', 'aligning']))
                    Alignment is in progress. Sentences will appear here as they are processed.
                @elseif($run->status === 'failed')
                    Alignment failed. No results to display.
                @else
                    No alignment data available.
                @endif
            </p>
        </div>
    @else
        <div class="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800">
            <div class="overflow-x-auto">
                <table class="w-full table-fixed border-collapse">
                    <thead>
                        <tr class="border-b border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900">
                            <th class="w-12 px-3 py-3 text-center text-xs font-semibold text-gray-600 dark:text-gray-300">#</th>
                            <th class="w-12 px-2 py-3 text-center text-xs font-semibold text-gray-600 dark:text-gray-300">EN</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300">
                                {{ $run->enEntity->name ?? 'English' }}
                            </th>
                            <th class="w-12 px-2 py-3 text-center text-xs font-semibold text-gray-600 dark:text-gray-300">RU</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300">
                                {{ $run->ruEntity->name ?? 'Russian' }}
                            </th>
                            <th class="w-20 px-3 py-3 text-center text-xs font-semibold text-gray-600 dark:text-gray-300">Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $index => $row)
                            @php
                                $bgClass = '';
                                if ($row['type'] === 'match') {
                                    $bgClass = $colors[$row['color_index'] % count($colors)];
                                } elseif ($row['type'] === 'skip_en') {
                                    $bgClass = 'bg-red-50 dark:bg-red-900/20';
                                } elseif ($row['type'] === 'skip_ru') {
                                    $bgClass = 'bg-blue-50 dark:bg-blue-900/20';
                                }
                            @endphp
                            <tr class="border-b border-gray-100 dark:border-gray-700 {{ $bgClass }} hover:brightness-95">
                                {{-- Row number --}}
                                <td class="px-3 py-2 text-center text-xs text-gray-400">
                                    {{ $rowOffset + $index + 1 }}
                                </td>

                                {{-- EN order --}}
                                <td class="px-2 py-2 text-center text-xs font-medium text-gray-500">
                                    @foreach($row['en'] as $en)
                                        <div>{{ $en['order'] }}</div>
                                    @endforeach
                                </td>

                                {{-- EN content --}}
                                <td class="px-4 py-2 text-sm text-gray-800 dark:text-gray-200">
                                    @forelse($row['en'] as $en)
                                        <div class="py-0.5">{{ \Illuminate\Support\Str::limit($en['content'], 300) }}</div>
                                    @empty
                                        <span class="text-gray-300 dark:text-gray-600">—</span>
                                    @endforelse
                                </td>

                                {{-- RU order --}}
                                <td class="px-2 py-2 text-center text-xs font-medium text-gray-500">
                                    @foreach($row['ru'] as $ru)
                                        <div>{{ $ru['order'] }}</div>
                                    @endforeach
                                </td>

                                {{-- RU content --}}
                                <td class="px-4 py-2 text-sm text-gray-800 dark:text-gray-200">
                                    @forelse($row['ru'] as $ru)
                                        <div class="py-0.5">{{ \Illuminate\Support\Str::limit($ru['content'], 300) }}</div>
                                    @empty
                                        <span class="text-gray-300 dark:text-gray-600">—</span>
                                    @endforelse
                                </td>

                                {{-- Similarity score --}}
                                <td class="px-3 py-2 text-center text-xs">
                                    @if($row['similarity'] !== null)
                                        @php
                                            $simColor = match(true) {
                                                $row['similarity'] >= 0.85 => 'text-green-600',
                                                $row['similarity'] >= 0.70 => 'text-yellow-600',
                                                default => 'text-red-600',
                                            };
                                        @endphp
                                        <span class="{{ $simColor }} font-medium">
                                            {{ number_format($row['similarity'], 2) }}
                                        </span>
                                    @else
                                        <span class="text-gray-300">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @include('filament.pages.partials.alignment-pagination', [
                'page' => $display['page'],
                'lastPage' => $display['last_page'],
                'total' => $display['total'],
                'perPage' => $display['per_page'],
                'action' => 'goToDisplayPage',
            ])
        </div>
    @endif
</x-filament-panels::page>
