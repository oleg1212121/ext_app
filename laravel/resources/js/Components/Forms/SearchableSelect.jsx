import React from 'react';
import {useI18n} from '../../i18n';
import {filterGroups, flattenGroups} from '../../lib/groupedOptions.mjs';

const triggerClass = [
    'flex h-8 w-full min-w-56 items-center justify-between gap-2 rounded-sm border px-2.5',
    'font-serif text-sm tracking-tight',
    'bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)]',
    'text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]',
    'border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]',
    'cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)]',
    'disabled:cursor-not-allowed disabled:opacity-40',
].join(' ');

const popupClass = [
    'absolute left-0 top-full z-20 mt-1 w-80 max-w-[calc(100vw-2rem)] rounded-sm border py-1 shadow-md',
    'border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]',
    'bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)]',
].join(' ');

const inputClass = [
    'mx-1 mb-1 block w-[calc(100%-0.5rem)] rounded-sm border px-2 py-1',
    'font-serif text-sm tracking-tight',
    'bg-[var(--wbench-paper-deep)] dark:bg-[var(--wbench-paper-deep-night)]',
    'text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]',
    'border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]',
    'placeholder:text-[var(--wbench-ink-soft)] dark:placeholder:text-[var(--wbench-ink-soft-night)]',
    'focus:outline-none focus-visible:ring-1 focus-visible:ring-[var(--wbench-accent)]',
].join(' ');

const headerClass = [
    'px-2.5 pb-0.5 pt-1.5 font-[var(--wbench-mono)] text-[10px] uppercase tracking-[0.14em]',
    'text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]',
].join(' ');

// The simulator's alignment picker: a searchable, work-grouped select.
// The trigger shows the current choice; the popup filters the groups with a
// case-insensitive substring (a matching group header keeps all its
// options) and supports keyboard navigation (arrows, Enter, Escape).
// A native select's value contract: onChange receives the option id as a
// string.
export default function SearchableSelect({value, onChange, groups}) {
    const {t} = useI18n();
    const [open, setOpen] = React.useState(false);
    const [needle, setNeedle] = React.useState('');
    const [highlighted, setHighlighted] = React.useState(0);
    const rootRef = React.useRef(null);
    const inputRef = React.useRef(null);
    const listId = React.useId();

    const visibleGroups = React.useMemo(() => filterGroups(groups, needle), [groups, needle]);
    const options = React.useMemo(() => flattenGroups(visibleGroups), [visibleGroups]);
    const hasOptions = groups.some((group) => group.options.length > 0);
    const selected = options.find((option) => String(option.id) === String(value)) ?? null;
    const highlightedId = options[highlighted] ? String(options[highlighted].id) : null;
    const optionDomId = (option) => `${listId}-option-${String(option.id)}`;

    const openPopup = () => {
        setNeedle('');
        const selectedIndex = options.findIndex((option) => String(option.id) === String(value));
        setHighlighted(Math.max(0, selectedIndex));
        setOpen(true);
    };

    const pick = (option) => {
        setOpen(false);
        onChange(String(option.id));
    };

    const onKeyDown = (event) => {
        if (!open) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                openPopup();
            }
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setHighlighted((prev) => (options.length === 0 ? prev : (prev + 1) % options.length));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setHighlighted((prev) => (options.length === 0 ? prev : (prev - 1 + options.length) % options.length));
        } else if (event.key === 'Enter') {
            event.preventDefault();
            if (options[highlighted]) {
                pick(options[highlighted]);
            }
        } else if (event.key === 'Escape') {
            setOpen(false);
        }
    };

    React.useEffect(() => {
        if (!open) {
            return;
        }

        const onPointerDown = (event) => {
            if (rootRef.current && !rootRef.current.contains(event.target)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', onPointerDown);
        return () => document.removeEventListener('mousedown', onPointerDown);
    }, [open]);

    React.useEffect(() => {
        if (open && highlightedId !== null) {
            document.getElementById(`${listId}-option-${highlightedId}`)?.scrollIntoView({block: 'nearest'});
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [highlighted, open]);

    return (
        <div ref={rootRef} className="relative w-72 max-w-full" onKeyDown={onKeyDown}>
            <button
                type="button"
                aria-haspopup="listbox"
                aria-expanded={open}
                aria-controls={open ? listId : undefined}
                disabled={!hasOptions}
                onClick={() => (open ? setOpen(false) : openPopup())}
                className={triggerClass}
            >
                <span className="truncate">{selected ? selected.text : t('bilinguals.no_text_selected')}</span>
                <svg viewBox="0 0 16 16" className="h-3 w-3 shrink-0 text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]" fill="none" stroke="currentColor" strokeWidth="1.5" aria-hidden="true">
                    <path d="M4 6l4 4 4-4" strokeLinecap="round" strokeLinejoin="round"/>
                </svg>
            </button>

            {open && (
                <div className={popupClass}>
                    <input
                        ref={inputRef}
                        type="text"
                        role="combobox"
                        aria-expanded="true"
                        aria-controls={listId}
                        aria-activedescendant={highlightedId !== null ? optionDomId({id: highlightedId}) : undefined}
                        aria-label={t('bilinguals.picker_choose')}
                        value={needle}
                        placeholder={t('bilinguals.picker_search')}
                        autoFocus
                        onChange={(event) => {
                            setNeedle(event.target.value);
                            setHighlighted(0);
                        }}
                        className={inputClass}
                    />
                    <div role="listbox" id={listId} className="max-h-72 overflow-y-auto py-0.5">
                        {visibleGroups.map((group) => (
                            <div key={group.id} role="group" aria-label={group.label}>
                                <div className={headerClass}>{group.label}</div>
                                {group.options.map((option) => {
                                    const isSelected = String(option.id) === String(value);
                                    const isHighlighted = highlightedId !== null && String(option.id) === highlightedId;

                                    return (
                                        <button
                                            key={option.id}
                                            id={optionDomId(option)}
                                            type="button"
                                            role="option"
                                            aria-selected={isSelected}
                                            onClick={() => pick(option)}
                                            onMouseEnter={() => setHighlighted(options.findIndex((candidate) => String(candidate.id) === String(option.id)))}
                                            className={[
                                                'flex w-full items-center justify-between gap-2 px-2.5 py-1 text-left',
                                                'font-serif text-sm tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]',
                                                'cursor-pointer focus:outline-none',
                                                isHighlighted ? 'bg-[var(--wbench-paper-deep)] dark:bg-[var(--wbench-paper-deep-night)]' : '',
                                            ].join(' ')}
                                        >
                                            <span className="truncate">{option.text}</span>
                                            {isSelected && (
                                                <svg viewBox="0 0 16 16" className="h-3.5 w-3.5 shrink-0 text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)]" fill="none" stroke="currentColor" strokeWidth="1.5" aria-hidden="true">
                                                    <path d="M3 8.5l3.5 3.5L13 5" strokeLinecap="round" strokeLinejoin="round"/>
                                                </svg>
                                            )}
                                        </button>
                                    );
                                })}
                            </div>
                        ))}
                        {options.length === 0 && (
                            <div className="px-2.5 py-1.5 text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                {t('bilinguals.picker_no_results')}
                            </div>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
