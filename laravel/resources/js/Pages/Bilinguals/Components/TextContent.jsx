import "../../../../css/bilingual-table.css";
import CheckboxInput from "../../../Components/Forms/CheckboxInput.jsx";
import Button from "../../../Components/Forms/Button.jsx";
import React from "react";


export default function TextContent(props) {
    const rowOffset = props.rowOffset ?? 0;
    const hasRows = (props.rows?.length ?? 0) > 0;

    if (!hasRows) {
        return (
            <div className="flex-1 overflow-y-auto bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] flex items-center justify-center px-6 py-16">
                <div className="max-w-md text-center">
                    {props.loadError ? (
                        <>
                            <p className="font-[var(--wbench-mono)] text-[10px] tracking-[0.24em] uppercase text-[var(--wbench-danger)] dark:text-[var(--wbench-danger-night)] mb-3">Couldn't load</p>
                            <p className="font-[var(--wbench-serif)] text-lg text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] leading-snug">{props.loadError}</p>
                            <p className="mt-3 font-[var(--wbench-sans)] text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">Pick another text and load it, or try again in a moment.</p>
                        </>
                    ) : props.hasText ? (
                        <>
                            <p className="font-[var(--wbench-mono)] text-[10px] tracking-[0.24em] uppercase text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] mb-3">No text loaded</p>
                            <p className="font-[var(--wbench-serif)] text-lg text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] leading-snug">Press <span className="font-[var(--wbench-sans)] font-medium">Load</span> to bring in the selected text.</p>
                        </>
                    ) : (
                        <>
                            <p className="font-[var(--wbench-mono)] text-[10px] tracking-[0.24em] uppercase text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] mb-3">No text selected</p>
                            <p className="font-[var(--wbench-serif)] text-lg text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] leading-snug">Pick a text and load it to start.</p>
                        </>
                    )}
                </div>
            </div>
        );
    }

    return (
        <div className="flex-1 overflow-y-auto bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] pb-5">
            <table className="bilingual-table table w-full">
                <colgroup>
                    <col className="bilingual-lang-col"/>
                    <col className="bilingual-control-col"/>
                    <col className="bilingual-control-col"/>
                    <col className="bilingual-control-col"/>
                    <col className="bilingual-lang-col"/>
                </colgroup>
                <thead
                    className="sticky top-0 z-10 bg-[var(--wbench-paper-deep)] dark:bg-[var(--wbench-paper-deep-night)] border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]">
                <tr className="font-[var(--wbench-mono)] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] text-[10px] tracking-[0.2em] uppercase">
                    <th className="px-4 py-2 text-left">
                        <div className="flex items-center gap-2">
                            <span className="font-[var(--wbench-serif)] italic normal-case tracking-normal text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">English</span>
                            <CheckboxInput id='all_en'/>
                        </div>
                    </th>
                    <th className="px-2 py-2 text-center text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)]">EN</th>
                    <th className="px-2 py-2 text-center opacity-50">#</th>
                    <th className="px-2 py-2 text-center text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)]">RU</th>
                    <th className="px-4 py-2 text-left">
                        <div className="flex items-center gap-2">
                            <span className="font-[var(--wbench-serif)] italic normal-case tracking-normal text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">Russian</span>
                            <CheckboxInput id='all_ru'/>
                        </div>
                    </th>
                </tr>
                </thead>
                <tbody className="divide-y divide-[var(--wbench-rule)] dark:divide-[var(--wbench-rule-night)]">
                {props.rows.map((row, i) => {
                    const n = rowOffset + i + 1;
                    const nStr = n < 10 ? `0${n}` : String(n);
                    return (
                    <tr key={rowOffset + i}
                        className="simulator-row group relative transition-colors duration-150 hover:bg-[var(--wbench-paper-deep)]/60 dark:hover:bg-[var(--wbench-paper-deep-night)]/50 cursor-pointer">
                        <td className="px-4 py-2 align-top hide_en relative">
                            <span className="ribbon-mark absolute left-0 top-0 bottom-0" aria-hidden="true"/>
                            <span className="eng content resizeable_element block w-full break-words text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] font-[var(--wbench-serif)]">{row[0]}</span>
                        </td>
                        <td className="px-2 py-2 bilingual-control-cell">
                            <div className="bilingual-control-inner bilingual-control-resizeable">
                                <CheckboxInput className="check_en cursor-pointer"/>
                            </div>
                        </td>
                        <td className="px-2 py-2 bilingual-control-cell">
                            <div className="bilingual-control-inner bilingual-control-resizeable font-[var(--wbench-mono)] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] tabular-nums">
                                {nStr}
                            </div>
                        </td>
                        <td className="px-2 py-2 bilingual-control-cell">
                            <div className="bilingual-control-inner bilingual-control-resizeable">
                                <CheckboxInput className="check_ru cursor-pointer"/>
                            </div>
                        </td>
                        <td className="px-4 py-2 align-top hide_ru">
                            <div className="flex w-full flex-col gap-1.5">
                                <span className="rus content resizeable_element block w-full break-words text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] font-[var(--wbench-serif)]">
                                    {row[1]}
                                </span>
                                <div className="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity duration-150">
                                    <Button onClick={() => props.focusOnWorkplace()} color='dark' size="xs" outline>Open</Button>
                                    {props.canUseAi && (
                                        <Button onClick={() => props.ask(row)} color='green' size="xs">Ask</Button>
                                    )}
                                </div>
                            </div>
                        </td>
                    </tr>);
                })}
                </tbody>
            </table>
        </div>
    )
}
