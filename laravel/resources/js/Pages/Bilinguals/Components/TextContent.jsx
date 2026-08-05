import "../../../../css/bilingual-table.css";
import CheckboxInput from "../../../Components/Forms/CheckboxInput.jsx";
import Button from "../../../Components/Forms/Button.jsx";
import React from "react";


export default function TextContent(props) {
    const rowOffset = props.rowOffset ?? 0;

    return (
        <div className="flex-1 overflow-y-auto bg-[var(--color-vellum)] dark:bg-[var(--color-ink-night)] pb-5">
            <table className="bilingual-table table w-full">
                <colgroup>
                    <col className="bilingual-lang-col"/>
                    <col className="bilingual-control-col"/>
                    <col className="bilingual-control-col"/>
                    <col className="bilingual-control-col"/>
                    <col className="bilingual-lang-col"/>
                </colgroup>
                <thead
                    className="sticky top-0 z-10 bg-[var(--color-vellum-deep)] dark:bg-[var(--color-hairline-night)]/40 border-b border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)]">
                <tr className="font-serif text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70 text-sm">
                    <th className="px-4 py-3 text-left">
                        <div className="flex items-center gap-2">
                            <span className="italic">English</span>
                            <CheckboxInput id='all_en'/>
                        </div>
                    </th>
                    <th className="px-2 py-3 text-center font-sans text-[10px] tracking-[0.2em] uppercase text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)]">EN</th>
                    <th className="px-2 py-3 text-center font-sans text-[10px] tracking-[0.2em] uppercase opacity-60">#</th>
                    <th className="px-2 py-3 text-center font-sans text-[10px] tracking-[0.2em] uppercase text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)]">RU</th>
                    <th className="px-4 py-3 text-left">
                        <div className="flex items-center gap-2">
                            <span className="italic">Russian</span>
                            <CheckboxInput id='all_ru'/>
                        </div>
                    </th>
                </tr>
                </thead>
                <tbody className="divide-y divide-[var(--color-hairline)] dark:divide-[var(--color-hairline-night)]">
                {props.rows.map((row, i) => (
                    <tr key={rowOffset + i}
                        className="simulator-row group relative transition-colors duration-200 hover:bg-[var(--color-vellum-deep)]/50 dark:hover:bg-[var(--color-hairline-night)]/25 cursor-pointer">
                        <td className="px-4 py-3 align-top hide_en">
                            <div className="flex w-full flex-col gap-1">
                                <span
                                    className="eng content resizeable_element block w-full break-words text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]">{row[0]}</span>
                            </div>
                        </td>
                        <td className="px-2 py-3 bilingual-control-cell">
                            <div className="bilingual-control-inner bilingual-control-resizeable">
                                <CheckboxInput className="check_en cursor-pointer"/>
                            </div>
                        </td>
                        <td className="px-2 py-3 bilingual-control-cell">
                            <div
                                className="bilingual-control-inner bilingual-control-resizeable font-serif italic text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/50">
                                {rowOffset + i + 1}
                            </div>
                        </td>
                        <td className="px-2 py-3 bilingual-control-cell">
                            <div className="bilingual-control-inner bilingual-control-resizeable">
                                <CheckboxInput className="check_ru cursor-pointer"/>
                            </div>
                        </td>
                        <td className="px-4 py-3 align-top hide_ru">
                            <div className="flex w-full flex-col gap-2">
                                <span
                                    className="rus content resizeable_element block w-full break-words text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]">
                                    {row[1]}
                                </span>
                                <div
                                    className="flex gap-1 opacity-0 group-hover:opacity-100 transition">
                                    <Button onClick={() => props.focusOnWorkplace()} color='dark' size="xs" outline>Open</Button>
                                    <Button onClick={() => props.ask(row)} color='green' size="xs">Ask</Button>
                                </div>
                            </div>
                        </td>
                    </tr>))}
                </tbody>
            </table>
        </div>
    )
}