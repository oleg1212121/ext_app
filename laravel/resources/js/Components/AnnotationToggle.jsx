import React from 'react';
import {Icon} from './icons.jsx';

// The simulator's tab primitives, one home for both toggle kinds below.
const simTabClass = (isActive) => [
    'relative inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium tracking-wide transition-colors duration-200 rounded-sm',
    'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)]',
    isActive
        ? 'text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]'
        : 'text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] hover:text-[var(--wbench-ink)] dark:hover:text-[var(--wbench-ink-night)]',
].join(' ');

const Underline = ({isActive}) => (
    <span
        aria-hidden="true"
        className={[
            'absolute left-1 right-1 -bottom-px h-[2px] bg-[var(--wbench-accent)] dark:bg-[var(--wbench-accent-night)]',
            'transition-transform duration-300 origin-left',
            isActive ? 'scale-x-100' : 'scale-x-0',
        ].join(' ')}
        style={{transformOrigin: 'left center'}}
    />
);

const greyIconClass = 'h-4 w-4 shrink-0 transition-colors text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]';
const accentIconClass = (active) => `h-4 w-4 shrink-0 transition-colors ${active ? 'text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)]' : greyIconClass}`;

/**
 * The simulator's panel-visibility tab: accent icon when active, the
 * underline alone carries the on-state.
 */
export function PanelToggleTab({active, label, icon, onClick}) {
    return (
        <button
            type="button"
            className={simTabClass(active)}
            aria-label={label}
            aria-pressed={active}
            title={label}
            onClick={onClick}
        >
            <Icon name={icon} className={accentIconClass(active)}/>
            <Underline isActive={active}/>
        </button>
    );
}

/**
 * The annotation toggle both reading surfaces carry for each enricher
 * (stress marks, phrasal verbs): a persistent, autosaved preference gated by
 * rowsHaveAnnotation. The toggle stays on for whole sessions, so the icon
 * keeps grey line-art always — the on-state rides the simulator's accent
 * underline or the reader's vermilion border alone. A tinted fill would read
 * as a plain accent-colored icon.
 */
export default function AnnotationToggle({active, label, icon, onClick, variant = 'reader'}) {
    if (variant === 'sim') {
        return (
            <button
                type="button"
                className={simTabClass(active)}
                aria-label={label}
                aria-pressed={active}
                title={label}
                onClick={onClick}
            >
                <Icon name={icon} className={greyIconClass}/>
                <Underline isActive={active}/>
            </button>
        );
    }
    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={label}
            aria-pressed={active}
            title={label}
            className={[
                'w-8 h-8 inline-flex items-center justify-center rounded-sm',
                'border transition-colors duration-150',
                'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)]',
                active
                    ? 'border-[var(--color-vermilion)] dark:border-[var(--color-vermilion-night)] text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70'
                    : 'border-[var(--color-hairline)] text-[var(--color-ink-soft)] dark:border-[var(--color-hairline-night)] dark:text-[var(--color-vellum-night)]/70 hover:border-[var(--color-ink)] dark:hover:border-[var(--color-vellum-night)] hover:text-[var(--color-ink)] dark:hover:text-[var(--color-vellum-night)]',
            ].join(' ')}
        >
            <Icon name={icon} className="h-4 w-4"/>
        </button>
    );
}
