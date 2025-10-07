 <div class="cell symbol" @click="clickSymbolCell(cell.y, cell.x)">
     <input
     type="text"
     :id="cell.y+'.'+cell.x"
     @keydown="changeCell($event)"
     maxlength="0"
     class="input_symbol border-0! m-0 p-0"
     :data-y="cell.y"
     :data-x="cell.x"
     style="text-transform:uppercase"
     :class="cell.class"
     :value="cell.answer"
     autocomplete="off"
     >
 </div>
