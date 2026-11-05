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
// POST /words/opens, nothing fetched or "being fetched". The old unclickable
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
const fetchAt = popupSource.indexOf('fetch(`/words/${fetchId}?surface=');
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

// The missing card's toolbar block: from the strip's own class hook to the
// word header that follows it — everything between is the first-row JSX
// (word-popup-overhaul ticket 05's full toolbar).
const missingToolbarStart = missingContentSource.indexOf('className="word-popup-actions');
const missingToolbar = missingContentSource.slice(missingToolbarStart, missingContentSource.indexOf('className="word-popup-header'));

// The full JSX of one missing-card toolbar button, located by its stable class hook.
const missingButtonIn = (hook) => {
    const at = missingToolbar.indexOf(hook);
    expect(at, `${hook} renders in the missing card's toolbar`).toBeGreaterThan(-1);
    const start = missingToolbar.lastIndexOf('<button', at);
    return missingToolbar.slice(start, missingToolbar.indexOf('</button>', at));
};

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
            openPopupSource.indexOf('recordWordOpens'),
        );
        expect(missingBranch).toContain('wordId: null');
        expect(missingBranch).toContain('surface: segment.text');
        expect(missingBranch).toContain('familiarity: null');
        // The mapped path keeps today's payload untouched.
        expect(openPopupSource).toContain('surface: segment.key');
    });

    it('fires nothing for a missing token — the open penalty is unreachable there', () => {
        // The missing branch RETURNS before recordWordOpens: no /words/opens
        // penalty can fire for a token without a word id (a word that stays
        // missing costs nothing — word-popup-overhaul ticket 01).
        const missingBranch = openPopupSource.slice(
            openPopupSource.indexOf('if (!entry?.w) {'),
            openPopupSource.indexOf('recordWordOpens'),
        );
        expect(missingBranch).toContain('return;');
        expect(openPopupSource).toContain('recordWordOpens');
    });

    it('posts the open penalty for every mapped open — no row-key guard left', () => {
        // word-popup-overhaul ticket 01: the −5 applies on EVERY open of a
        // mapped word — the old lookup event's `if (rowKey)` gate is gone
        // (the penalty needs no row identity), and the response recolors
        // the token + patches the popup header exactly as the lookup
        // event's response did.
        expect(openPopupSource).toContain('recordWordOpens([entry.w])');
        expect(openPopupSource).not.toContain("kind: 'lookup'");
        const postTail = openPopupSource.slice(openPopupSource.indexOf('recordWordOpens'));
        expect(postTail).toContain('onWordProgress?.(segment.key');
        expect(postTail).toContain('current?.wordId === entry.w');
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
    it('skips the detail fetch until an id exists — bound, or re-resolved by the lookup', () => {
        // The guard sits between the effect's opening and the fetch, so no
        // GET /words/ can fire for a token without a word id — mapped
        // directly, or re-resolved by ticket 07's on-open lookup (the
        // lookedUpId state feeding fetchId). A missing token with no
        // descriptor never gains an id, so its zero-fetch contract holds.
        expect(fetchEffectSource).toMatch(/const fetchId = wordId \?\? lookedUpId;/);
        // The early return still clears the previous word's loading/error/
        // data (and the queued ask's started notice) — the missing branch
        // keys on data == null, so a popup that moves here from a loaded
        // token without unmounting shows the missing card, never the stale
        // dictionary.
        expect(fetchEffectSource).toMatch(
            /if \(fetchId == null\) \{\s*setLoading\(false\);\s*setError\(null\);\s*setData\(null\);\s*setAiStarted\(false\);\s*return;\s*\}/,
        );
        // The mapped path's fetch itself is untouched.
        expect(popupSource).toContain('fetch(`/words/${fetchId}?surface=${encodeURIComponent(surface)}`');
    });

    it('renders the missing card until the on-open lookup resolves the adopted word (ticket 07)', () => {
        const ternaryAt = popupRenderSource.indexOf(') : (');
        expect(popupRenderSource).toMatch(/wordId == null && lookedUpId == null && data == null \? \(/);
        expect(ternaryAt).toBeGreaterThan(-1);
        // The missing branch holds only the missing card — the ticket 05
        // ask handler goes live on it only when a column language exists…
        const missingBranch = popupRenderSource.slice(popupRenderSource.indexOf('wordId == null && lookedUpId == null && data == null ? ('), ternaryAt);
        expect(missingBranch).toContain('surface={surface}');
        expect(missingBranch).toContain('<MissingPopupContent');
        expect(missingBranch).toContain('askAiMissing');
        expect(missingBranch).not.toContain('<PopupContent');
        // The full toolbar's wiring (word-popup-overhaul ticket 05): the
        // section-visibility map, the explain payload the disabled toggle +
        // live robot read, and the Models used popup state all thread down
        // (no explainKey — the pane never mounts on the missing card, its
        // toggle can never enable); the progress and Anki handlers do NOT —
        // their structural absence is what disables those icons until the
        // adopted payload loads in. (The queued ask itself swaps nothing —
        // word-popup-overhaul ticket 06.)
        expect(missingBranch).toContain('sections={effectiveSections}');
        expect(missingBranch).toContain('explainProps={explainProps}');
        expect(missingBranch).toContain('modelsOpen={modelsOpen}');
        expect(missingBranch).toContain('onModelsOpenChange={setModelsOpen}');
        expect(missingBranch).toContain('canAskAi={canAskAi}');
        expect(missingBranch).not.toContain('onProgressAction=');
        expect(missingBranch).not.toContain('onAnkiSend=');
        // …and PopupContent (toolbar, header, dictionary, Anki, explain)
        // renders the adopted payload once the on-open lookup resolved the
        // minted/adopted word and the fetch effect loaded it.
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

    it('holds the loading line while the on-open resolve is in flight (ADR 0096 amendment)', () => {
        // The not-in-dictionary note is a verdict — it may only show once
        // the resolve answered. While `resolving` the body renders the
        // shared word.loading line instead (the same string the detail
        // fetch's word-popup-loading uses); the note keeps its own hook and
        // settles back in on a miss.
        expect(missingContentSource).toMatch(/export function MissingPopupContent\(\{[\s\S]*?resolving = false/);
        expect(missingContentSource).toContain('{resolving ? (');
        expect(missingContentSource).toContain("t('word.loading')");
        expect(missingContentSource).toContain('word-popup-loading');
    });

    it('shows the real card’s six toolbar hooks, in the same order (word-popup-overhaul ticket 05)', () => {
        // The ask-AI-only strip is gone: the missing card mirrors the
        // dictionary card's row — know, remove mark, then pushed right:
        // Anki, Explanation toggle, Ask-AI, robot — every icon disabled,
        // never hidden, while no word exists behind the card (except the
        // two live ones pinned below; Ask-AI additionally hides for
        // non-admins per word-popup-overhaul ticket 06).
        const order = [
            'word-popup-progress-known',
            'word-popup-progress-reset',
            'word-popup-anki-send',
            'word-popup-explain-toggle',
            'word-popup-ask-ai',
            'word-popup-models-button',
        ];
        const at = order.map((hook) => missingToolbar.indexOf(hook));
        at.forEach((position, index) => expect(position, order[index]).toBeGreaterThan(-1));
        expect([...at].sort((a, b) => a - b)).toEqual(at);
        // Anki — the first right-hand icon — carries the same ml-auto push,
        // and it is the row's only one.
        expect(missingButtonIn('word-popup-anki-send')).toContain('ml-auto');
        expect(missingToolbar.match(/ml-auto/g)).toHaveLength(1);
    });

    it('disables know / remove-mark / Anki through the null-handler gates — no word to act on', () => {
        // The progress and Anki handlers are structurally absent in missing
        // mode (the outer WordPopup passes none — there is no word behind
        // the card to act on): the same gates the real card reads disable
        // through them, and go live only when the ask swaps the real card
        // in. The Anki icon keeps the add form — no in-deck state exists to
        // flip it.
        expect(missingButtonIn('word-popup-progress-known')).toMatch(/disabled=\{onProgressAction == null\}/);
        expect(missingButtonIn('word-popup-progress-reset')).toMatch(/disabled=\{onProgressAction == null\}/);
        expect(missingButtonIn('word-popup-anki-send')).toMatch(/disabled=\{onAnkiSend == null\}/);
        expect(missingButtonIn('word-popup-anki-send')).toContain('name="ankiAdd"');
        expect(missingButtonIn('word-popup-progress-known')).toContain("t('word.i_know_this')");
        expect(missingButtonIn('word-popup-progress-reset')).toContain("t('word.remove_mark')");
        expect(missingButtonIn('word-popup-anki-send')).toContain("t('word.anki_send')");
    });

    it('gates the Explanation toggle + robot on the explain payload; the toggle stays disabled', () => {
        // The same shared gate as the real card…
        expect(missingContentSource).toMatch(/const showExplain = explainProps != null && sections\.explanation !== false;/);
        expect(missingToolbar.match(/showExplain &&/g)).toHaveLength(2);
        // …but the missing card's toggle can never enable: there is no word
        // behind the card to attach the explanation to (and the pane it
        // would mount lives on the real card the ask swaps in — a plain
        // `disabled`, never pressed, never wired).
        const toggle = missingButtonIn('word-popup-explain-toggle');
        expect(toggle).toMatch(/\n\s+disabled\n/);
        expect(toggle).toContain('aria-pressed={false}');
        expect(toggle).not.toContain('onClick=');
        expect(toggle).toContain("t('word.explain_toggle')");
    });

    it('the robot is live — the Models used popup needs no word', () => {
        const robot = missingButtonIn('word-popup-models-button');
        expect(robot).toContain('onClick={() => onModelsOpenChange?.(true)}');
        expect(robot).toContain('aria-haspopup="dialog"');
        expect(robot).not.toContain('disabled=');
        expect(robot).toContain("t('ai.models_icon')");
        // Its modal mounts behind the same gate, fed from the explain
        // payload like the real card's.
        expect(missingContentSource).toMatch(/\{showExplain && \(\s*<ModelsUsedPopup/s);
        expect(missingContentSource).toContain('open={modelsOpen}');
        expect(missingContentSource).toContain('onClose={() => onModelsOpenChange?.(false)}');
    });

    it('keeps Ask-AI live on its existing gating — for approved admins only (ticket 06)', () => {
        const askAi = missingButtonIn('word-popup-ask-ai');
        expect(askAi).toContain('onClick={onAskAi}');
        expect(askAi).toMatch(/disabled=\{onAskAi == null \|\| aiBusy\}/);
        // One hoisted label const drives tooltip + aria (the real card's
        // pattern): a send in flight reads "sending", the default Ask AI.
        expect(askAi).toContain('title={askAiLabel}');
        expect(askAi).toContain('aria-label={askAiLabel}');
        expect(missingContentSource).toMatch(/const askAiLabel = aiBusy \? t\('word\.ask_ai_sending'\) : t\('word\.ask_ai'\);/);
        // Plain sparkles — the re-ask variant needs a payload's ai_asked,
        // which the missing card never has.
        expect(askAi).toContain('<Icon name="sparkles"');
        // Admin-only (word-popup-overhaul ticket 06): the icon renders for
        // approved admins only — hidden, not disabled, for everyone else,
        // matching the endpoint's 403. The handler gating above still
        // holds under the render gate.
        expect(missingToolbar).toMatch(/\{canAskAi && \(\s*<button[^>]*word-popup-ask-ai/s);
        expect(missingContentSource).toContain('canAskAi = false');
    });

    it('floats the queued ask’s started notice above the card (ticket 06)', () => {
        // The queued ask dispatches a job and answers {queued: true} — the
        // missing card stays missing; the transient notice (auto-dismissed
        // by the outer WordPopup) says the result arrives on the next open.
        expect(missingContentSource).toContain('aiStarted = false');
        expect(missingContentSource).toContain('aiStarted={aiStarted}');
    });

    it('still carries no header lines and fetches nothing', () => {
        for (const absent of ['word-popup-familiarity', 'word-popup-frequency', 'word-popup-form-of']) {
            expect(missingContentSource).not.toContain(absent);
        }
        // The card itself fetches nothing — the ask POST lives in the outer
        // WordPopup.
        expect(missingContentSource).not.toContain('fetch(');
        expect(missingContentSource).not.toContain('<AskAiError');
    });

    it('floats a failed ask in the info popup above the card — Retry, dismiss, no-model/no-key guidance', () => {
        // The shared info popup layer (word-popup-overhaul ticket 04): the
        // missing card mounts it with the ask handler and the dismiss
        // callback — the failed ask's guidance floats above the card, the
        // toolbar strip keeps only its icon.
        expect(missingContentSource).toContain('<InfoPopup');
        expect(missingContentSource).toContain('aiError={aiError}');
        expect(missingContentSource).toContain('aiErrorStatus={aiErrorStatus}');
        expect(missingContentSource).toContain('onRetryAskAi={onAskAi}');
        expect(missingContentSource).toContain('onDismissAskAi={onDismissAskAi}');
        expect(missingContentSource).not.toContain('<AskAiError');
        // The shared render carries the stable hook, the Retry control
        // (the four-state contract's error state) and the go-to-settings
        // link for the no-model (400) / no-key (403) answers — the
        // ExplainGuidance treatment.
        const errorComponent = popupSource.slice(
            popupSource.indexOf('function AskAiError'),
            popupSource.indexOf('function InfoDismissButton'),
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

describe('word.info_dismiss ui-string', () => {
    // The info popup's per-item dismiss control (word-popup-overhaul
    // ticket 04): the sticky items — the Anki error and the ask-AI error —
    // each carry one small button; the success notices auto-dismiss
    // instead.
    const dismissStringMatch = seederSource.match(/'word\.info_dismiss' => \[([\s\S]*?)\],/);

    it('exists for all three interface languages', () => {
        expect(dismissStringMatch).toBeTruthy();
        for (const lang of ['en', 'ru', 'be']) {
            const match = dismissStringMatch?.[1].match(new RegExp(`'${lang}' => '((?:[^'\\\\]|\\\\.)*)'`));
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
