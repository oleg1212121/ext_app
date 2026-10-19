import {readFileSync} from 'node:fs';
import {describe, expect, it} from 'vitest';

// popup-ai-enrich ticket 03: EVERY tokenizer word token is interactive —
// components sit outside vitest's behavioral reach (ADR 0066), so the
// decisions are pinned the way WordPopup.toolbar.test.js pins the toolbar:
// source assertions. What they lock: a key segment without a word-map entry
// still renders as a word-token (hover-underline + pointer, keyboard
// contract, NO familiarity tint) whose Ctrl+click / Ctrl+Enter opens the
// popup in MISSING mode — the RAW surface as the title (the folded key would
// show ё-folded, mark-stripped text) and zero network: no GET /words/, no
// POST /word-events, nothing fetched or "being fetched". The old unclickable
// phrasal-mark span branch merges into the same interactive path. Each
// column's language travels down to the popup as a prop from both
// surfaces (reader display columns, simulator columns) — ticket 05's
// adopt-on-ask asks with it. The honest not-in-dictionary copy lives in
// the word.* ui-strings seeder.

const wordTextSource = readFileSync(new URL('./WordText.jsx', import.meta.url), 'utf8');
const popupSource = readFileSync(new URL('./WordPopup.jsx', import.meta.url), 'utf8');
const readerRowSource = readFileSync(new URL('../Pages/Reader/ReaderRow.jsx', import.meta.url), 'utf8');
const textContentSource = readFileSync(new URL('../Pages/Bilinguals/Components/TextContent.jsx', import.meta.url), 'utf8');
const seederSource = readFileSync(new URL('../../../database/seeders/ui-strings/words.php', import.meta.url), 'utf8');

// The click handler: from its const to the next hook.
const openPopupSource = wordTextSource.slice(
    wordTextSource.indexOf('const openPopup'),
    wordTextSource.indexOf('const handleProgress'),
);

// The render loop over one sentence's segments.
const renderLoopSource = wordTextSource.slice(
    wordTextSource.indexOf('const renderTextSentence'),
    wordTextSource.indexOf('const interactiveToken'),
);

// The interactive token span.
const interactiveTokenSource = wordTextSource.slice(
    wordTextSource.indexOf('const interactiveToken'),
    wordTextSource.indexOf('// segmentLists/phrasalMarks/stressMaps are indexed'),
);

// The popup portal — where the language prop must ride along.
const portalSource = wordTextSource.slice(wordTextSource.indexOf('{popup && createPortal('));

// The detail fetch effect: from its useEffect opener to the fetch itself.
const fetchAt = popupSource.indexOf('fetch(`/words/${wordId}?surface=');
const fetchEffectSource = popupSource.slice(
    popupSource.lastIndexOf('useEffect(() => {', fetchAt),
    fetchAt,
);

// The missing card body (defined before PopupContent so the toolbar pins'
// slices stay PopupContent's own).
const missingContentSource = popupSource.slice(
    popupSource.indexOf('export function MissingPopupContent'),
    popupSource.indexOf('export function PopupContent'),
);

// The default portal's render branch.
const popupRenderSource = popupSource.slice(popupSource.indexOf('return createPortal('));

// The missing copy's three interface texts, pulled out of the seeder partial.
const missingStringMatch = seederSource.match(/'word\.not_in_dictionary' => \[([\s\S]*?)\],/);
const seederText = (lang) => {
    const match = missingStringMatch?.[1].match(new RegExp(`'${lang}' => '((?:[^'\\\\]|\\\\.)*)'`));
    return match?.[1] ?? null;
};

