export default function AlignmentRow({row}) {
    const statusClasses = {
        pending: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
        verifying: 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300',
        aligning: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
        completed: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
        failed: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
    };
    const similarity = row.similarity;
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

    const statusClass = statusClasses[row.status] ?? statusClasses.pending;
    // const url = viewUrl.replace('__ID__', row.id);

    return (
        <tr className="hover:bg-gray-50 dark:hover:bg-gray-700/40">
            <td className="px-4 py-4 text-sm font-medium text-gray-900 dark:text-gray-100">
                {row.en_entity_name ?? '—'}
            </td>
            <td className="px-4 py-4 text-sm font-medium text-gray-900 dark:text-gray-100">
                {row.ru_entity_name ?? '—'}
            </td>
            <td className={`px-4 py-4 text-sm font-semibold ${similarityClasses}`}>
                {similarity !== null ? Number(similarity).toFixed(4) : '—'}
            </td>
            <td className="px-4 py-4 text-sm text-gray-600 dark:text-gray-300">
                {row.linked_count > 0 ? `${row.linked_count} links` : '—'}
            </td>
            <td className="px-4 py-4 text-sm text-gray-600 dark:text-gray-300">
                {row.en_total_sentences}
            </td>
            <td className="px-4 py-4 text-sm text-gray-600 dark:text-gray-300">
                {row.ru_total_sentences}
            </td>
            <td className="px-4 py-4 text-sm">
                <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${statusClass}`}>
                    {row.status.charAt(0).toUpperCase() + row.status.slice(1)}
                </span>
            </td>
            <td className="px-4 py-4 text-sm text-gray-500 dark:text-gray-400">
                {row.created_at ?? '—'}
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
