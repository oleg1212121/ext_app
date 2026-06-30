import EmptyCell from './EmptyCell';
import ArrowHorizontalCell from './ArrowHorizontalCell';
import ArrowVerticalCell from './ArrowVerticalCell';
import SymbolCell from './SymbolCell';
import {cellKey} from '../constants';

export default function CrosswordGrid({
    crossword,
    cellValues,
    onArrowClick,
    onSymbolClick,
    onCellKeyDown,
    registerInputRef,
    onAltKeyDown,
    onAltKeyUp,
}) {
    if (!crossword) {
        return null;
    }

    return (
        <div
            className="left flex-1 overflow-auto p-4"
            onKeyDown={(e) => e.altKey && onAltKeyDown()}
            onKeyUp={(e) => !e.altKey && onAltKeyUp()}
        >
            <div className="bg-white dark:bg-gray-700 rounded-md shadow-sm border-2 border-gray-400 dark:border-gray-600 p-4 inline-block">
                {crossword.newGrid.map((row, rowIndex) => (
                    <div className="row" key={rowIndex}>
                        {row.map((cell) => {
                            const key = cellKey(cell.y, cell.x);

                            if (cell.type === 1) {
                                return <EmptyCell key={key} cell={cell}/>;
                            }
                            if (cell.type === 2) {
                                return (
                                    <ArrowHorizontalCell
                                        key={key}
                                        cell={cell}
                                        onClick={onArrowClick}
                                    />
                                );
                            }
                            if (cell.type === 3) {
                                return (
                                    <ArrowVerticalCell
                                        key={key}
                                        cell={cell}
                                        onClick={onArrowClick}
                                    />
                                );
                            }
                            if (cell.type === 4) {
                                return (
                                    <SymbolCell
                                        key={key}
                                        cell={cell}
                                        value={cellValues[key] ?? ''}
                                        onKeyDown={onCellKeyDown}
                                        onClick={onSymbolClick}
                                        registerInputRef={registerInputRef}
                                    />
                                );
                            }

                            return null;
                        })}
                    </div>
                ))}
            </div>
        </div>
    );
}
