import "../../../../css/bilingual-table.css";
import CheckboxInput from "../../../Components/Forms/CheckboxInput.jsx";
import Button from "../../../Components/Forms/Button.jsx";
import React from "react";


export default function TextContent(props) {
    const rowOffset = props.rowOffset ?? 0;

    return (
        <>
            <div className="flex-1 overflow-y-auto bg-white dark:bg-gray-700 pb-5">
                <table className="bilingual-table table w-full">
                    <colgroup>
                        <col className="bilingual-lang-col"/>
                        <col className="bilingual-control-col"/>
                        <col className="bilingual-control-col"/>
                        <col className="bilingual-control-col"/>
                        <col className="bilingual-lang-col"/>
                    </colgroup>
                    <thead
                        className="sticky top-0 bg-orange-100 dark:bg-gray-800 border-b-2 border-gray-400 dark:border-gray-600 z-10 shadow-sm">
                    <tr className="text-sm font-semibold text-gray-800 dark:text-gray-200">
                        <th className="px-4 py-3 text-left">
                            <div className="flex items-center gap-2">
                                <span>English</span>
                                <CheckboxInput id='all_en'/>
                            </div>
                        </th>
                        <th className="px-2 py-3 text-center">EN</th>
                        <th className="px-2 py-3 text-center">#</th>
                        <th className="px-2 py-3 text-center">RU</th>
                        <th className="px-4 py-3 text-left">
                            <div className="flex items-center gap-2">
                                <span>Russian</span>
                                <CheckboxInput id='all_ru'/>
                            </div>
                        </th>
                    </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-200 dark:divide-gray-600">
                    {props.rows.map((row, i) => (
                        <tr key={rowOffset + i}
                            className="simulator-row hover:bg-orange-100 dark:hover:bg-gray-600 transition group cursor-pointer">
                            <td className="px-4 py-3 align-top hide_en">
                                <div className="flex w-full flex-col gap-1">
                                    <span
                                        className="eng content resizeable_element block w-full break-words text-gray-800 dark:text-gray-200">{row[0]}</span>
                                </div>
                            </td>
                            <td className="px-2 py-3 bilingual-control-cell">
                                <div className="bilingual-control-inner bilingual-control-resizeable">
                                    <CheckboxInput className="check_en cursor-pointer"/>
                                </div>
                            </td>
                            <td className="px-2 py-3 bilingual-control-cell">
                                <div
                                    className="bilingual-control-inner bilingual-control-resizeable text-gray-500 dark:text-gray-400">
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
                                        className="rus content resizeable_element block w-full break-words text-gray-800 dark:text-gray-200">
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
        </>
    )
}
