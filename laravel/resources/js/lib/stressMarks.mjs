// Stress-mark overlay support (ADR 0052 rendering): a sentence's `stressed`
// variant differs from the plain text only by inserted U+0301 combining
// acutes and е→ё substitutions on stressed vowels. stressOffsets() walks the
// two strings in lockstep and returns the offsets into the PLAIN text whose
// characters carry stress, so the renderer can draw a CSS accent over them
// while the DOM keeps the original characters — selection, copy/paste, and
// double-click dictionary extensions never see combining marks. Any
// unexpected divergence returns [] and that sentence renders plain.

const ACUTE = '\u0301';

function isYoSubstitution(plainChar, stressedChar) {
    return (plainChar === 'е' && stressedChar === 'ё')
        || (plainChar === 'Е' && stressedChar === 'Ё');
}

export function stressOffsets(plain, stressed) {
    if (typeof plain !== 'string' || typeof stressed !== 'string'
        || plain.length === 0 || stressed.length === 0) {
        return [];
    }

    const marks = [];
    let i = 0;
    let j = 0;
    while (i < plain.length && j < stressed.length) {
        const plainChar = plain[i];
        const stressedChar = stressed[j];
        if (plainChar === stressedChar) {
            // Marks present in both strings (pre-marked source content the
            // pipeline kept) stress the preceding character too.
            if (plainChar === ACUTE && i > 0) {
                marks.push(i - 1);
            }
            i += 1;
            j += 1;
            continue;
        }
        if (stressedChar === ACUTE) {
            // An inserted mark sits after the vowel it belongs to.
            if (i === 0) {
                return [];
            }
            marks.push(i - 1);
            j += 1;
            continue;
        }
        if (isYoSubstitution(plainChar, stressedChar)) {
            // Silero's е→ё on a stressed vowel: overlay the acute on plain е.
            marks.push(i);
            i += 1;
            j += 1;
            continue;
        }
        if (plainChar === ACUTE) {
            // A mark the pipeline stripped from the variant (it re-marked the
            // word elsewhere): skip it, the variant decides placement.
            i += 1;
            continue;
        }
        return [];
    }
    // Marks left at the very end of the variant still belong to the last
    // plain character.
    while (j < stressed.length && stressed[j] === ACUTE) {
        if (i !== plain.length || i === 0) {
            return [];
        }
        marks.push(i - 1);
        j += 1;
    }
    if (i !== plain.length || j !== stressed.length) {
        return [];
    }
    return Array.from(new Set(marks));
}
