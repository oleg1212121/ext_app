import EmptyCell from './EmptyCell';
import ArrowHorizontalCell from './ArrowHorizontalCell';
import ArrowVerticalCell from './ArrowVerticalCell';
import SymbolCell from './SymbolCell';
import {cellKey} from '../constants';

function EmptySurface({isError, onRetry}) {
    if (isError) {
        return (
            <div className="flex-1 overflow-auto flex items-center justify-center p-4 bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)]">
                <div className="max-w-md text-center">
                    <p className="font-[var(--font-wbench-mono)] text-[10px] tracking-[0.24em] uppercase text-[var(--wbench-danger)] dark:text-[var(--wbench-danger-night)] mb-3">
                        Couldn&rsquo;t build
                    </p>
                    <p className="font-[var(--font-wbench-serif)] text-lg text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] leading-snug">
                        Something went wrong while generating the crossword.
                    </p>
                    <p className="mt-3 font-[var(--font-wbench-sans)] text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                        <button
                            type="button"
                            onClick={onRetry}
                            className="text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)] underline underline-offset-4 hover:text-[var(--wbench-accent-ink)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] rounded-sm"
                        >
                            Retry
                        </button>
                    </p>
                </div>
            </div>
        );
    }

    return (
        <div className="flex-1 overflow-auto flex items-center justify-center p-4 bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)]">
            <div className="max-w-md text-center">
                <p className="font-[var(--font-wbench-mono)] text-[10px] tracking-[0.24em] uppercase text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] mb-3">
                    No crossword loaded
                </p>
                <p className="font-[var(--font-wbench-serif)] text-lg text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] leading-snug">
                    Pick a text and a level, then press{' '}
                    <span className="font-[var(--font-wbench-sans)] font-medium">Build</span>{' '}
                    to generate a crossword.
                </p>
            </div>
        </div>
    );
}

export default function CrosswordGrid({
    crossword,
    cellValues,
    isError,
    onArrowClick,
    onSymbolClick,
    onCellKeyDown,
    registerInputRef,
    onAltKeyDown,
    onAltKeyUp,
    onRetry,
}) {
    if (!crossword) {
        return <EmptySurface isError={isError} onRetry={onRetry}/>;
    }

    return (
        <div
            className="left flex-1 overflow-auto p-4"
            onKeyDown={(e) => e.altKey && onAltKeyDown()}
            onKeyUp={(e) => !e.altKey && onAltKeyUp()}
        >
            <div className="crossword-board rounded-sm border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] p-4 inline-block">
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