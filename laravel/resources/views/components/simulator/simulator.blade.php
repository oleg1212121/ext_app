<div class="body w-full flex-1 flex flex-col overflow-hidden bg-orange-100 dark:bg-gray-900"
     @mouseup.ctrl.alt="showSelectionModal()"
     @dblclick="memorizeHighlight()"
     @keydown.window="memorizeHighlight()"
>
    <!-- Selection Modal -->
    <div id="selection-modal"
         class="absolute bg-white dark:bg-gray-800 shadow-lg rounded-md z-50 p-2 border border-gray-400 dark:border-gray-600"
         x-show="pep">
        <span @click="saveSelection()"
              class="px-2 py-1 cursor-pointer text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-900 rounded transition">+</span>
        <span
            class="cursor-pointer px-2 py-1 text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 rounded transition"
            @click="pep = !pep">×</span>
    </div>

    <!-- Top Toolbar - Fixed -->
    <div class="flex-none bg-white dark:bg-gray-800 border-b-2 border-gray-400 dark:border-gray-600 shadow-md">
        <div class="flex items-center gap-3 px-4 py-3">
            <!-- AI Model Selection -->
            <div class="flex items-center gap-2">
                <select
                    class="bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 text-sm rounded-md px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-gray-600 dark:focus:ring-gray-500 focus:border-transparent transition"
                    x-model="selectedChat">
                    <template x-for="(models, providerName) in aiModels" :key="providerName">
                        <optgroup :label="providerName">
                            <template x-for="(displayName, modelKey) in models" :key="modelKey">
                                <option :value="modelKey" x-text="displayName" :selected="modelKey === selectedChat"></option>
                            </template>
                        </optgroup>
                    </template>
                </select>
            </div>

            <!-- Text Selection -->
            <div class="flex items-center gap-2">
                <select
                    class="bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 text-sm rounded-md px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-gray-600 dark:focus:ring-gray-500 focus:border-transparent transition"
                    x-model="filename">
                    <template x-for="(item, index) in textsList" :key="index">
                        <option :value="item" x-text="item" :selected="item == filename"></option>
                    </template>
                </select>
                <button type="button"
                        class="px-3 py-1.5 bg-gray-700 dark:bg-gray-600 hover:bg-gray-800 dark:hover:bg-gray-500 hover:cursor-pointer text-white text-sm rounded-md transition shadow-sm"
                        @click.prevent="searchFile()">
                    Load
                </button>
            </div>

            <div class="h-6 w-px bg-gray-400 dark:bg-gray-600"></div>

            <!-- Font Size Controls -->
            <div class="flex items-center gap-1">
                <button
                    class="w-8 h-8 flex items-center justify-center bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 hover:cursor-pointer text-gray-700 dark:text-gray-200 rounded transition"
                    @click.prevent="changeFontSize('+')">
                    <span class="text-lg font-semibold">+</span>
                </button>
                <button
                    class="w-8 h-8 flex items-center justify-center bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 hover:cursor-pointer text-gray-700 dark:text-gray-200 rounded transition"
                    @click.prevent="changeFontSize('-')">
                    <span class="text-lg font-semibold">−</span>
                </button>
            </div>

            <div class="h-6 w-px bg-gray-400 dark:bg-gray-600"></div>

            <!-- View Toggles -->
            <div class="flex items-center gap-1">
                <button
                    class="px-3 py-1.5 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 hover:cursor-pointer text-gray-700 dark:text-gray-200 text-sm rounded transition"
                    @click.prevent="showWorkplace = !showWorkplace"
                    :class="{ 'bg-gray-200 dark:bg-gray-600 text-gray-800 dark:text-white ring-1 ring-gray-400 dark:ring-gray-500': showWorkplace }">
                    Workplace
                </button>
                <button
                    class="px-3 py-1.5 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 hover:cursor-pointer text-gray-700 dark:text-gray-200 text-sm rounded transition"
                    @click.prevent="showQuestion = !showQuestion"
                    :class="{ 'bg-gray-200 dark:bg-gray-600 text-gray-800 dark:text-white ring-1 ring-gray-400 dark:ring-gray-500': showQuestion }">
                    Question
                </button>
                <button @click.prevent="leftColumn = !leftColumn"
                        class="px-3 py-1.5 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 hover:cursor-pointer text-gray-700 dark:text-gray-200 text-sm rounded transition"
                        :class="{ 'bg-gray-200 dark:bg-gray-600 text-gray-800 dark:text-white ring-1 ring-gray-400 dark:ring-gray-500': leftColumn }">
                    Text
                </button>
                <button @click.prevent="middleColumn = !middleColumn"
                        class="px-3 py-1.5 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 hover:cursor-pointer text-gray-700 dark:text-gray-200 text-sm rounded transition"
                        :class="{ 'bg-gray-200 dark:bg-gray-600 text-gray-800 dark:text-white ring-1 ring-gray-400 dark:ring-gray-500': middleColumn }">
                    AI
                </button>
            </div>
        </div>
    </div>
    <!-- Information Bar - Fixed -->
    <div class="flex-none z-40">
        <div x-show="spinner" x-transition
             class="bg-green-200 dark:bg-gray-700 border-b-2 border-green-400 dark:border-gray-600 px-4 py-2.5 text-sm text-gray-800 dark:text-gray-200 flex items-center gap-2 font-medium">
            <svg class="animate-spin h-4 w-4 text-gray-800 dark:text-gray-200" xmlns="http://www.w3.org/2000/svg"
                 fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor"
                      d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span>Processing...</span>
        </div>
        <div x-show="isError" x-transition
             class="bg-red-100 dark:bg-red-900 border-b-2 border-red-300 dark:border-red-700 px-4 py-2.5 text-sm text-red-800 dark:text-red-200 flex items-center gap-2 font-medium">
            <svg class="h-4 w-4 text-red-700 dark:text-red-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span>An error occurred. Please try again.</span>
        </div>
    </div>

    <!-- Main Content Area - Scrollable Columns -->
    <div class="flex-1 flex gap-0 overflow-hidden bg-orange-100 dark:bg-gray-800"
        {{-- @mouseup.ctrl.slash.debounce.100="contextModalShow()" --}}
    >
        <!-- Left Column: Bilingual Text -->
        <div x-show="leftColumn" x-transition
             class="flex-1 flex flex-col bg-orange-100 dark:bg-gray-800 border-r-2 border-gray-400 dark:border-gray-600 overflow-hidden shadow-sm">
            <div class="flex-1 overflow-y-auto bg-white dark:bg-gray-700 pb-5">
                <table class="table w-full">
                    <thead
                        class="sticky top-0 bg-orange-100 dark:bg-gray-800 border-b-2 border-gray-400 dark:border-gray-600 z-10 shadow-sm">
                    <tr class="text-sm font-semibold text-gray-800 dark:text-gray-200">
                        <th class="px-4 py-3 text-left">
                            <div class="flex items-center gap-2">
                                <span>English</span>
                                <input type="checkbox"
                                       class="all_en w-4 h-4 text-gray-700 dark:text-gray-300 rounded border-gray-300 dark:border-gray-600 focus:ring-gray-600 dark:bg-gray-700"
                                       id="all_en"/>
                            </div>
                        </th>
                        <th class="px-2 py-3 text-center w-12">EN</th>
                        <th class="px-2 py-3 text-center w-12">#</th>
                        <th class="px-2 py-3 text-center w-12">RU</th>
                        <th class="px-4 py-3 text-left">
                            <div class="flex items-center gap-2">
                                <span>Russian</span>
                                <input type="checkbox"
                                       class="all_ru w-4 h-4 text-gray-700 dark:text-gray-300 rounded border-gray-300 dark:border-gray-600 focus:ring-gray-600 dark:bg-gray-700"
                                       id="all_ru"/>
                            </div>
                        </th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-600">
                    <template x-for="(item, index) in rows" :key="index">
                        <tr class="row hover:bg-orange-100 dark:hover:bg-gray-600 transition group">
                            <td class="px-4 py-3 hide_en">
                                <div class="flex flex-col gap-1">
                                    <span class="eng content resizeable_element text-gray-800 dark:text-gray-200"
                                          x-text="item[0]"></span>
                                </div>
                            </td>
                            <td class="px-2 py-3 text-center">
                                <input @click="memorizeSentence(index)" type="checkbox"
                                       class="check_en w-4 h-4 text-gray-700 dark:text-gray-300 rounded border-gray-300 dark:border-gray-600 focus:ring-gray-600 cursor-pointer dark:bg-gray-700"/>
                            </td>
                            <td class="px-2 py-3 text-center text-sm text-gray-500 dark:text-gray-400 resizeable_element"
                                x-text="index"></td>
                            <td class="px-2 py-3 text-center">
                                <input type="checkbox"
                                       class="check_ru w-4 h-4 text-gray-700 dark:text-gray-300 rounded border-gray-300 dark:border-gray-600 focus:ring-gray-600 cursor-pointer dark:bg-gray-700"/>
                            </td>
                            <td class="px-4 py-3 hide_ru">
                                <div class="flex flex-col gap-2">
                                    <span class="rus content resizeable_element text-gray-800 dark:text-gray-200"
                                          x-text="item[1]"></span>
                                    <div class="flex gap-1 opacity-0 group-hover:opacity-100 transition">
                                        <button
                                            class="px-2 py-1 bg-gray-700 dark:bg-gray-600 hover:bg-gray-800 dark:hover:bg-gray-500 hover:cursor-pointer text-white text-xs rounded transition"
                                            @click.prevent="openWorkplace()">
                                            Open
                                        </button>
                                        <button
                                            class="px-2 py-1 bg-emerald-600 dark:bg-emerald-700 hover:bg-emerald-700 dark:hover:bg-emerald-600 hover:cursor-pointer text-white text-xs rounded transition"
                                            @click.prevent="ask(item)">
                                            Ask
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </template>
                    </tbody>
                </table>
            </div>

            <!-- Bottom Control Bar - Fixed at bottom of viewport -->
            <div
                class="flex-none border-t-2 border-gray-400 dark:border-gray-600 bg-orange-100 dark:bg-gray-800 shadow-lg max-h-[40vh] overflow-y-auto pb-5">
                <div x-show="showWorkplace" x-transition class="p-3 border-b border-gray-300 dark:border-gray-600">
                    <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Workplace</label>
                    <textarea autocapitalize="on" rows="5" name="" id="workplace_textarea" x-ref="workplace"
                              x-model="text" placeholder="Type here..."
                              class="resizeable_element w-full px-3 py-2 border-2 border-gray-300 dark:border-gray-600 rounded-md focus:outline-none focus:ring-2 focus:ring-gray-600 dark:focus:ring-gray-500 focus:border-gray-600 resize-none bg-white dark:bg-gray-700 dark:text-gray-200 shadow-sm"></textarea>
                </div>
                <div x-show="showQuestion" x-transition class="p-3">
                    <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Question</label>
                    <textarea rows="2" name="" id="question_textarea" x-model="question" placeholder="Ask a question..."
                              class="resizeable_element w-full px-3 py-2 border-2 border-gray-300 dark:border-gray-600 rounded-md focus:outline-none focus:ring-2 focus:ring-gray-600 dark:focus:ring-gray-500 focus:border-gray-600 resize-none bg-white dark:bg-gray-700 dark:text-gray-200 shadow-sm"></textarea>
                </div>
            </div>
        </div>
        <!-- Middle Column: AI Response -->
        <div x-show="middleColumn" x-transition
             class="flex flex-col bg-orange-100 dark:bg-gray-800 border-r-2 border-gray-400 dark:border-gray-600 overflow-hidden shadow-sm"
             :style="`width: ${middleColumnWidth}px`">
            <!-- Width Controls -->
            <div
                class="flex-none flex items-center justify-end gap-1 p-2 border-b-2 border-gray-400 dark:border-gray-600 bg-orange-100 dark:bg-gray-800">
                <span class="text-xs font-semibold text-gray-600 dark:text-gray-400 mr-auto ml-2">AI Response</span>
                <button
                    class="w-7 h-7 flex items-center justify-center bg-gray-200 dark:bg-gray-600 hover:bg-gray-300 dark:hover:bg-gray-500 hover:cursor-pointer text-gray-800 dark:text-gray-200 rounded transition text-sm font-bold"
                    @click.prevent="changingWidth('+','middleColumnWidth')">
                    ←
                </button>
                <button
                    class="w-7 h-7 flex items-center justify-center bg-gray-200 dark:bg-gray-600 hover:bg-gray-300 dark:hover:bg-gray-500 hover:cursor-pointer text-gray-800 dark:text-gray-200 rounded transition text-sm font-bold"
                    @click.prevent="changingWidth('-','middleColumnWidth')">
                    →
                </button>
            </div>
            <!-- AI Answer Content -->
            <div class="flex-1 overflow-y-auto p-4 bg-white dark:bg-gray-700 pb-5 mb-5">
                <div x-html="aiAnswer" id="ai_answer_div"
                     class="resizeable_element prose prose-sm dark:prose-invert max-w-none"></div>
            </div>
        </div>

        <!-- Right Column: Dictionary -->
        <div x-show="rightColumn" x-transition
             class="flex flex-col bg-orange-100 dark:bg-gray-800 overflow-hidden shadow-sm"
             :style="`width: ${rightColumnWidth}px`">
            <!-- Controls -->
            <div
                class="flex-none flex items-center justify-between gap-2 p-2 border-b-2 border-gray-400 dark:border-gray-600 bg-orange-100 dark:bg-gray-800">
                <div class="flex gap-1">
                    <button
                        class="w-8 h-8 flex items-center justify-center bg-emerald-600 dark:bg-emerald-700 hover:bg-emerald-700 dark:hover:bg-emerald-600 hover:cursor-pointer text-white rounded transition shadow-sm"
                        @click.prevent="createAnki()" title="Create Anki Card">
                        +
                    </button>
                    <button
                        class="w-8 h-8 flex items-center justify-center bg-gray-700 dark:bg-gray-600 hover:bg-gray-800 dark:hover:bg-gray-500 hover:cursor-pointer text-white rounded transition shadow-sm"
                        @click.prevent="mnemonicSearch()" title="Search Mnemonics">
                        ?
                    </button>
                </div>
                <div class="flex gap-1">
                    <button
                        class="w-7 h-7 flex items-center justify-center bg-gray-200 dark:bg-gray-600 hover:bg-gray-300 dark:hover:bg-gray-500 hover:cursor-pointer text-gray-800 dark:text-gray-200 rounded transition text-sm font-bold"
                        @click.prevent="changingWidth('+','rightColumnWidth')">
                        ←
                    </button>
                    <button
                        class="w-7 h-7 flex items-center justify-center bg-gray-200 dark:bg-gray-600 hover:bg-gray-300 dark:hover:bg-gray-500 hover:cursor-pointer text-gray-800 dark:text-gray-200 rounded transition text-sm font-bold"
                        @click.prevent="changingWidth('-','rightColumnWidth')">
                        →
                    </button>
                </div>
            </div>

            <!-- Dictionary Content -->
            <div class="flex-1 overflow-y-auto p-4 space-y-4 bg-white dark:bg-gray-700 pb-5">
                <!-- Word Search -->
                <div class="space-y-2">
                    <div class="flex gap-2">
                        <input type="text" required x-model="word"
                               class="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:outline-none focus:ring-2 focus:ring-gray-600 dark:focus:ring-gray-500 focus:border-transparent text-sm bg-white dark:bg-gray-600 dark:text-gray-200"
                               placeholder="Enter word..."/>
                        <button type="button"
                                class="px-4 py-2 bg-gray-700 dark:bg-gray-600 hover:bg-gray-800 dark:hover:bg-gray-500 hover:cursor-pointer text-white text-sm rounded-md transition shadow-sm"
                                @click.prevent="searching()">
                            Search
                        </button>
                    </div>
                    <input type="text" placeholder="Phonetics..." x-bind:value="phonetics"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:outline-none focus:ring-2 focus:ring-gray-600 dark:focus:ring-gray-500 focus:border-transparent text-sm bg-gray-50 dark:bg-gray-600 dark:text-gray-200"/>
                </div>

                <!-- Mnemonic -->
                <div class="border-t border-b border-gray-200 dark:border-gray-600 py-3">
                    <span x-html="mnemonic" id="mnemonic_span" class="text-sm text-gray-700 dark:text-gray-300"></span>
                </div>

                <!-- Definitions -->
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Definitions</label>
                    <textarea name="" rows="8" placeholder="Definitions..." x-text="definitions"
                              class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:outline-none focus:ring-2 focus:ring-gray-600 dark:focus:ring-gray-500 focus:border-transparent resize-none text-sm bg-white dark:bg-gray-600 dark:text-gray-200"></textarea>
                </div>

                <!-- Translations -->
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Translations</label>
                    <textarea name="" rows="3" placeholder="Translations..." x-text="translations"
                              class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:outline-none focus:ring-2 focus:ring-gray-600 dark:focus:ring-gray-500 focus:border-transparent resize-none text-sm bg-white dark:bg-gray-600 dark:text-gray-200"></textarea>
                </div>

                <!-- Examples -->
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Examples</label>
                    <textarea name="" rows="4" placeholder="Examples..." x-text="examples"
                              class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:outline-none focus:ring-2 focus:ring-gray-600 dark:focus:ring-gray-500 focus:border-transparent resize-none text-sm bg-white dark:bg-gray-600 dark:text-gray-200"></textarea>
                </div>
            </div>
        </div>

    </div>
</div>
