import {cellKey} from '../constants';

export default function SymbolCell({cell, value, onKeyDown, onClick, registerInputRef}) {
    return (
        <div className="cell symbol" onClick={() => onClick(cell.y, cell.x)}>
            <input
                type="text"
                id={cellKey(cell.y, cell.x)}
                ref={(el) => registerInputRef(cell.y, cell.x, el)}
                onKeyDown={onKeyDown}
                maxLength={1}
                className={`input_symbol border-0! m-0 p-0 ${cell.class ?? ''}`}
                data-y={cell.y}
                data-x={cell.x}
                style={{textTransform: 'uppercase'}}
                value={value}
                readOnly
                autoComplete="off"
            />
        </div>
    );
}
