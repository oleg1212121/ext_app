import React from "react";

const VARIANTS = {
    dark: 'border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] bg-transparent text-[var(--color-ink)] dark:text-[var(--color-vellum-night)] hover:text-[var(--color-vermilion)] dark:hover:text-[var(--color-vermilion-night)] hover:border-[var(--color-vermilion)] dark:hover:border-[var(--color-vermilion-night)]',
    green: 'border border-[var(--color-vermilion)] dark:border-[var(--color-vermilion-night)] bg-[var(--color-vermilion)] dark:bg-[var(--color-vermilion-night)] text-[var(--color-vellum)] dark:text-[var(--color-ink-night)] hover:bg-transparent hover:text-[var(--color-vermilion)] dark:hover:text-[var(--color-vermilion-night)]',
};

const SIZES = {
    xs: 'px-2.5 py-1 text-xs',
    sm: 'px-3 py-1.5 text-sm',
    md: 'px-4 py-2 text-sm',
};

export default function Button({
    color = 'dark',
    size = 'md',
    outline = false,
    children,
    className,
    disabled,
    ...rest
}) {
    const variant = (outline && color === 'dark')
        ? 'border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] bg-transparent text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70 hover:text-[var(--color-vermilion)] dark:hover:text-[var(--color-vermilion-night)] hover:border-[var(--color-vermilion)] dark:hover:border-[var(--color-vermilion-night)]'
        : (VARIANTS[color] ?? VARIANTS.dark);

    return (
        <button
            type="button"
            disabled={disabled}
            className={[
                'inline-flex items-center justify-center gap-1.5 rounded-sm font-sans font-medium tracking-wide transition-colors duration-200 cursor-pointer',
                'focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[var(--color-vermilion)]',
                variant,
                SIZES[size] ?? SIZES.md,
                disabled ? 'opacity-50 cursor-not-allowed pointer-events-none' : '',
                className || '',
            ].join(' ')}
            {...rest}
        >
            {children}
        </button>
    );
}