import React, {useEffect, useState} from 'react'
import {useI18n} from '../../i18n'

// Shared local primitives for the Profile page: form controls, buttons and
// layout wrappers, all on the page's legacy vellum/vermilion palette.

export function SectionHeader({eyebrow, title, description, danger = false}) {
    return (
        <header className="flex flex-col gap-1">
            <span
                className={`font-serif italic text-[10px] tracking-[0.22em] uppercase ${
                    danger
                        ? 'text-[var(--color-vermilion)] dark:text-[var(--color-vermilion-night)]'
                        : 'text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)]'
                }`}
            >
                {eyebrow}
            </span>
            <h2 className="font-serif text-xl sm:text-2xl tracking-tight text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]">
                {title}
            </h2>
            {description && (
                <p className="mt-2 max-w-md font-serif italic text-sm text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70">
                    {description}
                </p>
            )}
        </header>
    )
}

export function InputLabel({htmlFor, children}) {
    return (
        <label
            htmlFor={htmlFor}
            className="block text-sm font-medium text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]"
        >
            {children}
        </label>
    )
}

export function TextInput({id, type = 'text', value, onChange, error, ...props}) {
    return (
        <input
            id={id}
            type={type}
            value={value}
            onChange={onChange}
            className={[
                'mt-1 block w-full rounded-sm border px-3 py-2 text-sm shadow-sm',
                'bg-[var(--color-vellum)] dark:bg-[var(--color-ink-night)]',
                'text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]',
                'placeholder:text-[var(--color-ink-soft)]/50 dark:placeholder:text-[var(--color-vellum-night)]/40',
                error
                    ? 'border-[var(--color-vermilion)] dark:border-[var(--color-vermilion-night)]'
                    : 'border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)]',
                'focus:outline-none focus:ring-2 focus:ring-[var(--color-vermilion)] dark:focus:ring-[var(--color-vermilion-night)] focus:border-transparent',
            ].join(' ')}
            {...props}
        />
    )
}

export function InputError({messages = []}) {
    if (!messages.length) return null
    return (
        <p className="mt-2 text-sm text-[var(--color-vermilion)] dark:text-[var(--color-vermilion-night)]">
            {messages.join(' ')}
        </p>
    )
}

export function SelectInput({id, value, onChange, children, error, disabled = false, ...props}) {
    return (
        <select
            id={id}
            value={value}
            onChange={onChange}
            disabled={disabled}
            className={[
                'mt-1 block w-full rounded-sm border px-3 py-2 text-sm shadow-sm',
                'bg-[var(--color-vellum)] dark:bg-[var(--color-ink-night)]',
                'text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]',
                'disabled:opacity-50',
                error
                    ? 'border-[var(--color-vermilion)] dark:border-[var(--color-vermilion-night)]'
                    : 'border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)]',
                'focus:outline-none focus:ring-2 focus:ring-[var(--color-vermilion)] dark:focus:ring-[var(--color-vermilion-night)] focus:border-transparent',
            ].join(' ')}
            {...props}
        >
            {children}
        </select>
    )
}

export function PrimaryButton({children, disabled = false, className = ''}) {
    return (
        <button
            type="submit"
            disabled={disabled}
            className={[
                'inline-flex items-center px-4 py-2 rounded-sm text-sm font-medium transition-colors',
                'bg-[var(--color-vermilion)] dark:bg-[var(--color-vermilion-night)]',
                'text-white dark:text-[var(--color-ink-night)]',
                'hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)] dark:focus-visible:ring-[var(--color-vermilion-night)]',
                'disabled:opacity-50',
                className,
            ].join(' ')}
        >
            {children}
        </button>
    )
}

export function DangerButton({children, onClick, type = 'button', className = ''}) {
    return (
        <button
            type={type}
            onClick={onClick}
            className={[
                'inline-flex items-center px-4 py-2 rounded-sm text-sm font-medium transition-colors',
                'bg-[var(--color-vermilion)] dark:bg-[var(--color-vermilion-night)]',
                'text-white dark:text-[var(--color-ink-night)]',
                'hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)] dark:focus-visible:ring-[var(--color-vermilion-night)]',
                className,
            ].join(' ')}
        >
            {children}
        </button>
    )
}

export function SecondaryButton({children, onClick, type = 'button'}) {
    return (
        <button
            type={type}
            onClick={onClick}
            className={[
                'inline-flex items-center px-4 py-2 rounded-sm text-sm font-medium transition-colors',
                'bg-[var(--color-vellum-deep)] dark:bg-[var(--color-hairline-night)]',
                'text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]',
                'hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)] dark:focus-visible:ring-[var(--color-vermilion-night)]',
            ].join(' ')}
        >
            {children}
        </button>
    )
}

export function SavedMessage({show}) {
    const { t } = useI18n()
    const [visible, setVisible] = useState(show)

    useEffect(() => {
        if (show) {
            setVisible(true)
            const timer = setTimeout(() => setVisible(false), 2000)
            return () => clearTimeout(timer)
        }
    }, [show])

    if (!visible) return null

    return (
        <p className="font-serif italic text-sm text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)]">
            {t('profile.saved')}
        </p>
    )
}

export function Modal({show, onClose, children}) {
    useEffect(() => {
        if (show) {
            document.body.style.overflow = 'hidden'
            return () => { document.body.style.overflow = '' }
        }
    }, [show])

    useEffect(() => {
        const onKey = (e) => {
            if (e.key === 'Escape') onClose()
        }
        if (show) document.addEventListener('keydown', onKey)
        return () => document.removeEventListener('keydown', onKey)
    }, [show, onClose])

    if (!show) return null

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center">
            <div className="fixed inset-0 bg-black/50" onClick={onClose}/>
            <div className="relative z-10 w-full max-w-lg mx-4 bg-[var(--color-vellum)] dark:bg-[var(--color-ink-night)] border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] shadow-lg rounded-sm p-6">
                {children}
            </div>
        </div>
    )
}

export function SaveIcon() {
    return (
        <svg className="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
        </svg>
    )
}

export function TrashIcon() {
    return (
        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75" aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/>
        </svg>
    )
}

// The bordered panel every profile section sits in.
export function Card({children}) {
    return (
        <div className="border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] bg-[var(--color-vellum)] dark:bg-[var(--color-ink-night)] rounded-sm p-4 sm:p-8">
            <div className="max-w-xl">
                {children}
            </div>
        </div>
    )
}