describe('WordText: every key segment is interactive', () => {
    it('renders unmapped key segments through the interactive token path', () => {
        // The old gate — mapped tokens only, everything else a plain
        // Fragment — is gone: the key-segment branch always renders a token,
        // passing whatever the word map holds (entry included, undefined for
        // an unmapped key).
        expect(renderLoopSource).toContain('return renderToken(segment, segmentIndex, wordMap[segment.key], markClass, markLabel, pieces)');
        expect(renderLoopSource).not.toContain('const entry =');
        expect(renderLoopSource).not.toContain('entry?.w');
    });

    it('merges the unclickable phrasal-mark span branch into that path', () => {
        // The standalone `word-token phrasal-hit` span (an unmapped phrasal
        // hit was a dead tooltip) no longer exists — the mark rides markClass
        // onto the interactive span, so a Ctrl+click opens the missing card.
        expect(wordTextSource).not.toContain("className={'word-token phrasal-hit'}");
        expect(interactiveTokenSource).toContain('markClass');
    });

    it('keeps mapped tokens tinted — unmapped ones plain, no familiarity tier', () => {
        // tierClass only runs for a mapped entry; an unmapped token is a bare
        // word-token: hover-underline + pointer from app.css, zero tint.
        expect(interactiveTokenSource).toContain("className={(entry?.w ? tierClass(entry.s, highlight) : 'word-token') + markClass}");
    });
});

describe('WordText: the missing-mode payload', () => {
    it('opens unmapped tokens with no word id and the RAW surface as title', () => {
        // segment.key is folded (ё→е, marks stripped); the card must show
        // what the reader actually clicked — segment.text.
        const missingBranch = openPopupSource.slice(
            openPopupSource.indexOf('if (!entry?.w) {'),
            openPopupSource.indexOf('recordWordEvents'),
        );
        expect(missingBranch).toContain('wordId: null');
        expect(missingBranch).toContain('surface: segment.text');
        expect(missingBranch).toContain('familiarity: null');
        // The mapped path keeps today's payload untouched.
        expect(openPopupSource).toContain('surface: segment.key');
    });

    it('fires nothing for a missing token — the event POST is unreachable there', () => {
        // The missing branch RETURNS before recordWordEvents: no /word-events
        // lookup can fire for a token without a word id.
        const missingBranch = openPopupSource.slice(
            openPopupSource.indexOf('if (!entry?.w) {'),
            openPopupSource.indexOf('recordWordEvents'),
        );
        expect(missingBranch).toContain('return;');
        expect(openPopupSource).toContain('recordWordEvents');
    });
});

