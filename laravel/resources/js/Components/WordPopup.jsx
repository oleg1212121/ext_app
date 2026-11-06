import {useEffect, useRef, useState} from 'react';
import {createPortal} from 'react-dom';
import {usePage} from '@inertiajs/react';
import {useI18n} from '../i18n';
import {isApprovedAdmin} from '../lib/authUser';
import {getCsrfToken} from '../lib/http';
import {FAMILIARITY_MAX, recordWordOpens} from '../lib/wordFamiliarity';
import {resolveWordId} from '../lib/wordLookup';
import {AnkiConnectError, markAnkiReachable, probeReachable, unmarkAnkiReachable} from '../lib/ankiConnect';
import {buildNoteFields, uiLabels} from '../lib/ankiTemplate';
import {findExistingNote, sendAnkiNote} from '../lib/ankiSync';
import {ankiWordSyncConfig} from '../lib/ankiWordConfig';
// The pure style helpers live in lib (word-card ticket 01) — the portal
// positions with popupStyle.
import {DEFAULT_POPUP_FONT_SIZE, popupStyle} from '../lib/popupStyle';
// The shell only (word-card ticket 03): the one word card renders every
// status (ticket 02), and the card's panes are flat siblings beside this
// file — the info popup layer (InfoPopup.jsx), the ask-AI error
// (AskAiError.jsx) and the Explanation pane with its page-lifetime cache
// (ExplainPane.jsx), which the shell builds as the inert element the card
// mounts behind its toggle.
import {WordCard} from './WordCard.jsx';
import {ExplainPane} from './ExplainPane.jsx';

// The Anki success notices' lifetime (word-popup-overhaul ticket 04): the
// info popup's only transient items — added / updated / duplicate dismiss
// themselves after ~4 seconds; errors stay sticky.
const ANKI_NOTICE_LIFETIME_MS = 4000;

/**
 * Word popover shown next to a Ctrl-clicked word: one section per part of
 * speech under the headword (details fetched lazily from GET /words/{id}),
 * plus the word progress actions and the Anki send in a top toolbar that
 * never scrolls away. `fontSize` (px) is the popup's root font, derived by
 * the host page from its reading font size (popupFontSizeFor); every inner
 * text size is em-relative to it, and the width scales with it.
 *
 * A `wordId` of null is MISSING mode (popup-ai-enrich tickets 03 + 05): the
 * token had no word-map entry, so nothing is fetched and no word-events fire
 * — the card renders from the raw `surface` alone through WordCard's
 * missing status (honest not-in-the-dictionary note, the full toolbar with every wordless
 * icon disabled — word-popup-overhaul ticket 05 — and the live Ask-AI icon).
 * Its ask posts `{surface, language_code}` from the `language` prop — the
 * clicked column's descriptor language ({code, name}|null), or the AI-answer
 * panel's ask language ({code}|null, derived learning-first from the
 * resolution snapshot, `lib/aiAnswerTokens.mjs`'s `answerAskLanguage` —
 * ADR 0096's adopt-less rule reversed for that surface); a mount without a
 * code keeps the icon hidden for non-admins and disabled for admins. The
 * server derives the lookup key, adopts onto the existing row or mints one
 * unknown-class word, and dispatches the queued enrichment
 * (word-popup-overhaul ticket 06 — no payload comes back to swap): the
 * card stays missing until the popup reopens, when the on-open resolve
 * finds the minted/adopted word and the fetch effect loads its payload
 * (the handlers then act on the adopted `data.id` through resolvedWordId;
 * the page's word map learns the new word on its next refresh).
 *
 * With an `explain` payload ({rowKind, rowId, side, sentenceIndex, modelKey,
 * enabled, modelLabel, followsAnswer, answerLabel}) the popup grows the
 * toolbar's Explanation toggle: the first click mounts the AI context
 * explanation pane as the dictionary view's first row and immediately
 * requests it; without the payload the toggle (and the robot) do not render.
 * `modelKey` (the user's explanation model id) only discriminates the client
 * cache and decides between the auto-request and choose-a-model guidance —
 * the server resolves the actual model per user preference. The label fields
 * (`modelLabel`, `followsAnswer`, and the simulator-only `answerLabel`)
 * exist for the toolbar's robot icon, which opens the Models used popup;
 * `enabled` false (no API key) keeps the toggle and shows add-key guidance
 * instead of a request.
 *
 * `sections` (the user's Popup preferences, ADR 0046) hides individual
 * blocks; it defaults to the popupVisibility prop shared to every page,
 * and an absent map means everything renders. The "Save to Anki" toolbar
 * icon ignores those toggles (always visible); it sends through
 * lib/ankiSync.js with the word's resolved config from the ankiSettings
 * shared prop (lib/ankiWordConfig.js: the language entry's deck + toggles,
 * per-language note type). On open a silent duplicate check
 * (lib/ankiSync.js's memoized findExistingNote — it never creates decks or
 * note types, and fails silently when Anki is off) drives the icon's add vs
 * update form; a send of an already-saved word updates the note in place.
 * Beside it, an on-open reachability probe (the cheap AnkiConnect version
 * action, popup-provenance ticket 05) paints the icon's amber token while
 * Anki answers and keeps it grey while the probe is pending, when Anki is
 * unreachable, or when no settings exist — send outcomes refresh the
 * answer without a re-probe. The probe is QUIET for a cold page session
 * (2026-10-24): while no successful Anki answer is on record — a fresh
 * reload, Anki never up — opens ask the lib's session ledger instead of
 * the network, because the browser logs every refused fetch as a console
 * error; the reader's hover or click of the icon probes ungated and is
 * how a running Anki gets re-discovered mid-session.
 * The body itself is the one WordCard (Components/WordCard.jsx, word-card
 * ticket 02), which renders every status — loaded, missing, loading, load
 * error — from lib/popupModel.js's view-model; the profile's Popups tab
 * mounts the same card for its live preview.
 */

