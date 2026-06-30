export default function AlignmentsIndex() {
    const {rows} = window.__ALIGNMENT_SHOW_DATA__;
    console.log(rows)
    const rowsContent = rows.map((item, index) => (
        <tr className="border-b border-gray-100 align-top hover:brightness-95 dark:border-gray-700 {{ $bgClass }}">
            <td className="px-3 py-3 text-center text-xs text-gray-400 dark:text-gray-500">
                {index + 1}
            </td>
            {/*<td className="px-2 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400">*/}

            {/*</td>*/}
            <td className="px-4 py-3 text-sm text-gray-800 dark:text-gray-200">
                {item.en.map((sentence) => (
                    <span>{sentence.content}</span>
                ))}
            </td>
            {/*<td className="px-2 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400">*/}
            {/*    shit 3*/}
            {/*</td>*/}
            <td className="px-4 py-3 text-sm text-gray-800 dark:text-gray-200">
                {item.ru.map((sentence) => (
                    <span>{sentence.content}</span>
                ))}
            </td>
            <td className="px-3 py-3 text-center text-xs">
                {item.similarity}
            </td>
        </tr>
    ))
    return (
        <div
            className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div className="overflow-x-auto">
                <table className="min-w-full table-fixed border-collapse">
                    <thead>
                    <tr className="border-b border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900/70">
                        <th className="w-12 px-3 py-3 text-center text-xs font-semibold text-gray-600 dark:text-gray-300">#</th>
                        {/*<th className="w-12 px-2 py-3 text-center text-xs font-semibold text-gray-600 dark:text-gray-300">EN</th>*/}
                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300">
                            English
                        </th>
                        {/*<th className="w-12 px-2 py-3 text-center text-xs font-semibold text-gray-600 dark:text-gray-300">RU</th>*/}
                        <th className="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300">
                            Russian
                        </th>
                        <th className="w-20 px-3 py-3 text-center text-xs font-semibold text-gray-600 dark:text-gray-300">
                            Score
                        </th>
                    </tr>
                    </thead>
                    <tbody>

                    {rowsContent}

                    </tbody>
                </table>
            </div>
        </div>
        // <div className="py-10">
        //     <div className="mx-auto flex max-w-7xl flex-col gap-6 px-4 sm:px-6 lg:px-8">
        //         <div
        //             className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        //             <div className="overflow-x-auto">
        //                 <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
        //                     <thead className="bg-gray-50 dark:bg-gray-900/70">
        //                     <tr>
        //                         {['EN Entity', 'RU Entity', 'Similarity', 'Progress', 'EN Sents', 'RU Sents', 'Status', 'Created', 'Open'].map((header) => (
        //                             <th
        //                                 key={header}
        //                                 className={
        //                                     header === 'Open'
        //                                         ? 'px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400'
        //                                         : 'px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400'
        //                                 }
        //                             >
        //                                 {header}
        //                             </th>
        //                         ))}
        //                     </tr>
        //                     </thead>
        //                     <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
        //                     {rows.length === 0 ? (
        //                         <tr>
        //                             <td colSpan={9}
        //                                 className="px-4 py-12 text-center text-sm text-gray-500 dark:text-gray-400">
        //                                 No links.
        //                             </td>
        //                         </tr>
        //                     ) : (
        //                         rows.map((row) => (
        //                             <AlignmentRow key={row.id} row={row}/>
        //                         ))
        //                     )}
        //                     </tbody>
        //                 </table>
        //             </div>
        //         </div>
        //
        //         {/*{paginationHtml && (*/}
        //         {/*    <div*/}
        //         {/*        className="rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm dark:border-gray-700 dark:bg-gray-800"*/}
        //         {/*        dangerouslySetInnerHTML={{__html: paginationHtml}}*/}
        //         {/*    />*/}
        //         {/*)}*/}
        //     </div>
        // </div>
    )
        ;
}
