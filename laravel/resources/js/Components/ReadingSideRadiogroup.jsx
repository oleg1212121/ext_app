import React from 'react';

// Variant class maps: each reading surface keeps its own palette and type —
// the simulator's workbench tokens, the reader's reading-room ones. The kit
// parameterizes them; it does not unify them.
const VARIANTS = {
    sim: {
        chassis: 'border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]',
        label: [
            'px-2 h-6 inline-flex items-center font-[var(--wbench-mono)] text-[11px] tracking-wide uppercase rounded-sm cursor-pointer select-none',
            'transition-colors duration-200 focus-within:outline-none focus-within:ring-2 focus-within:ring-[var(--color-vermilion)]',
            'text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] hover:text-[var(--wbench-ink)] dark:hover:text-[var(--wbench-ink-night)]',
        ].join(' '),
        active: 'bg-[var(--color-vermilion)] text-vellum dark:bg-[var(--color-vermilion-night)] dark:text-ink-night',
    },
    reader: {
        chassis: 'border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)]',
        label: [
            'px-2 h-7 inline-flex items-center font-sans text-xs tracking-wide rounded-sm cursor-pointer select-none',
            'transition-colors duration-150 focus-within:outline-none focus-within:ring-2 focus-within:ring-[var(--color-vermilion)]',
            'text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70 hover:text-[var(--color-ink)] dark:hover:text-[var(--color-vellum-night)]',
        ].join(' '),
        active: 'bg-[var(--color-vermilion)] text-vellum dark:bg-[var(--color-vermilion-night)] dark:text-ink-night',
    },
};

/**
 * The language side radiogroup every reading surface's toolbar carries: one
 * radio per canonical side, an sr-only input under a pill label, the active
 * side filled vermilion. Purely presentational — value semantics (side
 * letters on the simulator, language codes plus the same-code no-op guard
 * on the reader) stay with each surface through options/value/onChange.
 */
export default function ReadingSideRadiogroup({ariaLabel, name, options, value, onChange, variant = 'reader'}) {
    const v = VARIANTS[variant] ?? VARIANTS.reader;
    return (
        <div
            role="radiogroup"
            aria-label={ariaLabel}
            className={`flex items-center gap-0.5 border rounded-sm p-0.5 ${v.chassis}`}
        >
            {options.map((option, index) => (
                <label
                    // Index, not option.value: the reader keys radios by code
                    // and both sides may share one code.
                    key={index}
                    title={option.title}
                    className={`${v.label} ${value === option.value ? v.active : ''}`}
                >
                    <input
                        type="radio"
                        name={name}
                        value={option.value}
                        checked={value === option.value}
                        onChange={() => onChange(option.value)}
                        className="sr-only"
                    />
                    {option.label}
                </label>
            ))}
        </div>
    );
}
