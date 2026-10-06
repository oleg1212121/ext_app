import {useEffect, useState} from 'react';
import {clampPage} from '../lib/pagination.mjs';

// Per-surface input policy: the simulator's toolbar input stays type=number
// with min/max and commits on Enter only; the reader's pager sits in a
// scroll area, so it is type=text + inputMode (Chrome's number input spins
// the value on wheel while focused) and commits on blur.
const VARIANTS = {
    sim: {
        type: 'number',
        commitOn: 'enter',
        className: 'w-14 rounded-sm border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] px-2 py-1 text-center font-[var(--wbench-mono)] text-xs text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)]',
    },
    reader: {
        type: 'text',
        inputMode: 'numeric',
        pattern: '[0-9]*',
        commitOn: 'blur',
        className: 'w-14 h-7 px-1 text-center bg-transparent border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] rounded-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)]',
    },
};

/**
 * The page-number input every reading surface's pager carries: it mirrors
 * the authoritative page, clamps typed values into [1, lastPage] on commit
 * (NaN reverts to the current page), normalizes the field, and calls
 * onCommit(target) only when the page actually changes. The commit action —
 * POST fetch on the simulator, Inertia partial reload on the reader — stays
 * with the surface.
 */
export default function PageInput({page, lastPage, onCommit, variant = 'reader', disabled = false, ariaLabel, id}) {
    const v = VARIANTS[variant] ?? VARIANTS.reader;
    const [input, setInput] = useState(String(page));

    useEffect(() => {
        setInput(String(page));
    }, [page]);

    const commit = () => {
        const parsed = parseInt(input, 10);
        const target = Number.isNaN(parsed) ? page : clampPage(parsed, lastPage);
        setInput(String(target));
        if (target !== page) {
            onCommit(target);
        }
    };

    return (
        <input
            id={id}
            type={v.type}
            {...(v.inputMode ? {inputMode: v.inputMode} : {})}
            {...(v.pattern ? {pattern: v.pattern} : {})}
            {...(v.type === 'number' ? {min: 1, max: lastPage} : {})}
            value={input}
            aria-label={ariaLabel}
            disabled={disabled}
            onChange={(event) => setInput(event.target.value)}
            onBlur={v.commitOn === 'blur' ? commit : undefined}
            onKeyDown={(event) => {
                if (event.key !== 'Enter') {
                    return;
                }
                event.preventDefault();
                if (v.commitOn === 'blur') {
                    event.currentTarget.blur();
                } else {
                    commit();
                }
            }}
            className={v.className}
        />
    );
}
