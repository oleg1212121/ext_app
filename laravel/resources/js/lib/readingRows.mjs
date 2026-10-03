// Reading rows (ADR 0060): the payload ships canonical a/b row objects —
// {key, a: {sentences}, b: {sentences}} for meaning-match rows, b: null for
// single-language rows, and sentence objects {id, text, stressed?, phrasal?}
// or {id, image, text} for illustrations. Every reading surface presents
// them by choosing which canonical side plays each display column; this
// module is the only place that holds that mapping.

export function otherSide(side) {
    return side === 'a' ? 'b' : 'a';
}

// The canonical side a surface displays first, given the server's default
// and whether the user's flip is active. Null default (single-language
// text) never flips.
export function displaySideFor(defaultSide, flipped) {
    if (defaultSide !== 'a' && defaultSide !== 'b') {
        return null;
    }
    return flipped ? otherSide(defaultSide) : defaultSide;
}

export function sideSentences(row, side) {
    return row?.[side]?.sentences ?? [];
}

// A side has content when any junction produced a sentence — text or
// illustration. Drives the reveal/toggle affordances.
export function sideHasContent(row, side) {
    return sideSentences(row, side).length > 0;
}

// Whether any row on the page carries an annotation at all — gates the
// stress-marks / phrasal-verbs toggles exactly like the old
// "some side !== null" scans.
export function rowsHaveAnnotation(rows, field) {
    return rows.some((row) =>
        sideSentences(row, 'a').some((sentence) => sentence[field] !== undefined)
        || sideSentences(row, 'b').some((sentence) => sentence[field] !== undefined));
}

// The side's text sentences' contents, "\n"-joined — the familiarity
// event helpers (rowWordIds) tokenize this string.
export function sideTexts(row, side) {
    return sideSentences(row, side)
        .filter((sentence) => sentence.image === undefined)
        .map((sentence) => sentence.text)
        .join('\n');
}
