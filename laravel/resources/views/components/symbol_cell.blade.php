 <div class="cell symbol" @click="clickSymbolCell(cell)">
     <input 
     type="text" 
     :id="cell.y+'.'+cell.x" 
     @keydown="changeCell($event)" 
     maxlength="0" 
     class="input_symbol"
     :data-y="cell.y"
     :data-x="cell.x"
     style="text-transform:uppercase"
     :class="cell.class"
     >
 </div>
