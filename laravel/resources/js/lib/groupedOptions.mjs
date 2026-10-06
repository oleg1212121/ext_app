// The alignment picker's grouped options — works as groups, matches as
// options — and the two operations the picker needs on them: flattening to
// the selectable options and substring filtering for the search box.

// Case-insensitive substring match over the candidate texts.
function containsNeedle(needle, ...texts) {
    const lowered = needle.toLowerCase();
    return texts.some((text) => typeof text === 'string' && text.toLowerCase().includes(lowered));
}

// An option survives when its label matches the needle; a whole group
// survives (all options kept) when its header does.
export function filterGroups(groups, needle) {
    const trimmed = needle.trim();
    if (trimmed === '') {
        return groups;
    }

    return groups
        .map((group) => containsNeedle(trimmed, group.label)
            ? group
            : (() => {
                const options = group.options.filter((option) => containsNeedle(trimmed, option.text));
                return options.length > 0 ? {...group, options} : null;
            })())
        .filter((group) => group !== null);
}

// The selectable options in display order — the picker's membership check
// and keyboard navigation work over this flat view.
export function flattenGroups(groups) {
    return groups.flatMap((group) => group.options);
}
