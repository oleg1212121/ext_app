<div class="body w-full h-full  overflow-hidden"
@mouseup.ctrl.alt="showSelectionModal()"
@dblclick="memorizeHighlight()"
@keydown.window="memorizeHighlight()"
>
    <div id="selection-modal" class="absolute bg-slate-300 z-50 p-1" x-show="pep">
        <span @click="saveSelection()" class="px-1 cursor-pointer text-green-500 hover:bg-green-100">+</span>
        <span class="cursor-pointer text-red-500 hover:bg-red-100" @click="pep = !pep">X</span>
    </div>
    <div class="flex flex-row justify-center">
        <div class="flex flex-row justify-center">
            <select class="bg-gray-50 border border-gray-300 text-gray-900 text-sm block w-full  pl-1 pr-5 py-1 "
                x-model="selectedChat">
                <template x-for="(item, index) in chats" :key="index">
                    <option :value="item" x-text="item" :selected="item == selectedChat"></option>
                </template>
            </select>
        </div>
        <div class="flex flex-row justify-center">
            <select class="bg-gray-50 border border-gray-300 text-gray-900 text-sm block w-full  pl-1 pr-5 py-1"
                x-model="filename">
                <template x-for="(item, index) in textsList" :key="index">
                    <option :value="item" x-text="item" :selected="item == filename"></option>
                </template>
            </select>
            <div id="search_button" type="button"
                class="bg-green-600 hover:bg-green-700 border border-solid border-white  cursor-pointer text-white  p-1 rounded"
                @click.prevent="searchFile()">
                S..
            </div>
        </div>
        <div class="flex flex-row">
            <div class="bg-green-600 hover:bg-green-700 border border-solid border-white  cursor-pointer text-white p-1 rounded"
                @click.prevent="changeFontSize('+')">
                +
            </div>
            <div class="bg-green-600 hover:bg-green-700 border border-solid border-white  cursor-pointer text-white p-1 rounded"
                @click.prevent="changeFontSize('-')">
                -
            </div>
        </div>
        <div class="flex flex-row">
            <div class="bg-green-600 hover:bg-green-700 border border-solid border-white  cursor-pointer text-white p-1 rounded"
                @click.prevent="showWorkplace = !showWorkplace" :class="{ pushed: showWorkplace }">
                W..
            </div>
            <div class="bg-green-600 hover:bg-green-700 border border-solid border-white  cursor-pointer text-white p-1 rounded"
                @click.prevent="showQuestion = !showQuestion" :class="{ pushed: showQuestion }">
                Q..
            </div>
            <div @click.prevent="leftColumn = ! leftColumn"
                class="bg-green-600 hover:bg-green-700 border border-solid border-white  cursor-pointer text-white p-1 rounded"
                :class="{ pushed: leftColumn }">
                L..
            </div>
            <div @click.prevent="middleColumn = ! middleColumn"
                class="bg-green-600 hover:bg-green-700 border border-solid border-white  cursor-pointer text-white p-1 rounded"
                :class="{ pushed: middleColumn }">
                M..
            </div>

        </div>
    </div>
    <div class="information_bar">
        <div class="spinner" x-show="spinner" x-transition>
            Process... Process... Process... Process... Process... Process...
            Process... Process... Process... Process... Process... Process...
        </div>
        <div x-show="isError" class="error" x-transition>
            Error... Error... Error... Error... Error... Error... Error... Error...
            Error... Error... Error... Error... Error... Error... Error... Error...
        </div>
    </div>
    <div class="page_container"
    {{-- @mouseup.ctrl.slash.debounce.100="contextModalShow()" --}}
    >
        <div class="left_column">
            <div class="left_content" x-show="leftColumn">
                <table class="table">
                    <thead>
                        <tr class="header">
                            <th class="left item">
                                English <input type="checkbox" class="all_en" id="all_en" />
                            </th>
                            <th class="mid item">En</th>
                            <th class="mid item">N</th>
                            <th class="mid item">Ru</th>
                            <th class="right item">
                                Russian <input type="checkbox" class="all_ru" id="all_ru" />
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(item, index) in rows" :key="index">
                            <tr class="row hover:bg-orange-50 cursor-pointer">
                                <td class="left item hide_en">
                                    <div class="cell_container">
                                        <div class="text_container">
                                            <span class="eng content" x-text="item[0]"></span>
                                        </div>
                                        <div class="buttons_container"></div>
                                    </div>
                                </td>
                                <td class="mid item">
                                    <input @click="memorizeSentence(index)" type="checkbox" class="check_en" />
                                </td>
                                <td class="mid item" x-text="index"></td>
                                <td class="mid item">
                                    <input type="checkbox" class="check_ru" />
                                </td>
                                <td class="right item hide_ru">
                                    <div class="cell_container">
                                        <div class="text_container">
                                            <span class="rus content" x-text="item[1]"></span>
                                        </div>
                                        <div class="flex flex-row justify-start">
                                            <div class="bg-green-600 hover:bg-green-700 border border-solid border-white  cursor-pointer text-white rounded p-1 text-center items-center hover:opacity-100 opacity-20"
                                                @click.prevent="openWorkplace()">
                                                <span>Open</span>
                                            </div>
                                            <div class="bg-green-600 hover:bg-green-700 border border-solid border-white  cursor-pointer text-white p-1 rounded text-center items-center  hover:opacity-100 opacity-20"
                                                @click.prevent="ask(item)">
                                                <span>Ask</span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
                <br />
            </div>
            <div class="context_modal" x-show="contextModal">
                <div class="mright_content" x-transition>
                    <div class="input_class">
                        <div>
                            <div class="bg-green-600 hover:bg-green-700 border border-solid border-white  cursor-pointer text-white p-1 rounded pushed"
                                @click.prevent="createAnki()">
                                +
                            </div>
                            <div class="bg-green-600 hover:bg-green-700 border border-solid border-white  cursor-pointer text-white p-1 rounded pushed"
                                @click.prevent="mnemonicSearch()">
                                ?
                            </div>
                            <div class="bg-green-600 hover:bg-green-700 border border-solid border-white  cursor-pointer text-white p-1 rounded"
                                @click.prevent="contextModal = !contextModal">
                                X
                            </div>
                        </div>
                    </div>
                    <div class="">
                        <div class="input_class">
                            <div id="search_button" type="button"
                                class="bg-green-600 hover:bg-green-700 border border-solid border-white  cursor-pointer text-white p-1 rounded"
                                @click.prevent="searching()">
                                Search...
                            </div>

                            <input type="text" class="" required x-model="word" />
                        </div>
                        <div class="input_class">
                            <input type="text" class="" placeholder="Phonetics..."
                                x-bind:value="phonetics" />
                        </div>
                    </div>
                    <div class="">
                        <div class="input_class">
                            <hr />
                            <span x-html="mnemonic" id="mnemonic_span"></span>
                            <hr />
                        </div>
                    </div>
                    <div class="">
                        <div class="input_class">
                            <textarea name="" id="" rows="15" class="" placeholder="Definitions..."
                                x-text="definitions"></textarea>
                        </div>
                    </div>
                    <div class="">
                        <div class="input_class">
                            <textarea name="" rows="12" class="" placeholder="Translations..." x-text="translations"></textarea>
                        </div>
                    </div>
                </div>
            </div>
            <div class="control_bar" x-show="leftColumn">
                <div x-show="showWorkplace" x-transition>
                    <textarea autocapitalize="on" rows="11" name="" id="workplace_textarea" x-ref="workplace"
                        x-model="text" placeholder="Workplace..."></textarea>
                </div>
                <div x-show="showQuestion" x-transition>
                    <textarea rows="4" name="" id="question_textarea" x-model="question" placeholder="Question..."></textarea>
                </div>
            </div>
        </div>
        <div class="middle_column">
            <div class="middle_content flex flex-col " x-show="middleColumn" x-transition
                :style="`width: ${middleColumnWidth}px`">
                <div class="flex flex-row justify-end">
                    <div class="bg-green-600 hover:bg-green-700 border border-solid border-white  cursor-pointer text-white p-1 rounded"
                        @click.prevent="changingWidth('+','middleColumnWidth')">
                        <span class="text-xs">
                            < </span>
                    </div>
                    <div class="bg-green-600 hover:bg-green-700 border border-solid border-white  cursor-pointer text-white font-bold p-1 rounded"
                        @click.prevent="changingWidth('-','middleColumnWidth')">
                        <span class="text-xs">></span>
                    </div>
                </div>
                <div x-html="aiAnswer" id="ai_answer_div"></div>
            </div>
        </div>

        <div class="right_column">
            <div x-show="rightColumn" class="right_content" x-transition :style="`width: ${rightColumnWidth}px`">
                <div class="">
                    <div>
                        <div class="bg-green-600 hover:bg-green-700 border border-solid border-white  cursor-pointer text-white p-1 rounded"
                            @click.prevent="createAnki()">
                            +
                        </div>

                        <div class="bg-green-600 hover:bg-green-700 border border-solid border-white  cursor-pointer text-white p-1 rounded"
                            @click.prevent="mnemonicSearch()">
                            ?
                        </div>
                    </div>
                    <div>
                        <div class="bg-green-600 hover:bg-green-700 border border-solid border-white  cursor-pointer text-white p-1 rounded"
                            @click.prevent="changingWidth('+','rightColumnWidth')">
                            < </div>
                                <div class="bg-green-600 hover:bg-green-700 border border-solid border-white  cursor-pointer text-white p-1 rounded"
                                    @click.prevent="changingWidth('-','rightColumnWidth')">
                                    >
                                </div>
                        </div>
                    </div>
                    <div class="">
                        <div class="input_class">
                            <div id="search_button" type="button"
                                class="bg-green-600 hover:bg-green-700 border border-solid border-white  cursor-pointer text-white p-1 rounded"
                                @click.prevent="searching()">
                                Search...
                            </div>

                            <input type="text" class="" required x-model="word" />
                        </div>
                        <div class="input_class">
                            <input type="text" class="" placeholder="Phonetics..."
                                x-bind:value="phonetics" />
                        </div>
                    </div>
                    <div class="">
                        <div class="input_class">
                            <hr />
                            <span x-html="mnemonic" id="mnemonic_span"></span>
                            <hr />
                        </div>
                    </div>
                    <div class="">
                        <div class="input_class">
                            <textarea name="" id="" rows="10" class="" placeholder="Definitions..."
                                x-text="definitions"></textarea>
                        </div>
                    </div>
                    <div class="">
                        <div class="input_class">
                            <textarea name="" rows="4" class="" placeholder="Translations..." x-text="translations"></textarea>
                        </div>
                    </div>
                    <div class="">
                        <div class="input_class">
                            <textarea name="" rows="5" class="" placeholder="Examples..." x-text="examples"></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>
