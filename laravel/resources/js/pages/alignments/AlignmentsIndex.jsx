export default function AlignmentsIndex() {
    const {entityMatches, paginationHtml, viewUrl} = window.__ALIGNMENT_DATA__;

    return (
        <div className="py-10">
            <div className="mx-auto flex max-w-7xl flex-col gap-6 px-4 sm:px-6 lg:px-8">
                <div className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead className="bg-gray-50 dark:bg-gray-900/70">
                                <tr>
                                    {['EN Entity', 'RU Entity', 'Similarity', 'Progress', 'EN Sents', 'RU Sents', 'Status', 'Created', 'Open'].map((header) => (
                                        <th
                                            key={header}
                                            className={
                                                header === 'Open'
                                                    ? 'px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400'
                                                    : 'px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400'
                                            }
                                        >
                                            {header}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                                {entityMatches.length === 0 ? (
                                    <tr>
                                        <td colSpan={9} className="px-4 py-12 text-center text-sm text-gray-500 dark:text-gray-400">
                                            No alignments have been created yet.
                                        </td>
                                    </tr>
                                ) : (
                                    entityMatches.map((run) => (
                                        <AlignmentRow key={run.id} run={run} viewUrl={viewUrl} />
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                {paginationHtml && (
                    <div
                        className="rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm dark:border-gray-700 dark:bg-gray-800"
                        dangerouslySetInnerHTML={{__html: paginationHtml}}
                    />
                )}
            </div>
        </div>
    );
}

function AlignmentRow({run, viewUrl}) {
    const statusClasses = {
        pending: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
        verifying: 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300',
        aligning: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
        completed: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
        failed: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
    };

    const similarity = run.entity_similarity;
    let similarityClasses = 'text-gray-400 dark:text-gray-500';
    if (similarity !== null) {
        if (similarity >= 0.85) {
            similarityClasses = 'text-emerald-600 dark:text-emerald-400';
        } else if (similarity >= 0.70) {
            similarityClasses = 'text-amber-600 dark:text-amber-400';
        } else {
            similarityClasses = 'text-red-600 dark:text-red-400';
        }
    }

    const statusClass = statusClasses[run.status] ?? statusClasses.pending;
    const url = viewUrl.replace('__ID__', run.id);

    return (
        <tr className="hover:bg-gray-50 dark:hover:bg-gray-700/40">
            <td className="px-4 py-4 text-sm font-medium text-gray-900 dark:text-gray-100">
                {run.en_entity_name ?? '—'}
            </td>
            <td className="px-4 py-4 text-sm font-medium text-gray-900 dark:text-gray-100">
                {run.ru_entity_name ?? '—'}
            </td>
            <td className={`px-4 py-4 text-sm font-semibold ${similarityClasses}`}>
                {similarity !== null ? Number(similarity).toFixed(4) : '—'}
            </td>
            <td className="px-4 py-4 text-sm text-gray-600 dark:text-gray-300">
                {run.linked_count > 0 ? `${run.linked_count} links` : '—'}
            </td>
            <td className="px-4 py-4 text-sm text-gray-600 dark:text-gray-300">
                {run.en_total_sentences}
            </td>
            <td className="px-4 py-4 text-sm text-gray-600 dark:text-gray-300">
                {run.ru_total_sentences}
            </td>
            <td className="px-4 py-4 text-sm">
                <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${statusClass}`}>
                    {run.status.charAt(0).toUpperCase() + run.status.slice(1)}
                </span>
            </td>
            <td className="px-4 py-4 text-sm text-gray-500 dark:text-gray-400">
                {run.created_at ?? '—'}
            </td>
            <td className="px-4 py-4 text-right text-sm">
                <a
                    href={url}
                    className="inline-flex items-center rounded-lg border border-orange-200 bg-orange-50 px-3 py-2 font-medium text-orange-700 transition hover:bg-orange-100 dark:border-orange-900/60 dark:bg-orange-900/30 dark:text-orange-300 dark:hover:bg-orange-900/50"
                >
                    View
                </a>
            </td>
        </tr>
    );
}