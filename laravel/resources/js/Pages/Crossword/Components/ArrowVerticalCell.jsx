export default function ArrowVerticalCell({cell, onClick}) {
    return (
        <div
            className="cell vertical cursor-pointer hover:shadow-[0_0_2px_grey]"
            onClick={() => onClick(cell.y, cell.x)}
            id={`${cell.y}.${cell.x}`}
        >
            <span className="cell_text">{cell.y}.{cell.x}</span>
        </div>
    );
}
