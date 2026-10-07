/**
 * Merge a mutation response's row payloads into the editor's displayed rows.
 *
 * Rows already on the page are replaced in place, and a newly created row is
 * spliced in after its anchor row (the anchor row id `runMutation` remembered
 * at dispatch time; a missing anchor falls back to appending at the end).
 *
 * Payload rows for rows that are NOT on the displayed page — a row approved
 * straight from the needs-review list, for example — belong to another page:
 * appending them would fake an extra "next page" preview row at the end of
 * the list, so they are skipped. The server already holds their truth, and
 * loadRows refetches the page on demand.
 *
 * Rows with a non-numeric id are the editor's optimistic temp rows; they are
 * dropped here, since the response replaces the mutation's effect.
 *
 * @param {list} baseRows the currently displayed row payloads
 * @param {list} incomingRows the mutation response's row payloads (non-empty)
 * @param {number|string|null} anchorRowId insert new rows after this row id
 * @returns {list} a new rows array (inputs untouched)
 */
export function mergeMutationRows(baseRows, incomingRows, anchorRowId = null) {
    let rows = baseRows
        .filter((row) => typeof row.id === 'number')
        .map((row) => incomingRows.find((next) => next.id === row.id) ?? row);

    if (anchorRowId !== null) {
        const anchorIndex = rows.findIndex((existing) => existing.id === anchorRowId);
        let insertIndex = anchorIndex < 0 ? rows.length : anchorIndex + 1;

        incomingRows.forEach((row) => {
            if (!rows.some((existing) => existing.id === row.id)) {
                rows.splice(insertIndex, 0, row);
                insertIndex++;
            }
        });
    }

    return rows;
}
