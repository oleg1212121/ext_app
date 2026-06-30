export default function EmptyCell({cell}) {
    return (
        <div className="cell empty" id={`${cell.y}.${cell.x}`}>
            <span>{' '}</span>
        </div>
    );
}
