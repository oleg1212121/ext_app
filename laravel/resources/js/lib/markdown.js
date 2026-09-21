import {marked} from 'marked';
import DOMPurify from 'dompurify';

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

export function renderMarkdown(text) {
    if (!text) return '';
    const html = marked.parse(markDoubleEquals(normalizeArrowNotation(text)));
    const slots = [];
    const protectedHtml = html.replace(/<(pre|code|kbd|samp)\b[^>]*>[\s\S]*?<\/\1>/gi, (m) => {
        slots.push(m);
        return `\u0000${slots.length - 1}\u0000`;
    });
    const highlighted = highlightScores(highlightQuotes(highlightCorrections(unmarkDoubleEquals(protectedHtml))));
    return DOMPurify.sanitize(highlighted.replace(/\u0000(\d+)\u0000/g, (_m, i) => slots[Number(i)]));
}
