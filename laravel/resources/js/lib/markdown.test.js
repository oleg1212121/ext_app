// The AI answer's word tokens (ai-answer-words 01): renderMarkdown grows an
// off-by-default option that wraps every tokenizer word token in a focusable
// span carrying the normalized lookup key — the same wordTokenizer.mjs keys
// the text columns use (case-fold, strip stress marks, ё→е). Only the AI
// stream engine passes the option; the word popup's explanation pane renders
// byte-identically to before. vitest is Node-only (ADR 0066): dompurify's
// factory returns early without a window (no sanitize method at all), so the
// pins below exercise the raw pass output; the browser sanitizes that same
// string (role/tabindex/class are in dompurify's default allowlist, data-*
// attrs ride ALLOW_DATA_ATTR's default true).
import {describe, expect, it} from 'vitest';

import {renderMarkdown} from './markdown';

// The span contract the AI panel's Ctrl+click delegation walks: a focusable
// button-role span (never a real <button> — double-click must keep native
// selection, ADR 0030) with the key in data-word-key and the dedicated
// ai-word-token class (never the columns' word-token classes).
const token = (key, text = key) =>
    `<span role="button" tabindex="0" data-word-key="${key}" class="ai-word-token">${text}</span>`;

describe('renderMarkdown default option', () => {
    const DOC = '## Fix the verb\n\nwrong => right at 95%\n\nKeep `bomb-hit` safe.';

    it('stays byte-identical with no option object', () => {
        expect(renderMarkdown(DOC)).toBe(
            '<h2>Fix the verb</h2>\n'
            + '<p><span class="ai-correction"><span class="ai-correction-old">wrong</span>'
            + '<span class="ai-correction-arrow">→</span>'
            + '<span class="ai-correction-new">right</span></span> at '
            + '<mark class="ai-score">95%</mark></p>\n'
            + '<p>Keep <code>bomb-hit</code> safe.</p>\n',
        );
    });

    it('is identical across the explicit option-off spellings', () => {
        expect(renderMarkdown(DOC, {})).toBe(renderMarkdown(DOC));
        expect(renderMarkdown(DOC, {wordTokens: false})).toBe(renderMarkdown(DOC));
        expect(renderMarkdown(DOC)).not.toContain('ai-word-token');
        expect(renderMarkdown(DOC)).not.toContain('data-word-key');
    });

    it('keeps the falsy-input short circuit', () => {
        expect(renderMarkdown('')).toBe('');
        expect(renderMarkdown(null)).toBe('');
    });
});

