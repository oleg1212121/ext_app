import {marked} from 'marked';
import DOMPurify from 'dompurify';
import {segmentText} from './wordTokenizer.mjs';

marked.setOptions({breaks: true, gfm: true});

function normalizeArrowNotation(text) {
    return text.replace(/\$\\rightarrow\$|\\rightarrow|→|\$\\to\$|\$\\Rightarrow\$/g, '=>');
}

function markDoubleEquals(text) {
    if (!text) return text;
    const slots = [];
    const protectedText = text.replace(/(```[\s\S]*?```|~~~[\s\S]*?~~~|`[^`\n]+`)/g, (m) => {
        slots.push(m);
        return `\u0000${slots.length - 1}\u0000`;
    });
    const converted = protectedText.replace(/==([^\n=]+)==/g, '\u0001$1\u0001');
    return converted.replace(/\u0000(\d+)\u0000/g, (_m, i) => slots[Number(i)]);
}

function unmarkDoubleEquals(html) {
    return html.replace(/\u0001([\s\S]*?)\u0001/g, '<mark>$1</mark>');
}

const QUOTED_HTML = '&quot;(?:[^<&]|&(?!quot;))+&quot;|«(?:[^<&»]|&(?!quot;))+»|“(?:[^<&”]|&(?!quot;))+”|‘(?:[^<&’]|&(?!quot;))+’';
const CORRECTION_TOKEN = `(?:<(?:del|s|ins|u|b|strong|em|i|mark)\\b[^>]*>[^<]*</(?:del|s|ins|u|b|strong|em|i|mark)>|${QUOTED_HTML}|[^\\s<]+)`;

function highlightCorrections(html) {
    const pattern = new RegExp(`(${CORRECTION_TOKEN})\\s*(?:=&gt;|-&gt;)\\s*(${CORRECTION_TOKEN})|(<[^>]+>)`, 'g');
    return html.replace(pattern, (m, old, fresh, tag) => {
        if (tag) return tag;
        return `<span class="ai-correction"><span class="ai-correction-old">${old}</span><span class="ai-correction-arrow">→</span><span class="ai-correction-new">${fresh}</span></span>`;
    });
}

function highlightQuotes(html) {
    const pattern = new RegExp(`(<[^>]+>)|${QUOTED_HTML}`, 'g');
    return html.replace(pattern, (m, tag) => {
        if (tag) return tag;
        return `<mark class="ai-quote">${m}</mark>`;
    });
}

function highlightScores(html) {
    return html.replace(/(<[^>]+>)|\d{1,3}(?:[.,]\d+)?\s?%/g, (m, tag, offset, htmlString) => {
        if (tag) return tag;
        const previous = htmlString[offset - 1];
        if (previous && /[\d.,]/.test(previous)) return m;
        return `<mark class="ai-score">${m}</mark>`;
    });
}

// Word-token wrapping for the AI answer (ai-answer-words): every tokenizer
// word token becomes a focusable span carrying the normalized lookup key —
// the same wordTokenizer.mjs keys the text columns look up — so the panel's
// Ctrl+click delegation finds words without re-tokenizing (ADR 0030's
// role="button" span contract; never a real <button>, which would kill the
// double-click's native selection). A dedicated class keeps the columns'
// tint/hover-underline styles in app.css from leaking into the panel.
const WORD_TOKEN_CLASS = 'ai-word-token';

// Tags and character entities are inert: attribute text is never wrapped,
// and an entity's letters (&quot;) are never mistaken for the word "quot".
// A tag admits `>` inside quoted attribute values (<a title="a > b">) — a
// naive <[^>]+> would end the match at the first `>` and wrap the
// attribute tail into span markup inside the attribute. Entities cover the
// well-formed shapes AND semicolon-less legacy named references (&copy),
// which survive only inside raw HTML pass-through (marked escapes a text
// `&` to &amp;): wrapping their letters corrupts the entity. The legacy
// form's lookahead spans the whole maximal name so a match can never
// backtrack into a shorter prefix of a ;-terminated entity, and the
// well-formed shapes are tried first — &amp;copy keeps `copy` wrappable.
// Numeric references keep requiring the `;` (HTML5 has no legacy numeric
// form). Cost of the pragmatic shape: a bare `&` + letters inside raw HTML
// that is not an entity stays unwrapped — inert is the safe direction.
const INERT_TAG_PATTERN = /<(?:[^>"']|"[^"]*"|'[^']*')*>/;
const INERT_ENTITY_PATTERN = /&(?:#[0-9]+|#[xX][0-9a-fA-F]+|[a-zA-Z][a-zA-Z0-9]*);|&[a-zA-Z][a-zA-Z0-9]*(?![a-zA-Z0-9]*;)/;
const INERT_HTML_PATTERN = new RegExp(`${INERT_TAG_PATTERN.source}|${INERT_ENTITY_PATTERN.source}`, 'g');

function wrapTextRun(text) {
    let wrapped = '';
    for (const segment of segmentText(text)) {
        if (segment.key === null) {
            wrapped += segment.text;
            continue;
        }
        // Keys are letters/apostrophes/hyphens by tokenizer construction, so
        // the attribute needs no escaping, and tag-shaped text is inert, so
        // no < can reach the span body.
        wrapped += `<span role="button" tabindex="0" data-word-key="${segment.key}" class="${WORD_TOKEN_CLASS}">${segment.text}</span>`;
    }
    return wrapped;
}

function wrapWordTokens(html) {
    let wrapped = '';
    let cursor = 0;
    for (const match of html.matchAll(INERT_HTML_PATTERN)) {
        if (match.index > cursor) {
            wrapped += wrapTextRun(html.slice(cursor, match.index));
        }
        wrapped += match[0];
        cursor = match.index + match[0].length;
    }
    if (cursor < html.length) {
        wrapped += wrapTextRun(html.slice(cursor));
    }
    return wrapped;
}

export function renderMarkdown(text, {wordTokens = false} = {}) {
    if (!text) return '';
    const html = marked.parse(markDoubleEquals(normalizeArrowNotation(text)));
    const slots = [];
    const protectedHtml = html.replace(/<(pre|code|kbd|samp)\b[^>]*>[\s\S]*?<\/\1>/gi, (m) => {
        slots.push(m);
        return `\u0000${slots.length - 1}\u0000`;
    });
    const highlighted = highlightScores(highlightQuotes(highlightCorrections(unmarkDoubleEquals(protectedHtml))));
    // The wrap runs after the correction/quote/score passes (their one-word
    // sides must still see unwrapped text) and before the slot restore, so
    // code slots are never wrapped: the \u0000N\u0000 placeholders hold only
    // digits, which the letter tokenizer can never match.
    const tokenized = wordTokens ? wrapWordTokens(highlighted) : highlighted;
    const restored = tokenized.replace(/\u0000(\d+)\u0000/g, (_m, i) => slots[Number(i)]);
    // Node has no window: dompurify's factory then returns early and its
    // default export carries no sanitize at all — return the raw pass output
    // there (pure-Node vitest, ADR 0066). The browser always sanitizes.
    return DOMPurify.isSupported ? DOMPurify.sanitize(restored) : restored;
}
