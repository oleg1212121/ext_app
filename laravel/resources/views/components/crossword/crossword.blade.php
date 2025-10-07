<div>
    <div x-data="main" class="main flex flex-col">
        <!-- Unsolved Words Modal -->
        <div x-show="showUnsolvedModal" class="fixed inset-0 z-50 flex items-center justify-center">
            <div class="absolute inset-0 bg-black bg-opacity-50" @click="showUnsolvedModal = false"></div>
            <div class="relative bg-white rounded-lg shadow-lg w-11/12 md:w-5/6 lg:w-3/4 max-h-[90vh] overflow-y-auto p-4">
                <div class="flex justify-between items-center mb-2">
                    <p class="text-4xl font-bold">Unsolved words</p>
                    <button class="text-gray-600 hover:text-gray-800" @click="showUnsolvedModal = false">✕</button>
                </div>
                <ul class="list-decimal pl-5 space-y-2">
                    <template x-if="!crossword || !crossword.dictionary">
                        <li class="text-gray-500">No crossword loaded</li>
                    </template>
                    <template x-for="(item, index) in unsolvedList()" :key="item.word">
                        <li>
                            {{-- <span class="text-2xl font-semibold" x-text="(index + 1) + '. ' + item.word"></span> --}}
                            <span class="text-2xl font-semibold" x-text="item.word"></span>
                            <template x-for="(definition, index2) in item.definitions" :key="index2">
                                <span class="text-xl block m-0" x-text="definition"></span>
                            </template>
                            {{-- <div class="text-sm text-gray-700" x-text="item.definitions && item.definitions.length ? item.definitions.join('<br>; ') : 'No definitions'"></div> --}}
                        </li>
                    </template>
                </ul>
            </div>
        </div>
        <div class='menu flex flex-row'>
            <div class='textes_select'>
                <select name="" id="" class="select_text" x-model="currentText"

                >
                    <template x-for="text in textes">
                        <option x-text="text.name" :value="text.id"></option>
                    </template>
                </select>
            </div>
            <div class='levels_select'>
                <select name="" id="" class="" x-model="currentLevel"

                >
                    <template x-for="level in wordLevels">
                        <option x-text="level.name" :value="level.id"></option>
                    </template>
                </select>
            </div>
            <div class="border border-white text-white bg-green-600 hover:bg-green-500 px-3 py-2 shadow-md cursor-pointer">
                <div class="" x-on:click.debounce="getCrossword()">Build</div>
            </div>

        </div>

        <div class="workspace flex flex-row">
            <div class="left" x-on:keydown.alt.debounce.500="setAltBlock()" x-on:keyup.alt.debounce.500="unsetAltBlock()">
                <template x-if="crossword">
                    <div>
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
            <div class="right p-1 flex flex-col">
                <div class="flex flex-row justify-stretch">
                    <div @click="currentTab = 0"
                    class="flex-2 border text-center text-white bg-green-600 hover:bg-green-500 font-bold py-3 px-6 my-1 rounded-lg shadow-md cursor-pointer">
                        DEF
                    </div>
                    <div @click="currentTab = 1"
                    class="flex-1 border text-center text-white bg-green-600 hover:bg-green-500 font-bold py-3 px-6 my-1 rounded-lg shadow-md cursor-pointer">
                        OBS
                    </div>
                    <div @click="currentTab = 2"
                    class="flex-1 border text-center text-white bg-green-600 hover:bg-green-500 font-bold py-3 px-6 my-1 rounded-lg shadow-md cursor-pointer">
                        RU
                    </div>
                    <div @click="currentTab = 3"
                    class="flex-1 border text-center text-white bg-green-600 hover:bg-green-500 font-bold py-3 px-6 my-1 rounded-lg shadow-md cursor-pointer">
                        FORMS
                    </div>
                    <div @click="_checkImage()"
                    class="flex-1 border text-center text-white bg-green-600 hover:bg-green-500 font-bold py-3 px-6 my-1 rounded-lg shadow-md cursor-pointer">
                        I
                    </div>
                    <div class="flex-2">
                        <div @click.debounce="_askAI()"
                        class=" border text-center text-white bg-blue-600 hover:bg-blue-500 font-bold py-3 px-6 my-1 rounded-lg shadow-md cursor-pointer">

                            Search
                        </div>
                    </div>
                    <div class="flex-1">
                        <div
                            class="border text-center text-white bg-blue-600 hover:bg-blue-500 font-bold py-3 px-6 my-1 rounded-lg shadow-md cursor-pointer flex items-center ml-2"
                            title="Approve"
                            type="button"
                            @click.debounce="_acknowledge()"
                        >
                            <!-- Approve icon SVG (checkmark) -->
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M5 13l4 4L19 7" />
                            </svg>
                            Up
                        </div>
                    </div>
                    <div class="flex-1">
                        <div
                            class="border text-center text-white bg-red-600 hover:bg-red-500 font-bold py-3 px-6 my-1 rounded-lg shadow-md cursor-pointer flex items-center ml-2"
                            title="Delete"
                            type="button"
                            @click.debounce="_dismiss()"
                        >
                            <!-- Delete icon SVG -->
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12" />
                            </svg>
                            Del
                        </div>
                    </div>
                    <div class="flex-1">
                        <div
                            class="border text-center text-white bg-yellow-600 hover:bg-yellow-500 font-bold py-3 px-6 my-1 rounded-lg shadow-md cursor-pointer flex items-center ml-2"
                            title="Open"
                            type="button"
                            @click.debounce="showUnsolvedModal = true"
                        >
                            <!-- Open icon SVG (plus sign) -->
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                            </svg>
                            Look
                        </div>
                    </div>

                </div>
                <div class="flex flex-row">
                    <div x-show="currentTab == 0" class="w-full">
                        <ol class='definitions'>
                            <template x-for="(definition, index) in definitions" :key="index">
                                <li
                                :class="{blue : (index % 2) == 0, antique : (index % 2) == 1}"
                                class="px-1"
                                >
                                    <span class="" x-text="(index+1) + '.'"></span>
                                    <span
                                    x-text="definition"
                                    ></span>
                                </li>
                            </template>
                        </ol>
                    </div>
                    <div x-show="currentTab == 1" class="w-full">
                        <ol class='obsolete'>
                            <template x-for="(def, index) in obsolete" :key="index">
                                <li
                                :class="{blue : (index % 2) == 0, antique : (index % 2) == 1}"
                                class="px-1"
                                >
                                    <span class="" x-text="(index+1) + '.'"></span>
                                    <span
                                    x-text="def"
                                    ></span>
                                </li>
                            </template>
                        </ol>
                    </div>
                    <div x-show="currentTab == 2"  class="w-full">
                        <ol class='translations'>

                            <template x-for="(translation, index) in translations" :key="index">
                                <li
                                    :class="{blue : (index % 2) == 0, antique : (index % 2) == 1}"
                                    class="px-1"
                                >
                                    <span class="" x-text="(index+1) + '.'"></span>
                                    <span
                                    x-text="translation"
                                    ></span>
                                </li>
                            </template>
                        </ol>
                    </div>
                    <div x-show="currentTab == 3"  class="w-full">
                        <ol class='forms'>

                            <template x-for="(form, index) in forms" :key="index">
                                <li
                                    :class="{blue : (index % 2) == 0, antique : (index % 2) == 1}"
                                    class="px-1"
                                >
                                    <span class="" x-text="(index+1) + '.'"></span>
                                    <span
                                    x-text="form"
                                    ></span>
                                </li>
                            </template>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
