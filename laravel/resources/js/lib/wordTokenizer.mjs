// Browser-side port of App\Classes\WordTokenizer. The two MUST stay in sync:
// the interactive word map sent by the server is keyed by the PHP tokenizer's
// lowercase l_word values, and the parity is enforced by
// tests/Unit/TokenizerParityTest.php.
const TOKEN_PATTERN = /[\p{L}\p{M}]+(?:['’\-][\p{L}\p{M}]+)*/gu;

const MIN_LENGTH = 2;

function trimEdgePunctuation(surface) {
    // Byte-safe in JS by nature; mirrors the multibyte-safe PHP trim.
    return surface.replace(/^['’-]+/, '').replace(/['’-]+$/, '');
}

function codePointLength(text) {
    let length = 0;
    for (const _ of text) {
        length += 1;
    }
    return length;
}

/**
 * Split text into render segments: word tokens carry the PHP tokenizer's
 * lowercase key (so the server word map can be looked up), everything else is
 * plain passthrough text. Tokens too short to be dictionary keys are rendered
 * as plain text, matching the PHP tokenizer's min length.
 */
export function segmentText(text) {
    const segments = [];
    if (typeof text !== 'string' || text === '') {
        return segments;
    }

    let cursor = 0;
    for (const match of text.matchAll(TOKEN_PATTERN)) {
        const surface = trimEdgePunctuation(match[0]);
        if (surface === '' || codePointLength(surface) < MIN_LENGTH) {
            continue;
        }
        if (match.index > cursor) {
            segments.push({text: text.slice(cursor, match.index), key: null});
        }
        segments.push({text: match[0], key: surface.toLowerCase()});
        cursor = match.index + match[0].length;
    }
    if (cursor < text.length) {
        segments.push({text: text.slice(cursor), key: null});
    }

    return segments;
}
