<x-filament-panels::page>
    @php
        $run = $this->getRecord();
        $rows = $this->visibleMeaningRows;
        $rowOffset = ($this->meaningPage - 1) * $this->meaningPerPage;
        $colors = ['bg-green-50 dark:bg-green-900/20', 'bg-blue-50 dark:bg-blue-900/20', 'bg-yellow-50 dark:bg-yellow-900/20', 'bg-purple-50 dark:bg-purple-900/20', 'bg-pink-50 dark:bg-pink-900/20', 'bg-orange-50 dark:bg-orange-900/20'];
    @endphp

    <div
        class="alignment-editor w-full max-w-full"
        x-data
        x-on:beforeunload.window="if (@js($this->isDirty)) { $event.preventDefault(); $event.returnValue = ''; }"
    >
        <style>
            .fi-main {
                max-width: 100% !important;
                width: 100% !important;
            }
            .fi-page-content {
                max-width: 100% !important;
                width: 100% !important;
            }
            .fi-main-ctn {
                max-width: 100% !important;
                width: 100% !important;
            }
            .alignment-editor__table-wrap,
            .alignment-editor__table {
                max-width: 100%;
                width: 100%;
            }
            .alignment-editor__textarea {
                box-sizing: border-box;
                display: block;
                max-width: 100%;
                resize: none;
                width: 100%;
            }
        </style>

        @if($this->isDirty)
            <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-200">
                Unsaved changes — use the row save button to persist edits.
            </div>
        @endif

        @if($this->meaningRowsTotal > 0)
            <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                {{ $this->meaningRowsTotal }} meaning rows total
            </p>
        @endif

        @if($this->meaningRowsTotal === 0)
            <div class="rounded-lg bg-white p-8 text-center shadow-sm dark:bg-gray-800">
                <p class="text-gray-500 dark:text-gray-400">
                    No meaning rows yet. Add sentences and connect unmatched ones to build alignments.
                </p>
            </div>
        @else
            <div class="alignment-editor__table-wrap overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800">
                <div class="w-full overflow-x-auto">
                    <table class="alignment-editor__table table-fixed border-collapse">
                        <thead>
                            <tr class="border-b border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900">
                                <th class="px-3 py-3 text-center text-xs font-semibold text-gray-600 dark:text-gray-300" style="width: 4%;">#</th>
                                <th class="px-3 py-3 text-center text-xs font-semibold text-gray-600 dark:text-gray-300" style="width: 8%;">Actions</th>
                                <th class="px-2 py-3 text-center text-xs font-semibold text-gray-600 dark:text-gray-300" style="width: 5%;">EN</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300" style="width: 37%;">
                                    {{ $run->enEntity->name ?? 'English' }}
                                </th>
                                <th class="px-2 py-3 text-center text-xs font-semibold text-gray-600 dark:text-gray-300" style="width: 5%;">RU</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300" style="width: 41%;">
                                    {{ $run->ruEntity->name ?? 'Russian' }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $index => $row)
                                @php
                                    $hasEn = count($row['en_sentences']) > 0;
                                    $hasRu = count($row['ru_sentences']) > 0;
                                    $bgClass = '';
                                    if ($hasEn && $hasRu) {
                                        $bgClass = $colors[($rowOffset + $index) % count($colors)];
                                    } elseif ($hasEn) {
                                        $bgClass = 'bg-red-50 dark:bg-red-900/20';
                                    } elseif ($hasRu) {
                                        $bgClass = 'bg-blue-50 dark:bg-blue-900/20';
                                    }
                                @endphp
                                <tr wire:key="row-{{ $row['key'] }}" class="border-b border-gray-100 dark:border-gray-700 {{ $bgClass }}">
                                    <td class="px-3 py-2 text-center text-xs text-gray-400">
                                        <span>{{ $rowOffset + $index + 1 }}</span>
                                    </td>
                                    <td class="px-3 py-2 text-center text-xs text-gray-400">
                                        <div class="flex items-center justify-center gap-1 whitespace-nowrap">
                                            <x-filament::button
                                                wire:click="saveMeaningRow('{{ $row['key'] }}')"
                                                icon="heroicon-o-check"
                                                size="xs"
                                                color="success"
                                                aria-label="Save row"
                                                title="Save row"
                                            />
                                            <x-filament::button
                                                wire:click="insertMeaningRowAfter('{{ $row['key'] }}')"
                                                icon="heroicon-o-plus"
                                                size="xs"
                                                color="gray"
                                                aria-label="Insert row below"
                                                title="Insert row below"
                                            />
                                            <x-filament::button
                                                wire:click="unlinkMeaningRow('{{ $row['key'] }}')"
                                                wire:confirm="Remove this sentence link? The sentences will stay in their entities."
                                                icon="heroicon-o-link-slash"
                                                size="xs"
                                                color="warning"
                                                aria-label="Remove link"
                                                title="Remove link"
                                            />
                                        </div>
                                    </td>
                                    <td class="px-2 py-2 text-center text-xs font-medium text-gray-500">
                                        @foreach($row['en_sentences'] as $sentence)
                                            <div>{{ $sentence['order'] }}</div>
                                        @endforeach
                                    </td>
                                    <td class="px-4 py-2 text-sm text-gray-800 dark:text-gray-200 align-top">
                                        @forelse($row['en_sentences'] as $sentence)
                                            @include('filament.pages.partials.alignment-sentence-editor', [
                                                'lang' => 'en',
                                                'sentence' => $sentence,
                                                'showActions' => false,
                                            ])
                                        @empty
                                            <span class="text-gray-300 dark:text-gray-600">—</span>
                                        @endforelse
                                    </td>
                                    <td class="px-2 py-2 text-center text-xs font-medium text-gray-500">
                                        @foreach($row['ru_sentences'] as $sentence)
                                            <div>{{ $sentence['order'] }}</div>
                                        @endforeach
                                    </td>
                                    <td class="px-4 py-2 text-sm text-gray-800 dark:text-gray-200 align-top">
                                        @forelse($row['ru_sentences'] as $sentence)
                                            @include('filament.pages.partials.alignment-sentence-editor', [
                                                'lang' => 'ru',
                                                'sentence' => $sentence,
                                                'showActions' => false,
                                            ])
                                        @empty
                                            <span class="text-gray-300 dark:text-gray-600">—</span>
                                        @endforelse
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @include('filament.pages.partials.alignment-pagination', [
                    'page' => $this->meaningPage,
                    'lastPage' => $this->meaningLastPage,
                    'total' => $this->meaningRowsTotal,
                    'perPage' => $this->meaningPerPage,
                    'action' => 'goToMeaningPage',
                ])
            </div>
        @endif

        <div class="mt-6 grid gap-4 md:grid-cols-2">
            @foreach([
                'en' => ['label' => 'English', 'rows' => $this->visibleUnmatchedEn, 'total' => $this->unmatchedEnTotal, 'page' => $this->unmatchedEnPage, 'lastPage' => $this->unmatchedEnLastPage, 'action' => 'goToUnmatchedEnPage'],
                'ru' => ['label' => 'Russian', 'rows' => $this->visibleUnmatchedRu, 'total' => $this->unmatchedRuTotal, 'page' => $this->unmatchedRuPage, 'lastPage' => $this->unmatchedRuLastPage, 'action' => 'goToUnmatchedRuPage'],
            ] as $lang => $panel)
                <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <div class="p-4">
                        <h3 class="mb-3 text-sm font-semibold text-gray-700 dark:text-gray-200">
                            Unmatched {{ $panel['label'] }} ({{ $panel['total'] }})
                        </h3>
                        @if($panel['total'] === 0)
                            <p class="text-sm text-gray-500 dark:text-gray-400">All {{ $panel['label'] }} sentences are linked to meaning rows.</p>
                        @else
                            <ul class="flex flex-col gap-3">
                                @foreach($panel['rows'] as $sentence)
                                    <li wire:key="unmatched-{{ $lang }}-{{ $sentence['key'] }}" class="rounded border border-dashed border-gray-300 p-3 dark:border-gray-600">
                                        <div class="mb-1 text-xs text-gray-400">#{{ $sentence['order'] }}</div>
                                        @include('filament.pages.partials.alignment-sentence-editor', [
                                            'lang' => $lang,
                                            'sentence' => $sentence,
                                            'showUnlink' => false,
                                        ])
                                        <div class="mt-2">
                                            <x-filament::button
                                                wire:click="openConnectModal('{{ $lang }}', '{{ $sentence['key'] }}')"
                                                icon="heroicon-o-link"
                                                size="xs"
                                                color="primary"
                                                aria-label="Connect"
                                                title="Connect"
                                            />
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                    @include('filament.pages.partials.alignment-pagination', [
                        'page' => $panel['page'],
                        'lastPage' => $panel['lastPage'],
                        'total' => $panel['total'],
                        'perPage' => $this->unmatchedPerPage,
                        'action' => $panel['action'],
                    ])
                </div>
            @endforeach
        </div>
    </div>

    @if($this->showAddModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl dark:bg-gray-800">
                <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                    Add {{ strtoupper($this->addLang) }} sentence
                </h3>
                <div class="flex flex-col gap-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Insert position</label>
                        <select
                            wire:model="addAfterOrder"
                            class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                        >
                            @foreach($this->getInsertOrderOptions($this->addLang) as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Content</label>
                        <textarea
                            wire:model="addContent"
                            rows="3"
                            class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                        ></textarea>
                    </div>
                    <div class="flex justify-end gap-2">
                        <x-filament::button
                            wire:click="closeAddModal"
                            icon="heroicon-o-x-mark"
                            color="gray"
                            aria-label="Cancel"
                            title="Cancel"
                        />
                        <x-filament::button
                            wire:click="addSentence"
                            icon="heroicon-o-plus"
                            color="primary"
                            aria-label="Add"
                            title="Add"
                        />
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if($this->showConnectModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl dark:bg-gray-800">
                <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                    Connect {{ strtoupper($this->connectLang) }} sentence
                </h3>
                <div class="flex flex-col gap-4">
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="radio" wire:model.live="connectMode" value="0" class="rounded border-gray-300">
                        Attach to existing meaning row
                    </label>
                    @if($this->connectMode === 0)
                        <select
                            wire:model="connectTargetMeaningKey"
                            class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                        >
                            @foreach($this->getMeaningRowOptions() as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    @endif

                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="radio" wire:model.live="connectMode" value="1" class="rounded border-gray-300">
                        Create new meaning row
                    </label>
                    @if($this->connectMode === 1)
                        <select
                            wire:model="connectInsertAfterMeaningOrder"
                            class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                        >
                            @foreach($this->getMeaningInsertOptions() as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    @endif

                    <div class="flex justify-end gap-2">
                        <x-filament::button
                            wire:click="closeConnectModal"
                            icon="heroicon-o-x-mark"
                            color="gray"
                            aria-label="Cancel"
                            title="Cancel"
                        />
                        <x-filament::button
                            wire:click="connectSentence"
                            icon="heroicon-o-link"
                            color="primary"
                            aria-label="Connect"
                            title="Connect"
                        />
                    </div>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
