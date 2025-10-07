
<div class="cell vertical"  @click="clickArrowCell(cell.y, cell.x)" :id="cell.y+'.'+cell.x">
    <span class="cell_text" x-text="cell.y+'.'+cell.x"></span>
</div>