describe('WordText → WordPopup: the column language rides along', () => {
    it('forwards a new language prop from the segment renderer to the popup', () => {
        // Ticket 05's adopt-on-ask resolves the missing word's ask from it.
        expect(wordTextSource).toMatch(/function WordText\(\{[\s\S]*?language = null/);
        expect(portalSource).toContain('language={language}');
    });

    it('the reader passes each display column’s descriptor language', () => {
        expect(readerRowSource).toContain('language={first.language}');
        expect(readerRowSource).toContain('language={second.language}');
    });

    it('the simulator passes each column’s descriptor language', () => {
        expect(textContentSource).toContain('language={props.first.language}');
        expect(textContentSource).toContain('language={props.second.language}');
    });
});

describe('WordPopup: missing mode', () => {
    it('skips the detail fetch entirely when there is no word id', () => {
        // The guard sits between the effect's opening and the fetch, so no
        // GET /words/ can fire for a missing token.
        expect(fetchEffectSource).toMatch(/if \(wordId == null\) \{\s*return;\s*\}/);
        // The mapped path's fetch itself is untouched.
        expect(popupSource).toContain('fetch(`/words/${wordId}?surface=${encodeURIComponent(surface)}`');
    });

    it('renders the missing card until the adoption ask swaps a payload in', () => {
        const ternaryAt = popupRenderSource.indexOf(') : (');
        expect(popupRenderSource).toMatch(/wordId == null && data == null \? \(/);
        expect(ternaryAt).toBeGreaterThan(-1);
        // The missing branch holds only the missing card — the ticket 05
        // ask handler goes live on it only when a column language exists…
        const missingBranch = popupRenderSource.slice(popupRenderSource.indexOf('wordId == null && data == null ? ('), ternaryAt);
        expect(missingBranch).toContain('surface={surface}');
        expect(missingBranch).toContain('<MissingPopupContent');
        expect(missingBranch).toContain('askAiMissing');
        expect(missingBranch).not.toContain('<PopupContent');
        // …and PopupContent (toolbar, header, dictionary, Anki, explain)
        // renders the adopted payload — the transition to the full
        // dictionary view is the same setData swap the known-word ask uses.
        expect(popupRenderSource.slice(ternaryAt)).toContain('<PopupContent');
    });

    it('accepts the language prop — ticket 05’s adopt-on-ask consumes it', () => {
        expect(popupSource).toMatch(/export default function WordPopup\(\{[\s\S]*?language = null/);
    });
});

describe('MissingPopupContent', () => {
    it('shows the surface as title and the honest not-in-dictionary note', () => {
        expect(missingContentSource).toContain('word-popup-title');
        expect(missingContentSource).toContain('{surface}');
        expect(missingContentSource).toContain("t('word.not_in_dictionary')");
        expect(missingContentSource).toContain('word-popup-missing');
    });

    it('carries only the Ask-AI icon — live, but no progress, Anki, explain, robot', () => {
        expect(missingContentSource).toContain('word-popup-ask-ai');
        expect(missingContentSource).toContain("t('word.ask_ai')");
        // The ticket 05 icon disables (never hides) while inapplicable: no
        // handler (the profile preview, or a column without a language
        // descriptor) or an ask in flight.
        expect(missingContentSource).toMatch(/disabled=\{onAskAi == null \|\| aiBusy\}/);
        expect(missingContentSource).toContain('onClick={onAskAi}');
        for (const absent of [
            'word-popup-progress-known',
            'word-popup-progress-reset',
            'word-popup-anki-send',
            'word-popup-explain-toggle',
            'word-popup-models-button',
            'word-popup-familiarity',
            'word-popup-frequency',
            'word-popup-form-of',
        ]) {
            expect(missingContentSource).not.toContain(absent);
        }
        // The card itself fetches nothing — the ask POST lives in the outer
        // WordPopup.
        expect(missingContentSource).not.toContain('fetch(');
    });

    it('shows a failed ask beneath the toolbar — Retry, and no-model/no-key get the explain pane’s guidance', () => {
        // The shared AskAiError render (PopupContent's toolbar mounts it
        // too): the missing card passes the message, the status and the ask
        // handler.
        expect(missingContentSource).toContain('<AskAiError');
        expect(missingContentSource).toContain('message={aiError}');
        expect(missingContentSource).toContain('status={aiErrorStatus}');
        expect(missingContentSource).toContain('onRetry={onAskAi}');
        // The shared render carries the stable hook, the Retry control
        // (the four-state contract's error state) and the go-to-settings
        // link for the no-model (400) / no-key (403) answers — the
        // ExplainGuidance treatment.
        const errorComponent = popupSource.slice(
            popupSource.indexOf('function AskAiError'),
            popupSource.indexOf('export function MissingPopupContent'),
        );
        expect(errorComponent).toContain('word-popup-ask-ai-error');
        expect(errorComponent).toContain('word-popup-ask-ai-retry');
        expect(errorComponent).toMatch(/status === 400 \|\| status === 403/);
        expect(errorComponent).toContain('/profile?tab=ai');
        expect(errorComponent).toContain("t('word.explain_go_to_settings')");
    });
});

describe('word.ask_ai_retry ui-string', () => {
    // The ask error state's Retry control (both popup bodies mount the
    // shared AskAiError render) — the explain pane's error state has one,
    // and the four-state contract words the error treatment strictly.
    const retryStringMatch = seederSource.match(/'word\.ask_ai_retry' => \[([\s\S]*?)\],/);

    it('exists for all three interface languages', () => {
        expect(retryStringMatch).toBeTruthy();
        for (const lang of ['en', 'ru', 'be']) {
            const match = retryStringMatch?.[1].match(new RegExp(`'${lang}' => '((?:[^'\\\\]|\\\\.)*)'`));
            expect(match?.[1] ?? null, lang).toBeTruthy();
        }
    });
});

describe('word.not_in_dictionary ui-string', () => {
    it('exists for all three interface languages', () => {
        expect(missingStringMatch).toBeTruthy();
        for (const lang of ['en', 'ru', 'be']) {
            expect(seederText(lang), lang).toBeTruthy();
        }
    });

    it('is honest — it names the dictionary and never claims anything is loading', () => {
        const en = seederText('en');
        expect(en).toMatch(/dictionary/i);
        expect(en).toMatch(/yet/i);
        for (const lang of ['en', 'ru', 'be']) {
            expect(seederText(lang), lang).not.toMatch(/load|fetch|загруз|загру|иска/i);
        }
    });
});