// The missing-mode re-resolution POST (popup-ai-enrich ticket 07) lives in
// lib/wordLookup.js since ai-answer-words ticket 02: the sequential
// `resolveWordId` over an ordered language list — a single-code list
// ([language.code]) is the exact old postLookup contract — unit-tested in
// Node (the review pass unexported the single-code wrapper; no caller of
// its own).

export default function WordPopup({wordId, surface, familiarity, rect, onClose, onProgress, fontSize = DEFAULT_POPUP_FONT_SIZE, explain, sections, language = null, languageCodes = null}) {
    const {t} = useI18n();
    const {props} = usePage();
    const effectiveSections = sections ?? props.popupVisibility ?? {};
    const ankiSettings = props.ankiSettings ?? null;
    const nativeLanguage = props.auth?.user?.native_language ?? null;
    // Ask-AI is an approved-admin capability (word-popup-overhaul ticket
    // 06): the sparkles icon — plain and re-ask variants, dictionary and
    // missing cards alike — renders ONLY for approved admins (hidden, not
    // disabled, for everyone else; the endpoint answers the same gate with
    // a 403). The gate itself lives in the shared lib helper — the
    // profile's Popups-tab preview reads the same one.
    const canAskAi = isApprovedAdmin(props.auth?.user);
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [ankiSend, setAnkiSend] = useState(null);
    // The ask-AI request state for the current word: `aiBusy` while the
    // enrichment POST is in flight (the icon disables), `aiError` the latest
    // failure's message (shown in the info popup above the card, sticky
    // until dismissed — the icon stays retryable), `aiErrorStatus` its HTTP
    // status (400/403 — no model / no key — switch the error line to the
    // explain pane's guidance treatment). A success answers the minimal
    // {queued: true} marker — the provider call runs in the queue now
    // (word-popup-overhaul ticket 06) — so the payload is NEVER swapped:
    // `aiStarted` floats the transient "enrichment started" notice instead,
    // and the enriched content appears the next time the popup opens.
    const [aiBusy, setAiBusy] = useState(false);
    const [aiError, setAiError] = useState(null);
    const [aiErrorStatus, setAiErrorStatus] = useState(null);
    const [aiStarted, setAiStarted] = useState(false);
    // The on-open re-resolution (popup-ai-enrich ticket 07): a missing-mode
    // popup with a column language descriptor resolves its RAW surface
    // server-side before the missing card settles — a hit means the word
    // exists (a previous adopt-on-ask minted it; the page's word map just
    // never learned it), so `lookedUpId` feeds the fetch effect below and
    // the loaded payload's ai_asked picks the re-ask icon variant. A null (a
    // genuine
    // miss) — or a failed lookup — leaves ticket 03's missing card exactly
    // as it was. `lookupBusy` holds the card's ask icon while the resolve
    // is in flight.
    const [lookedUpId, setLookedUpId] = useState(null);
    const [lookupBusy, setLookupBusy] = useState(false);
    // The on-open duplicate check's outcome for the current word:
    // 'checking' while the findNotes round-trip is in flight (the icon
    // stays in add form — no spinner), 'found' once the word sits in its
    // language's deck (the icon flips to the update glyph), 'absent' when
    // it does not — or when Anki was unreachable: the sync layer collapses
    // "not found" and "couldn't ask" into one null, and a send still runs
    // its own find-before-add, so a misjudged icon can never create a
    // second card.
    const [ankiExisting, setAnkiExisting] = useState('checking');
    // The on-open reachability probe's outcome (popup-provenance ticket 05):
    // whether AnkiConnect answered its cheap version action for the current
    // open — amber for the Anki icon while true, grey otherwise (pending,
    // unreachable, no settings). A browser-side fact only: no backend flag,
    // re-probed per open so starting Anki mid-session is picked up on the
    // next popup.
    const [ankiReachable, setAnkiReachable] = useState(false);
    // The quiet-probe fourth state (2026-10-24): has THIS page session ever
    // heard a successful answer from Anki? A fresh page starts true —
    // nothing is on record — and while it stays true the on-open probe goes
    // quiet (lib/ankiConnect.js's onlyIfKnown gate): with Anki down a cold
    // page opening popups logs ZERO refused requests instead of one per
    // 30s window. The first reader engagement with the icon (hover or the
    // click) probes for real and flips this false whatever the answer —
    // from then on the session is either reachable (successes don't log)
    // or known-unreachable (failures memoized 30s there), and opens probe
    // again either way. A completed send also records a success.
    const [ankiUnknown, setAnkiUnknown] = useState(true);
    // Mirrors `data` on every render; the send's completion paths compare
    // the data they started with against it, so a late result for a
    // previous word never lands on the current one (the explainKey
    // precedent in ExplainPane).
    const dataRef = useRef(null);
    dataRef.current = data;
    // Mirrors the popup's open origin next to dataRef: the missing card's
    // adoption ask starts from data === null, so the data mirror alone
    // cannot tell "still on the missing token" from "the popup moved on"
    // (the fetch effect nulls data for the new word too) — the word id,
    // surface and column language can.
    const originRef = useRef(null);
    originRef.current = {wordId, surface, languageCode: language?.code ?? null};
    // The word identity the popup's handlers act on: the bound word id for a
    // mapped token, the adopted payload's id once the missing card's ask
    // swapped in (wordId stays null there — the page's word map only learns
    // the new word on its next refresh).
    const resolvedWordId = wordId ?? data?.id ?? null;
    const [modelsOpen, setModelsOpen] = useState(false);
    // Read by the document-level close handlers below: while the nested
    // Models used popup is open, that modal owns Escape and outside-click
    // closing — the word popup underneath must stay put.
    const modelsOpenRef = useRef(false);
    modelsOpenRef.current = modelsOpen;
    const ref = useRef(null);

    const explainKey = explain
        ? [explain.sentenceId, surface, explain.modelKey].join('|')
        : null;

    useEffect(() => {
        setModelsOpen(false);
    }, [wordId, explainKey]);

    useEffect(() => {
        // Missing mode (popup-ai-enrich ticket 03): no word id — nothing to
        // fetch and no payload state to reset; the card renders from the
        // surface alone. No GET /words/ may fire for a token without an id
        // — unless the on-open lookup resolved one (popup-ai-enrich ticket
        // 07): fetchId is then the word the surface re-resolved to,
        // fetched exactly like a mapped token so the payload's ai_asked
        // picks the toolbar's re-ask icon variant. The early return still
        // clears the
        // previous word's payload state — the missing-card branch keys on
        // data == null, so a popup that moves here from a loaded token
        // without unmounting must not keep painting that token's
        // dictionary.
        const fetchId = wordId ?? lookedUpId;
        if (fetchId == null) {
            setLoading(false);
            setError(null);
            setData(null);
            setAiStarted(false);
            return;
        }
        let cancelled = false;
        setLoading(true);
        setError(null);
        setData(null);
        setAnkiSend(null);
        // The new word starts undetected — the previous word's 'found' must
        // never paint onto it (the detection effect below re-asserts
        // 'checking' when it runs for the loaded data).
        setAnkiExisting('checking');
        // A fresh word also clears the previous word's ask state — an
        // in-flight enrichment for the old word is dropped by its
        // staleAsk guard below, so the flag never sticks, and the queued
        // ask's "started" notice belongs to the word it was asked on.
        setAiBusy(false);
        setAiError(null);
        setAiErrorStatus(null);
        setAiStarted(false);

        fetch(`/words/${fetchId}?surface=${encodeURIComponent(surface)}`, {
            headers: {Accept: 'application/json'},
        })
            .then(async (res) => {
                if (!res.ok) {
                    throw new Error(t('word.load_failed', {status: res.status}));
                }
                return res.json();
            })
            .then((json) => {
                if (!cancelled) {
                    setData(json.data);
                }
            })
            .catch((e) => {
                if (!cancelled) {
                    setError(e instanceof Error ? e.message : t('word.load_failed', {status: '?'}));
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [wordId, lookedUpId, surface, t]);

    // The Anki success notices' auto-dismiss (word-popup-overhaul ticket
    // 04): added / updated / duplicate are the info popup's only transient
    // items — ~4 seconds after a send lands its notice dismisses itself.
    // Errors are deliberately outside this gate: they stay sticky until
    // their per-item dismiss control (or the word change / popup close
    // below). The effect's cleanup is the timing guard: ANY change to
    // `ankiSend` — a new send, a manual dismiss, the fetch effect's
    // word-change reset, an unmount — tears the pending timer down before
    // it can fire, so a notice can never outlive its word or swallow a
    // newer one's state.
    useEffect(() => {
        if (ankiSend == null
            || (ankiSend.status !== 'added' && ankiSend.status !== 'updated'
                && ankiSend.status !== 'duplicate')) {
            return;
        }
        const timer = setTimeout(() => setAnkiSend(null), ANKI_NOTICE_LIFETIME_MS);
        return () => clearTimeout(timer);
    }, [ankiSend]);

    // The queued ask-AI's "started" notice auto-dismiss (word-popup-overhaul
    // ticket 06): a successful dispatch floats the transient notice through
    // the same ~4s gate as the Anki success notices — the same timing guard
    // applies: ANY `aiStarted` change (a newer ask, the fetch effect's
    // word-change reset, an unmount) tears the pending timer down before it
    // can fire.
    useEffect(() => {
        if (!aiStarted) {
            return;
        }
        const timer = setTimeout(() => setAiStarted(false), ANKI_NOTICE_LIFETIME_MS);
        return () => clearTimeout(timer);
    }, [aiStarted]);

    // The resolve list (ai-answer-words ticket 02), keyed as a primitive
    // string: the caller's ordered codes when a list is passed (ticket 03's
    // AI-answer mount — learning first), otherwise the clicked column's
    // single language, the exact pre-list behavior. Codes are locale tags
    // and never contain '|', so the join/split round-trip is lossless — and
    // keying on the string, not the array reference, keeps an inline
    // `languageCodes={[a, b]}` prop (a fresh array every parent render)
    // from re-triggering the effect below.
    const lookupCodesKey = languageCodes != null
        ? (languageCodes.join('|') || null)
        : (language?.code ?? null);

    // The on-open re-resolution (popup-ai-enrich ticket 07): in missing
    // mode the popup resolves its RAW surface server-side once per open —
    // the minted word a previous adopt-on-ask left behind re-links to the
    // page (`entities:enrich`, the scheduler dev never runs, is the only
    // other linker). Since ai-answer-words ticket 02 the resolve runs
    // through lib/wordLookup.js's sequential resolver: codes in list order,
    // the first hit wins (later codes never fire), a miss falls through,
    // and any transport failure collapses the whole sequence to null — the
    // single-code contract the popup always had. A hit flips the popup into
    // known mode through the fetch effect above; a null (all codes missed,
    // or the resolve failed) leaves the missing card. The cancelled guard
    // mirrors the fetch effect's and spans the whole sequence: a popup
    // closed or moved to another surface drops the late answer instead of
    // resolving the wrong token. `lookupBusy` holds the card's ask icon
    // until the sequence settles either way.
    useEffect(() => {
        setLookedUpId(null);
        if (wordId != null || lookupCodesKey == null) {
            return;
        }
        let cancelled = false;
        setLookupBusy(true);
        resolveWordId(surface, lookupCodesKey.split('|')).then((found) => {
            if (!cancelled) {
                setLookupBusy(false);
                setLookedUpId(found);
            }
        });
        return () => {
            cancelled = true;
        };
    }, [wordId, surface, lookupCodesKey]);

    // The popup-open penalty (word-popup-overhaul ticket 01): a missing-mode
    // popup whose surface RESOLVED to a dictionary entry — this instance's
    // on-open lookup, or the 409 sync's re-resolve (both land through
    // setLookedUpId) — costs −5 familiarity ONCE per popup instance, ledger-
    // free (the server dedupes nothing; repeat opens are fresh instances
    // and apply again). Keyed on the resolved id alone: a miss (null) never
    // fires — a word that stays missing costs nothing — and a bound wordId
    // never fires either (lookedUpId stays null; WordText posts that open's
    // penalty itself). Best-effort like every event POST: a failure leaves
    // the header at the stale score.
    const [resolvedFamiliarity, setResolvedFamiliarity] = useState(null);
    useEffect(() => {
        if (lookedUpId == null) {
            setResolvedFamiliarity(null);
            return;
        }
        let cancelled = false;
        recordWordOpens([lookedUpId]).then((familiarity) => {
            if (!cancelled && familiarity[lookedUpId] !== undefined) {
                setResolvedFamiliarity(familiarity[lookedUpId]);
            }
        });
        return () => {
            cancelled = true;
        };
    }, [lookedUpId]);

    // The familiarity score the Anki card's Familiarity field reports: the
    // caller's open-time value, overridden by the popup-open penalty's
    // response once it lands — the same slot the columns patch through their
    // own response handling (WordText). The card's header resolves the same
    // two values itself (lib/popupModel.js, which receives both), so header
    // and note can never disagree; this copy feeds the Anki send, whose
    // cluster stays in the shell (word-card ticket 02 scope).
    const effectiveFamiliarity = resolvedFamiliarity ?? familiarity ?? null;

    // On-open duplicate detection (anki-popup-refresh): the popup silently
    // asks Anki whether this word is already saved — scoped to the word's
    // language deck + Word + Language through lib/ankiSync.js's
    // findExistingNote, which never runs the ensure (detection must not
    // create decks or note types), answers from its page-session memo on
    // re-opens and on send-seeded entries, and resolves null on ANY failure
    // — so an unreachable Anki quietly leaves the add-form icon. The check
    // only runs once the reachability probe has answered reachable
    // (2026-10-24): with Anki off it never fires, so a popup open costs no
    // findNotes request at all. Keyed to the loaded data like the fetch
    // effect above; the cancelled guard drops a late answer for a popup
    // that has since moved to another word.
    useEffect(() => {
        if (!ankiReachable || !ankiSettings || !data) {
            return;
        }
        let cancelled = false;
        setAnkiExisting('checking');
        const language = data.language_code ?? null;
        findExistingNote(ankiWordSyncConfig(ankiSettings, language), {word: data.word, language}).then((noteId) => {
            if (!cancelled) {
                setAnkiExisting(noteId == null ? 'absent' : 'found');
            }
        });
        return () => {
            cancelled = true;
        };
    }, [ankiReachable, ankiSettings, data]);

    // On-open reachability probe (popup-provenance ticket 05): beside the
    // duplicate check it shares the open lifecycle with — same
    // [ankiSettings, data] key, same cancelled guard — the popup asks the
    // user's AnkiConnect endpoint its cheap version action through
    // lib/ankiConnect.js's probeReachable. Quiet until the session has
    // heard from Anki (2026-10-24): the probe runs with {onlyIfKnown:
    // true}, and the lib's page-session success ledger answers null
    // WITHOUT a request while no success is on record — the browser logs
    // every refused fetch as a console error, so with Anki down a cold
    // page opening popups stays silent instead of erroring once per 30s
    // window. A null answer (quiet) and a rejection (known-unreachable,
    // failures memoized 30s there) both keep the icon grey; a resolve
    // marks it reachable — the Save-to-Anki icon paints its amber token
    // and the duplicate check fires. The flag starts cleared — whatever a
    // previous open proved, the current probe answers for itself — and
    // re-runs per open (the key moves with every loaded payload); the
    // reader's hover/click of the icon probes ungated (probeAnkiNow
    // below), so a cold session learns Anki came up without a reload. A
    // late answer for a popup that has moved to another word is dropped.
    useEffect(() => {
        if (!ankiSettings || !data) {
            return;
        }
        let cancelled = false;
        setAnkiReachable(false);
        probeReachable(ankiSettings, {onlyIfKnown: true}).then(
            (answer) => {
                if (!cancelled) {
                    setAnkiReachable(answer != null);
                }
            },
            () => {
                if (!cancelled) {
                    setAnkiReachable(false);
                }
            },
        );
        return () => {
            cancelled = true;
        };
    }, [ankiSettings, data]);

    // The reader-engaged probe (2026-10-24): hovering the grey Anki icon —
    // or clicking it — asks the endpoint ungated, whatever the session
    // ledger holds. A resolve marks the session reachable (the ledger
    // records the success, so later opens probe again) and lights the
    // icon amber in place; a rejection makes unreachability KNOWN — the
    // unknown flag drops and the tooltip switches to the start-Anki
    // guidance (failures stay memoized 30s in the lib, so mousing over
    // the icon costs at most one refused request per window). A click
    // runs this first, then sendToAnki's find-before-add talks to Anki
    // for real — the send is its own reachability proof either way.
    const probeAnkiNow = () => {
        if (!ankiSettings) {
            return;
        }
        probeReachable(ankiSettings).then(
            () => {
                setAnkiReachable(true);
                setAnkiUnknown(false);
            },
            () => {
                setAnkiReachable(false);
                setAnkiUnknown(false);
            },
        );
    };

    useEffect(() => {
        const onKeyDown = (event) => {
            if (event.key === 'Escape') {
                if (modelsOpenRef.current) {
                    return;
                }
                event.stopPropagation();
                onClose();
            }
        };
        const onMouseDown = (event) => {
            if (modelsOpenRef.current) {
                return;
            }
            if (ref.current && !ref.current.contains(event.target)) {
                onClose();
            }
        };

        document.addEventListener('keydown', onKeyDown, true);
        document.addEventListener('mousedown', onMouseDown);
        return () => {
            document.removeEventListener('keydown', onKeyDown, true);
            document.removeEventListener('mousedown', onMouseDown);
        };
    }, [onClose]);

    const changeProgress = (action) => {
        setBusy(true);
        const method = action === 'known' ? 'PATCH' : 'DELETE';
        fetch(`/words/${resolvedWordId}/progress`, {
            method,
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                ...(getCsrfToken() ? {'X-CSRF-TOKEN': getCsrfToken()} : {}),
            },
            body: method === 'PATCH' ? JSON.stringify({familiarity: FAMILIARITY_MAX}) : undefined,
        })
            .then(async (res) => {
                if (!res.ok) {
                    throw new Error(`${res.status}`);
                }
                onProgress(surface, action === 'known' ? FAMILIARITY_MAX : null);
            })
            .catch(() => setBusy(false));
    };

    // The icon-click handler (2026-10-24): the click is also the check —
    // the ungated probe fires first (learning Anki came up lights the icon
    // amber in place), then the send's find-before-add talks to Anki
    // for real. With Anki off the send fails its first request and lands
    // in the inline error line — never a doomed multi-request cascade,
    // because the send's own findExistingNote hits the same refused
    // endpoint once and the sync layer collapses the failure to null
    // (find-before-add) before addNote is even attempted... whose failure
    // is the same single refused endpoint. The hover half lives in
    // probeAnkiNow, passed down as the icon's onAnkiProbe.
    const ankiIconClick = () => {
        probeAnkiNow();
        sendToAnki();
    };

    // The info popup's dismiss controls (word-popup-overhaul ticket 04): the
    // sticky items — the Anki error and the ask-AI error — clear their own
    // message state on click; everything else (the success notices'
    // auto-dismiss timer, the word change reset, the popup close unmount)
    // keeps clearing through the same state.
    const dismissAnkiStatus = () => setAnkiSend(null);
    const dismissAskAiError = () => {
        setAiError(null);
        setAiErrorStatus(null);
    };

    // The per-word "Save to Anki" / "Update in Anki" send: the note is built
    // from the payload the popup already loaded — the same builder the Anki
    // tab's sample card uses, with the Translations field narrowed to the
    // user's native language (the shared auth prop carries its code), the
    // word's tags from the payload, and the popup's own UI-language strings
    // as the structured values' labels (counted summaries, "+N more…"
    // expander, "form of") — the same baking the tab does, so a card is
    // worded in the sender's language. The resolved fields map rides along
    // too: the body toggles (plus the `body` master toggle) shape the Body
    // value at SEND time — newly sent cards only (spec V2); the scalar
    // toggles stay template-side. The config is the WORD's, not the global
    // base: ankiWordSyncConfig resolves the language entry
    // (languages[code] ?? languages.default) — the same config the on-open
    // detection used, so send and check can never target different decks.
    // The send then goes find-before-add through lib/ankiSync.js: a fresh
    // word is added, a found note of OUR types (configured per-language or
    // legacy base) is refreshed in place — all thirteen values rebuilt, tags
    // replaced, id/deck/scheduling untouched — and only a foreign-model note
    // stops at "Already in Anki.". The ensure (deck / note type / template)
    // is memoized module state shared with the tab, so the first send of a
    // session auto-creates them and later sends skip straight to the
    // duplicate check. Failures land in `ankiSend` for the info popup above
    // the card — never a silent no-op — and every completion first checks
    // the mirrored data ref: a popup that moved to another word never
    // adopts the previous word's result.
    const sendToAnki = () => {
        if (!ankiSettings || !data || ankiSend?.status === 'sending') {
            return;
        }
        const language = data.language_code ?? null;
        const config = ankiWordSyncConfig(ankiSettings, language);
        setAnkiSend({status: 'sending'});
        const tags = data.tags ?? [];
        const noteFields = buildNoteFields(data, {
            familiarity: effectiveFamiliarity,
            tags,
            nativeLanguage,
            labels: uiLabels(t),
            fields: config.fields,
        });
        sendAnkiNote(config, noteFields, {tags, word: data.word}).then(
            (result) => {
                if (dataRef.current !== data) {
                    return;
                }
                // A completed send is itself reachability evidence — Anki
                // answered every action, an added / updated note and a
                // foreign-model duplicate alike — so the session ledger
                // records the success (later opens probe for real again)
                // and the icon flips amber without a re-probe.
                markAnkiReachable(ankiSettings);
                setAnkiUnknown(false);
                setAnkiReachable(true);
                const status = result.added ? 'added' : result.updated ? 'updated' : 'duplicate';
                setAnkiSend({status});
                if (result.added || result.updated) {
                    // Our note is in the deck now: flip the icon to its
                    // update form without a re-query — the sync layer also
                    // seeded the detection memo, so the next open answers
                    // from the memo too.
                    setAnkiExisting('found');
                }
            },
            (error) => {
                if (dataRef.current !== data) {
                    return;
                }
                // A network-kind failure means Anki never answered — the
                // session ledger drops the endpoint (later opens go quiet
                // again) and the icon turns grey, now KNOWN-unreachable
                // (the tooltip switches to the start-Anki guidance). An
                // anki-kind failure means AnkiConnect answered with an
                // error string and a note-type mismatch means the
                // find-before-add talked to it: both keep the icon amber.
                if (error instanceof AnkiConnectError && error.kind === 'network') {
                    unmarkAnkiReachable(ankiSettings);
                    setAnkiUnknown(false);
                    setAnkiReachable(false);
                }
                setAnkiSend({status: 'error', error});
            },
        );
    };

    // The per-word Ask-AI send (popup-ai-enrich tickets 04 + 05;
    // word-popup-overhaul ticket 06 queued it): one POST to
    // /ai/word-enrich. The response is the minimal {queued: true} marker —
    // the provider call runs in the queue — so the success path NEVER
    // swaps the payload: it floats the transient "AI enrichment started"
    // notice through the info popup, and the enriched content appears the
    // next time the popup opens (the icon's ai_asked flips then too — the
    // re-ask variant picks it up from the fresh payload, never live). A
    // failed JOB is silent client-side: nothing to wire, the ledger just
    // stays failed and the icon retryable. Both completion paths check the
    // ask's origin first (see staleAsk): a late result for a popup that
    // has moved to another token is dropped, and the fetch effect's reset
    // clears the in-flight flag and the notice for the new word.
    // Failures of the dispatch itself land in `aiError` in the info popup
    // above the card — the server's guidance verbatim (no key, no model,
    // already asked/in flight, throttled, non-admin), sticky until
    // dismissed.
    const staleAsk = (origin) => dataRef.current !== origin.data
        || originRef.current.wordId !== origin.wordId
        || originRef.current.surface !== origin.surface
        || originRef.current.languageCode !== origin.languageCode;

    const runEnrich = (body, origin) => {
        setAiBusy(true);
        setAiError(null);
        setAiErrorStatus(null);
        fetch('/ai/word-enrich', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                ...(getCsrfToken() ? {'X-CSRF-TOKEN': getCsrfToken()} : {}),
            },
            body: JSON.stringify(body),
        })
            .then(async (res) => {
                const json = await res.json().catch(() => null);
                if (!res.ok) {
                    // The status rides along: 400/403 (no model / no key)
                    // switch the missing card's error line to the explain
                    // pane's guidance treatment.
                    const error = new Error(
                        json?.error
                            ?? json?.message
                            ?? t('word.ask_ai_failed', {status: res.status}),
                    );
                    error.status = res.status;
                    throw error;
                }

                return json;
            })
            .then((json) => {
                if (staleAsk(origin)) {
                    return;
                }
                // Queued: the ask is dispatched, the content arrives on the
                // next open — the notice says so, the card keeps its
                // current content (no live swap).
                setAiBusy(false);
                setAiErrorStatus(null);
                setAiStarted(true);
            })
            .catch((e) => {
                if (staleAsk(origin)) {
                    return;
                }
                setAiBusy(false);
                setAiErrorStatus(e.status ?? null);
                setAiError(e instanceof Error && e.message
                    ? e.message
                    : t('word.ask_ai_failed', {status: '?'}));

                // The 409 sync: the only enrich 409 left (ADR 0082
                // amendment — the completed lock is gone) is the "in
                // progress" gate, meaning another tab/tab-window's ask is
                // mid-flight. Refetching the payload is a harmless sync —
                // ai_asked stays false until the winner's ask succeeds (a
                // popup that was already re-ask-able just stays that way) —
                // and it future-proofs the icon against any payload drift
                // while the request ran. The missing card has no id to
                // refetch (the adopt-on-ask 409 carries no payload), so its
                // lookup re-resolves the adopted word and the fetch effect
                // loads the resulting payload.
                if (e.status === 409) {
                    if (origin.data?.id != null) {
                        fetch(`/words/${origin.data.id}?surface=${encodeURIComponent(surface)}`, {
                            headers: {Accept: 'application/json'},
                        })
                            .then(async (res) => (res.ok ? res.json() : null))
                            .then((json) => {
                                if (json && !staleAsk(origin)) {
                                    setData(json.data);
                                }
                            })
                            .catch(() => {});
                    } else if (language?.code != null) {
                        // The single code the ask itself carried — the ask
                        // flow stays keyed on the column language; the
                        // ordered list never widens it.
                        resolveWordId(surface, [language.code]).then((found) => {
                            if (found != null && !staleAsk(origin)) {
                                setLookedUpId(found);
                            }
                        });
                    }
                }
            });
    };

    // The known-word ask / re-ask (ticket 04, re-ask per the ADR 0082
    // amendment): the BOUND word id (data.id — the same id the server's
    // ledger tracks and the payload's ai_asked reports) plus the popup's
    // current surface. The queued flow answers {queued: true} without a
    // payload, so the surface matters only if the dispatch itself 409s —
    // the refetch sync below reuses it.
    const askAi = () => {
        if (!data || aiBusy) {
            return;
        }
        runEnrich({word_id: data.id, surface}, {data, wordId, surface, languageCode: language?.code ?? null});
    };

    // The missing card's adopt-on-ask (ticket 05): the RAW clicked surface
    // plus the clicked column's language code — the server derives the
    // lookup key (never trusting the title), adopts onto the existing row
    // or mints one, and dispatches the queued job. The card stays missing
    // until the popup reopens: the on-open resolve then finds the minted
    // word and the fetch effect loads its payload (the response carries no
    // payload to swap anymore). A column without a language descriptor
    // keeps the icon hidden for non-admins and disabled here for admins:
    // there is nothing valid to ask with.
    const askAiMissing = () => {
        if (data != null || aiBusy || language?.code == null) {
            return;
        }
        runEnrich({surface, language_code: language.code}, {data: null, wordId, surface, languageCode: language.code});
    };

    const explainProps = explain && explainKey
        ? {...explain, wordId: resolvedWordId, surface}
        : null;

    // The card's status (word-card ticket 02) — the old three-branch render,
    // now one selector feeding the one WordCard. Missing mode (popup-ai-enrich
    // ticket 03) holds until the on-open lookup resolves the adopted word a
    // queued ask minted (ticket 05's ask dispatches the job — no payload
    // comes back to swap — and ticket 07's resolve links it on the next
    // open). Once an id exists, the pre-payload card shows the loading line
    // or the load error until the payload lands. The one render between a
    // lookup hit and the fetch effect's start (an id, nothing loading yet)
    // has no status and mounts no card — exactly as the old branches drew
    // an empty wrapper there.
    const status = data != null
        ? 'loaded'
        : (wordId == null && lookedUpId == null && data == null ? 'missing' : (error ? 'error' : (loading ? 'loading' : null)));

    // The card's ask dispatch, one handler for both asking statuses: the
    // loaded card asks (or re-asks) on the bound word id; the missing card
    // adopts-on-ask from its raw surface — gated as it always was: admin
    // only, a column language to ask in, and the on-open resolve settled.
    // A null handler is an absent capability the model reads as a disabled
    // (or, without the admin gate, hidden) icon.
    const askAiAction = data != null
        ? (canAskAi ? askAi : null)
        : (canAskAi && language?.code != null && !lookupBusy ? askAiMissing : null);

    return createPortal(
        // The POSITIONED WRAPPER (word-popup-overhaul ticket 04): positioning
        // only — popupStyle's box, the dialog semantics and the close
        // handlers' `ref` — while the card surface itself lives one level
        // down (WordCard renders it). The split gives the info popup layer a
        // home beside the overflow-hidden card surface: a child of THIS
        // wrapper is never clipped by it.
        <div
            ref={ref}
            role="dialog"
            aria-label={data?.word ?? surface}
            style={{...popupStyle(rect, fontSize), fontSize: `${fontSize}px`}}
            className="word-popup absolute z-50 flex flex-col"
        >
            {/* The one word card (word-card ticket 02), every status. The
                shell hands over its state and ONE handlers object; the card
                builds the view-model (lib/popupModel.js) and derives
                nothing itself. Every handler rides along on every status —
                the missing card's wordless icons (know / remove mark / Anki)
                are disabled by the model's no-payload rule, whatever
                handlers arrive, while the robot stays live and Ask-AI
                renders for approved admins only (word-popup-overhaul ticket
                06). The Explanation pane arrives as an inert element the
                card mounts behind its own toggle. */}
            {status != null && (
                <WordCard
                    state={{
                        status,
                        surface,
                        data,
                        error,
                        familiarity,
                        resolvedFamiliarity,
                        sections: effectiveSections,
                        explainProps,
                        progressBusy: busy,
                        ankiSend,
                        ankiExisting,
                        ankiReachable,
                        ankiSettings,
                        ankiUnknown,
                        canAskAi,
                        aiBusy,
                        aiStarted,
                        aiError,
                        aiErrorStatus,
                        languageCode: language?.code ?? null,
                        resolving: lookupBusy,
                    }}
                    handlers={{
                        onProgressAction: changeProgress,
                        onAnkiSend: ankiIconClick,
                        onAnkiProbe: probeAnkiNow,
                        onAskAi: askAiAction,
                        onDismissAnki: dismissAnkiStatus,
                        onDismissAskAi: dismissAskAiError,
                        onModelsOpenChange: setModelsOpen,
                    }}
                    modelsOpen={modelsOpen}
                    explainPane={explainProps ? <ExplainPane explain={explainProps} explainKey={explainKey}/> : null}
                />
            )}
        </div>,
        document.body,
    );
}
