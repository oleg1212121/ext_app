import React from "react";

export default function Spinner(props) {
    return (
        <>
            <div className="flex-none z-40">
                {props.pending === true && (
                    <div
                        className="px-4 sm:px-6 py-2 text-sm flex items-center gap-3 font-serif italic
                                   bg-[var(--color-vellum-deep)] dark:bg-[var(--color-hairline-night)]/40
                                   border-b border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)]
                                   text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70">
                        <svg className="animate-spin h-3.5 w-3.5 text-[var(--color-vermilion)] dark:text-[var(--color-vermilion-night)]"
                             xmlns="http://www.w3.org/2000/svg"
                             fill="none" viewBox="0 0 24 24">
                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
                            <path className="opacity-75" fill="currentColor"
                                  d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
                        </svg>
                        <span>Processing…</span>
                    </div>
                )}
                {props.errors?.length > 0 && (
                    <div
                        className="px-4 sm:px-6 py-2.5 text-sm flex items-center gap-2 font-medium
                                   bg-[var(--color-vermilion)]/10 dark:bg-[var(--color-vermilion-night)]/10
                                   border-b border-[var(--color-vermilion)] dark:border-[var(--color-vermilion-night)]
                                   text-[var(--color-vermilion)] dark:text-[var(--color-vermilion-night)]">
                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2"
                                  d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>Something went wrong. Try again.</span>
                    </div>
                )}
            </div>
        </>
    )
}