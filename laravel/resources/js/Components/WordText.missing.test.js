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

// The one word card (word-card ticket 02): the missing card is its
// `missing` status — the same toolbar, header and dictionary chrome every
// status renders through, fed by lib/popupModel.js's view-model. What the
// missing status DERIVES (the wordless icons disabled whatever handlers
// arrive, the plain neutral Anki icon, the disabled-but-present
// Explanation toggle, the admin + language + mid-resolve ask gate, the
// title-only header, the resolving/missing body line, the ask-only info
// popup messages) is asserted behaviorally in lib/popupModel.test.js ("the
// missing card"); the pins here lock the wiring that carries it.
const cardSource = readFileSync(new URL('./WordCard.jsx', import.meta.url), 'utf8');

// The card's toolbar block: from the strip's own class hook to the word
// header that follows it.
const cardToolbar = cardSource.slice(
    cardSource.indexOf('className="word-popup-actions'),
    cardSource.indexOf('className="word-popup-header'),
);

// The default portal's render: from the card-status selector to the end of
// the shell.
const popupRenderSource = popupSource.slice(popupSource.indexOf('const status = data != null'));

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
        // The status selector picks 'missing' while no id exists at all —
        // bound, looked up, or loaded — and the one WordCard mount renders
        // it (word-card ticket 02: no separate missing component).
        expect(popupRenderSource).toMatch(/wordId == null && lookedUpId == null && data == null \? 'missing'/);
        expect(popupSource).not.toContain('MissingPopupContent');
        expect(popupSource.match(/<WordCard/g)).toHaveLength(1);
        // The ticket 05 ask goes live on the missing card only when a
        // column language exists — the missing branch of the one ask
        // dispatch.
        expect(popupRenderSource).toContain('? askAiMissing : null');
        // The full toolbar's wiring (word-popup-overhaul ticket 05): the
        // raw surface, the section-visibility map, the explain payload the
        // disabled toggle + live robot read, and the Models used popup state
        // all thread into the card. (The queued ask itself swaps nothing —
        // word-popup-overhaul ticket 06.)
        expect(popupRenderSource).toMatch(/^ {24}surface,$/m);
        expect(popupRenderSource).toContain('sections: effectiveSections,');
        expect(popupRenderSource).toMatch(/^ {24}explainProps,$/m);
        expect(popupRenderSource).toContain('modelsOpen={modelsOpen}');
        expect(popupRenderSource).toContain('onModelsOpenChange: setModelsOpen,');
        expect(popupRenderSource).toMatch(/^ {24}canAskAi,$/m);
    });

    it('accepts the language prop — ticket 05’s adopt-on-ask consumes it', () => {
        expect(popupSource).toMatch(/export default function WordPopup\(\{[\s\S]*?language = null/);
    });
});

describe('The missing card (WordCard\'s missing status)', () => {
    it('shows the model\'s title and its body line on the stable hooks', () => {
        // The title is the model's (the raw clicked surface on the missing
        // card); the body line is the not-in-dictionary verdict once the
        // resolve settled.
        expect(cardSource).toContain('word-popup-title');
        expect(cardSource).toContain('{card.header.title}');
        expect(cardSource).toContain('word-popup-missing');
        expect(cardSource).toContain("card.body?.kind === 'missing'");
    });

    it('holds the loading line while the on-open resolve is in flight (ADR 0096 amendment)', () => {
        // The not-in-dictionary note is a verdict — it may only show once
        // the resolve answered. While resolving the body renders the shared
        // loading line on the word-popup-loading hook instead; the note
        // keeps its own hook and settles back in on a miss. The shell hands
        // the in-flight resolve over as state.
        const body = cardSource.slice(cardSource.indexOf("card.body?.kind === 'missing'"), cardSource.indexOf('card.entries.map('));
        expect(body).toContain('card.body.resolving ? (');
        expect(body).toContain('word-popup-loading');
        expect(body).toContain('word-popup-missing');
        expect(popupRenderSource).toContain('resolving: lookupBusy,');
    });

    it('shares the real card\'s toolbar — the same six hooks, the same ml-auto push', () => {
        // One toolbar renders for every status: the missing card mirrors the
        // dictionary card's row by construction (order and disabling are
        // model output).
        for (const hook of [
            'word-popup-progress-known',
            'word-popup-progress-reset',
            'word-popup-anki-send',
            'word-popup-explain-toggle',
            'word-popup-ask-ai',
            'word-popup-models-button',
        ]) {
            expect(cardSource, hook).toContain(`'${hook}'`);
        }
        expect(cardToolbar.match(/ml-auto/g)).toHaveLength(1);
    });

    it('the robot is live — the Models used popup needs no word', () => {
        // The same opener every status binds; its modal mounts behind the
        // model's explain gate, fed from the explain payload.
        expect(cardSource).toContain('models: () => handlers.onModelsOpenChange?.(true)');
        expect(cardSource).toMatch(/\{showExplain && \(\s*<ModelsUsedPopup/s);
        expect(cardSource).toContain('open={modelsOpen}');
        expect(cardSource).toContain('onClose={() => handlers.onModelsOpenChange?.(false)}');
    });

    it('keeps Ask-AI live on its existing gating — the click is the one ask dispatch', () => {
        expect(cardSource).toContain("'ask-ai': handlers.onAskAi");
        expect(popupRenderSource).toContain('onAskAi: askAiAction,');
    });

    it('fetches nothing — the ask POST lives in the outer WordPopup', () => {
        expect(cardSource).not.toContain('fetch(');
        expect(cardSource).not.toContain('<AskAiError');
    });

    it('floats a failed ask in the info popup above the card — Retry, dismiss, no-model/no-key guidance', () => {
        // The shared info popup layer (word-popup-overhaul ticket 04): the
        // card mounts it with the model's notices, the ask handler behind
        // the availability verdict and the dismiss callback — the failed
        // ask's guidance floats above the card, the toolbar strip keeps only
        // its icon. The queued ask's started notice (ticket 06) floats there
        // too.
        expect(cardSource).toContain('<InfoPopup');
        expect(cardSource).toContain('aiError={card.notices.aiError}');
        expect(cardSource).toContain('aiErrorStatus={card.notices.aiErrorStatus}');
        expect(cardSource).toContain('aiStarted={card.notices.aiStarted}');
        expect(cardSource).toContain('onRetryAskAi={card.askAi.available ? handlers.onAskAi : null}');
        expect(cardSource).toContain('onDismissAskAi={handlers.onDismissAskAi}');
        // The shared render carries the stable hook, the Retry control
        // (the four-state contract's error state) and the go-to-settings
        // link for the no-model (400) / no-key (403) answers — the
        // ExplainGuidance treatment. Its own file since word-card ticket 03.
        const errorSource = readFileSync(new URL('./AskAiError.jsx', import.meta.url), 'utf8');
        const errorComponent = errorSource.slice(
            errorSource.indexOf('function AskAiError'),
            errorSource.indexOf('function InfoDismissButton'),
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
