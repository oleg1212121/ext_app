<div id="crosswordRoot" class="min-h-screen flex flex-col bg-orange-50 dark:bg-gray-900">
    <div x-data="main" class="flex flex-col h-screen">
        <!-- Unsolved Words Modal -->
        <div x-show="showUnsolvedModal" class="fixed inset-0 z-50 flex items-center justify-center">
            <div class="absolute inset-0 bg-black bg-opacity-50" @click="showUnsolvedModal = false"></div>
            <div class="relative bg-white dark:bg-gray-800 rounded-md shadow-sm border-2 border-gray-400 dark:border-gray-600 w-11/12 md:w-5/6 lg:w-3/4 max-h-[90vh] overflow-y-auto p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-2xl font-semibold text-gray-800 dark:text-gray-100">Unsolved Words</h2>
                    <button class="text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:cursor-pointer text-2xl" @click="showUnsolvedModal = false">✕</button>
                </div>
                <ul class="list-decimal pl-5 space-y-3">
                    <template x-if="!crossword || !crossword.dictionary">
                        <li class="text-gray-500 dark:text-gray-400">No crossword loaded</li>
                    </template>
                    <template x-for="(item, index) in unsolvedList()" :key="item.word">
                        <li>
                            <span class="text-xl font-semibold text-gray-800 dark:text-gray-100" x-text="item.word"></span>
                            <template x-for="(definition, index2) in item.definitions" :key="index2">
                                <span class="text-base text-gray-700 dark:text-gray-300 block mt-1" x-text="definition"></span>
                            </template>
                        </li>
                    </template>
                </ul>
            </div>
        </div>

        <!-- Header / Menu -->
        <header class="flex-none bg-white dark:bg-gray-800 border-b-2 border-gray-400 dark:border-gray-600 shadow-md">
            <div class="flex flex-wrap items-center justify-center gap-3 px-4 py-3">
                <!-- Title -->
                <div class="flex items-center gap-2">
                    <h1 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Crossword Puzzle</h1>
                </div>

                <div class="h-6 w-px bg-gray-400 dark:bg-gray-600"></div>

                <!-- Text Select -->
                <select class="px-3 py-1.5 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 text-sm rounded border border-gray-300 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-600 dark:focus:ring-gray-500 transition" x-model="currentText">
                    <template x-for="text in texts">
                        <option x-text="text.name" :value="text.id"></option>
                    </template>
                </select>

                <!-- Level Select -->
                <select class="px-3 py-1.5 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 text-sm rounded border border-gray-300 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-600 dark:focus:ring-gray-500 transition" x-model="currentLevel">
                    <template x-for="level in wordLevels">
                        <option x-text="level.name" :value="level.id"></option>
                    </template>
                </select>

                <!-- Build Button -->
                <button type="button" class="px-3 py-1.5 bg-gray-700 dark:bg-gray-600 hover:bg-gray-800 dark:hover:bg-gray-500 hover:cursor-pointer text-white text-sm rounded transition" x-on:click.debounce="getCrossword()">
                    Build Crossword
                </button>
            </div>
        </header>

        <!-- Main Workspace -->
        <main class="flex-1 flex flex-row overflow-hidden bg-orange-100 dark:bg-gray-800">
            <!-- Left Panel: Crossword Grid -->
            <div class="left flex-1 overflow-auto p-4" x-on:keydown.alt.debounce.500="setAltBlock()" x-on:keyup.alt.debounce.500="unsetAltBlock()">
                <template x-if="crossword">
                    <div class="bg-white dark:bg-gray-700 rounded-md shadow-sm border-2 border-gray-400 dark:border-gray-600 p-4 inline-block">
                        <template x-for="row in crossword.newGrid">
                            <div class="row">
                                <template x-for="cell in row" :key="cell.y + cell.x">
                                    <div>
                                        <template x-if="cell.type === 1">
                                            <x-crossword.empty_cell />
                                        </template>
                                        <template x-if="cell.type === 2">
                                            <x-crossword.arrow_horizontal_cell />
                                        </template>
                                        <template x-if="cell.type === 3">
                                            <x-crossword.arrow_vertical_cell />
                                        </template>
                                        <template x-if="cell.type === 4">
                                            <x-crossword.symbol_cell />
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>
            </div>

            <!-- Right Panel Resize Handle -->
            <div class="drag-handle-vertical"
                role="separator"
                aria-orientation="vertical"
                title="Resize definitions panel"
                @mousedown.prevent="startDragRightPanel($event)"></div>

            <!-- Right Panel: Definitions & Controls -->
            <div class="right overflow-auto p-4 flex flex-col bg-white dark:bg-gray-700 border-l-2 border-gray-400 dark:border-gray-600"
                :style="`width: ${rightPanelWidth}px`">
                <!-- Tab Navigation & Action Buttons -->
                <div class="flex flex-wrap gap-2 mb-4 pb-4 border-b-2 border-gray-200 dark:border-gray-600">
                    <!-- Tab Buttons -->
                    <button @click="currentTab = 0"
                        class="px-3 py-2 bg-gray-100 dark:bg-gray-600 hover:bg-gray-200 dark:hover:bg-gray-500 hover:cursor-pointer text-gray-700 dark:text-gray-200 text-sm rounded transition font-medium"
                        :class="{'bg-gray-700 dark:bg-gray-500 text-white hover:bg-gray-800 dark:hover:bg-gray-400': currentTab === 0}">
                        Definitions
                    </button>
                    <button @click="currentTab = 1"
                        class="px-3 py-2 bg-gray-100 dark:bg-gray-600 hover:bg-gray-200 dark:hover:bg-gray-500 hover:cursor-pointer text-gray-700 dark:text-gray-200 text-sm rounded transition font-medium"
                        :class="{'bg-gray-700 dark:bg-gray-500 text-white hover:bg-gray-800 dark:hover:bg-gray-400': currentTab === 1}">
                        Obsolete
                    </button>
                    <button @click="currentTab = 2"
                        class="px-3 py-2 bg-gray-100 dark:bg-gray-600 hover:bg-gray-200 dark:hover:bg-gray-500 hover:cursor-pointer text-gray-700 dark:text-gray-200 text-sm rounded transition font-medium"
                        :class="{'bg-gray-700 dark:bg-gray-500 text-white hover:bg-gray-800 dark:hover:bg-gray-400': currentTab === 2}">
                        Russian
                    </button>
                    <button @click="currentTab = 3"
                        class="px-3 py-2 bg-gray-100 dark:bg-gray-600 hover:bg-gray-200 dark:hover:bg-gray-500 hover:cursor-pointer text-gray-700 dark:text-gray-200 text-sm rounded transition font-medium"
                        :class="{'bg-gray-700 dark:bg-gray-500 text-white hover:bg-gray-800 dark:hover:bg-gray-400': currentTab === 3}">
                        Forms
                    </button>
                    <button @click="_checkImage()"
                        class="px-3 py-2 bg-gray-100 dark:bg-gray-600 hover:bg-gray-200 dark:hover:bg-gray-500 hover:cursor-pointer text-gray-700 dark:text-gray-200 text-sm rounded transition font-medium">
                        Image
                    </button>

                    <div class="h-6 w-px bg-gray-300 dark:bg-gray-500"></div>

                    <!-- Action Buttons -->
                    <button @click.debounce="_askAI()"
                        class="px-3 py-2 bg-gray-700 dark:bg-gray-600 hover:bg-gray-800 dark:hover:bg-gray-500 hover:cursor-pointer text-white text-sm rounded transition flex items-center gap-1">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        Search
                    </button>
                    <button @click.debounce="_acknowledge()"
                        title="Approve"
                        class="px-3 py-2 bg-gray-700 dark:bg-gray-600 hover:bg-gray-800 dark:hover:bg-gray-500 hover:cursor-pointer text-white text-sm rounded transition flex items-center gap-1">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        Approve
                    </button>
                    <button @click.debounce="_dismiss()"
                        title="Delete"
                        class="px-3 py-2 bg-gray-700 dark:bg-gray-600 hover:bg-gray-800 dark:hover:bg-gray-500 hover:cursor-pointer text-white text-sm rounded transition flex items-center gap-1">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                        Delete
                    </button>
                    <button @click.debounce="showUnsolvedModal = true"
                        title="Show unsolved words"
                        class="px-3 py-2 bg-gray-700 dark:bg-gray-600 hover:bg-gray-800 dark:hover:bg-gray-500 hover:cursor-pointer text-white text-sm rounded transition flex items-center gap-1">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                        Unsolved
                    </button>
                </div>
                <!-- Tab Content -->
                <div class="flex-1 overflow-auto">
                    <!-- Definitions Tab -->
                    <div x-show="currentTab == 0" class="w-full">
                        <div class="space-y-2">
                            <template x-for="(definition, index) in definitions" :key="index">
                                <div class="flex gap-2 text-2xl text-gray-800 dark:text-gray-200 leading-relaxed p-2 rounded hover:bg-orange-100 dark:hover:bg-gray-600 transition-colors duration-150">
                                    <span class="font-semibold min-w-[3rem]" x-text="(index + 1) + '.'"></span>
                                    <span x-text="definition"></span>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- Obsolete Tab -->
                    <div x-show="currentTab == 1" class="w-full">
                        <div class="space-y-2">
                            <template x-for="(def, index) in obsolete" :key="index">
                                <div class="flex gap-2 text-2xl text-gray-800 dark:text-gray-200 leading-relaxed p-2 rounded hover:bg-orange-100 dark:hover:bg-gray-600 transition-colors duration-150">
                                    <span class="font-semibold min-w-[3rem]" x-text="(index + 1) + '.'"></span>
                                    <span x-text="def"></span>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- Translations Tab -->
                    <div x-show="currentTab == 2" class="w-full">
                        <div class="space-y-2">
                            <template x-for="(translation, index) in translations" :key="index">
                                <div class="flex gap-2 text-2xl text-emerald-700 dark:text-emerald-400 leading-relaxed p-2 rounded hover:bg-orange-100 dark:hover:bg-gray-600 transition-colors duration-150">
                                    <span class="font-semibold min-w-[3rem]" x-text="(index + 1) + '.'"></span>
                                    <span x-text="translation"></span>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- Forms Tab -->
                    <div x-show="currentTab == 3" class="w-full">
                        <div class="space-y-2">
                            <template x-for="(form, index) in forms" :key="index">
                                <div class="flex gap-2 text-2xl text-gray-800 dark:text-gray-200 leading-relaxed p-2 rounded hover:bg-orange-100 dark:hover:bg-gray-600 transition-colors duration-150">
                                    <span class="font-semibold min-w-[3rem]" x-text="(index + 1) + '.'"></span>
                                    <span x-text="form"></span>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>