describe('renderMarkdown wordTokens: true', () => {
    it('wraps tokens in headings, lists, blockquotes and bold/italic', () => {
        expect(renderMarkdown('## Fix the verb form', {wordTokens: true})).toBe(
            `<h2>${token('fix', 'Fix')} ${token('the')} ${token('verb')} ${token('form')}</h2>\n`,
        );
        expect(renderMarkdown('- The bomb-hit exploded loudly', {wordTokens: true})).toBe(
            `<ul>\n<li>${token('the', 'The')} ${token('bomb-hit')} ${token('exploded')} ${token('loudly')}</li>\n</ul>\n`,
        );
        expect(renderMarkdown('> Quoted words here', {wordTokens: true})).toBe(
            `<blockquote>\n<p>${token('quoted', 'Quoted')} ${token('words')} ${token('here')}</p>\n</blockquote>\n`,
        );
        expect(renderMarkdown('**Bold move** and *quiet exit*', {wordTokens: true})).toBe(
            `<p><strong>${token('bold', 'Bold')} ${token('move')}</strong> ${token('and')}`
            + ` <em>${token('quiet')} ${token('exit')}</em></p>\n`,
        );
    });

    it('keys tokens through the shared tokenizer normalization', () => {
        // Keys: lowercase, combining stress marks stripped, ё→е — display
        // text untouched (same split as the columns' segmentText).
        expect(renderMarkdown('Ёлка поёт. Мо́ска stressed', {wordTokens: true})).toBe(
            `<p>${token('елка', 'Ёлка')} ${token('поет', 'поёт')}. ${token('моска', 'Мо́ска')} ${token('stressed')}</p>\n`,
        );
    });

    it('leaves sub-two-letter tokens plain', () => {
        expect(renderMarkdown('A short a and an I stay plain', {wordTokens: true})).toBe(
            `<p>A ${token('short')} a ${token('and')} ${token('an')} I ${token('stay')} ${token('plain')}</p>\n`,
        );
    });

    it('never wraps code blocks, inline code or kbd', () => {
        const out = renderMarkdown(
            'Keep `bomb-hit` intact.\n\n```js\nconst greeting = "hello world";\n```\n\nThen <kbd>Ctrl+C</kbd> works.',
            {wordTokens: true},
        );
        // Words outside code wrap; the protected code text passes through
        // byte-identically, entities included.
        expect(out).toBe(
            `<p>${token('keep', 'Keep')} <code>bomb-hit</code> ${token('intact')}.</p>\n`
            + '<pre><code class="language-js">const greeting = &quot;hello world&quot;;\n</code></pre>\n'
            + `<p>${token('then', 'Then')} <kbd>Ctrl+C</kbd> ${token('works')}.</p>\n`,
        );
        expect(out).not.toContain('data-word-key="bomb-hit"');
        expect(out).not.toContain('data-word-key="hello"');
        expect(out).not.toContain('data-word-key="world"');
        expect(out).not.toContain('data-word-key="ctrl"');
    });

    it('never wraps or breaks an HTML entity', () => {
        const out = renderMarkdown('She said &quot;hello&quot; to me', {wordTokens: true});
        // The entity letters are not a word (no key "quot"); the entity
        // delimiters stay intact around the wrapped real word between them.
        expect(out).toBe(
            `<p>${token('she', 'She')} ${token('said')} `
            + `<mark class="ai-quote">&quot;${token('hello')}&quot;</mark> `
            + `${token('to')} ${token('me')}</p>\n`,
        );
        expect(out.match(/&quot;/g)).toHaveLength(2);
        expect(out).not.toContain('data-word-key="quot"');
    });

    it('never wraps tag or attribute text', () => {
        const out = renderMarkdown('Use <abbr title="et cetera">etc</abbr> now', {wordTokens: true});
        expect(out).toBe(
            `<p>${token('use', 'Use')} <abbr title="et cetera">${token('etc')}</abbr> ${token('now')}</p>\n`,
        );
        expect(out).not.toContain('data-word-key="cetera"');
    });

    it('wraps inside correction spans', () => {
        expect(renderMarkdown('wrong => right now', {wordTokens: true})).toBe(
            '<p><span class="ai-correction"><span class="ai-correction-old">'
            + `${token('wrong')}</span><span class="ai-correction-arrow">→</span>`
            + `<span class="ai-correction-new">${token('right')}</span></span> ${token('now')}</p>\n`,
        );
    });

    it('wraps inside quoted corrections (guillemets)', () => {
        expect(renderMarkdown('Compare «старый» => «новый» now', {wordTokens: true})).toBe(
            `<p>${token('compare', 'Compare')} `
            + '<span class="ai-correction"><span class="ai-correction-old">'
            + `<mark class="ai-quote">«${token('старый')}»</mark></span>`
            + '<span class="ai-correction-arrow">→</span>'
            + '<span class="ai-correction-new">'
            + `<mark class="ai-quote">«${token('новый')}»</mark></span></span> ${token('now')}</p>\n`,
        );
    });

    it('keeps score marks intact with no token inside', () => {
        expect(renderMarkdown('Score is 95% now', {wordTokens: true})).toBe(
            `<p>${token('score', 'Score')} ${token('is')} <mark class="ai-score">95%</mark> ${token('now')}</p>\n`,
        );
    });
});
