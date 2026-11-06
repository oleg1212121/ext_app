import React, {useEffect, useState} from 'react';
import ModelsUsedPopup, {RobotHelpIcon} from './ModelsUsedPopup.jsx';
import {useI18n} from '../i18n';
import {buildCardModel} from '../lib/popupModel';
import {popupContainerClass, toolbarButtonClass} from '../lib/popupStyle';
import {Icon} from './icons.jsx';
// The info popup layer above the card (word-card ticket 03: a flat sibling).
import {InfoPopup} from './InfoPopup.jsx';

const TRANSLATIONS_PREVIEW = 8;
const TRANSCRIPTIONS_PREVIEW = 1;

// The toolbar descriptor's `kind` maps to the button's stable word-popup-*
// class hook — the card's structure stays addressable from outside CSS
// (page styles today; the profile preview inherits the classes) without
// coupling to utility-class churn.
const HOOK_FOR_KIND = {
    know: 'word-popup-progress-known',
    'remove-mark': 'word-popup-progress-reset',
    anki: 'word-popup-anki-send',
    explain: 'word-popup-explain-toggle',
    'ask-ai': 'word-popup-ask-ai',
    models: 'word-popup-models-button',
};

/**
 * One previewed popup line: the first `previewCount` items joined with
 * `separator`, then a "+N more…" expander for the rest (each line owns its
 * expanded state). `label` names the stable `word-popup-{label}` and
 * `word-popup-{label}-more` classes, `renderItem` maps an item to its text,
 * `decorate` wraps the joined text (transcriptions wear brackets), and
 * `empty` replaces the whole line when there is nothing to show — null
 * included, so a line without a fallback renders nothing at all.
 */
function CollapsibleLine({
    items,
    previewCount,
    separator,
    label,
    renderItem,
    decorate = null,
    empty = null,
    className,
}) {
    const {t} = useI18n();
    const [expanded, setExpanded] = useState(false);

    if (items.length === 0) {
        return empty;
    }

    const visible = expanded ? items : items.slice(0, previewCount);

    return (
        <p className={`word-popup-${label} ${className}`}>
            {decorate ? decorate(visible.map(renderItem).join(separator)) : visible.map(renderItem).join(separator)}
            {items.length > previewCount && !expanded && (
                <button
                    type="button"
                    onClick={() => setExpanded(true)}
                    className={`word-popup-${label}-more ml-1 underline underline-offset-2 opacity-70 hover:opacity-100`}
                >
                    {t('word.more', {count: items.length - previewCount})}
                </button>
            )}
        </p>
    );
}

/**
 * THE word card (word-card ticket 02): one component renders all four
 * statuses — a loaded dictionary payload, the missing card (an unmapped
 * token), and the bare pre-payload loading / load-error card — through the
 * view-model `lib/popupModel.js`'s buildCardModel derives from one state
 * object. The card owns NO derivations: everything the pre-word-card popup
 * bodies computed per render (the provenance badge, the effective
 * familiarity, the Anki label chain and color, the explain gating, the
 * ask-AI label and availability, the missing card's wordless icons, the
 * per-sense usage chips and dims, the Models used popup's props) arrives as
 * model output — toolbar descriptors, the header model, the entry list, the
 * body line, the Models used props and the info popup's notices. A handler
 * the shell does not pass is a capability the model reads as absent, so the
 * "structural absence disables the icon" semantics survive: the profile
 * preview's inert toolbar (its one handler opens the Models used popup;
 * none of the word actions is passed) and the missing card's wordless icons
 * (disabled by the model's no-payload rule, whatever handlers arrive) need
 * no special markup.
 *
 * Props: `state` — everything buildCardModel takes except `handlers` and
 * `explainShown` (the model output this component feeds back: the toggle's
 * pressed state and its pane are THIS component's concern, so
 * `explainShown` lives here, reset whenever the payload changes);
 * `handlers` — the one handlers object, bound by toolbar `kind` through one
 * lookup (progress action, Anki send, Anki probe, ask-AI, the two info-popup
 * dismissals, models-open change); `modelsOpen` — the Models used popup's
 * open state (card chrome, not a derivation); `explainPane` — the inert
 * ExplainPane element the shell built from its explain payload, mounted by
 * this card behind the toggle gate into the info popup layer (pane mount is
 * the auto-ask effect's fire, so mounting stays conditional on the model's
 * pressed verdict — `card.explain.pressed`, its read of `explainShown` — the
 * element itself is inert until rendered).
 *
 * The card surface, toolbar, header, dictionary and Models-used mount
 * render once, for every status — the pre-payload card is the same surface
 * minus toolbar, header and dictionary (the model returns none of them), so
 * a reader sees no difference anywhere.
 */
export function WordCard({state, handlers, modelsOpen = false, explainPane = null}) {
    const {t} = useI18n();
    // The Explanation pane starts hidden — nothing loads until the toolbar
    // icon's first click (pane mount == the auto-ask effect's fire).
    const [explainShown, setExplainShown] = useState(false);

    // A fresh payload (the popup moved to another word) hides the pane
    // again — the page-lifetime explain cache keeps its answer, so
    // re-opening the word and re-toggling never refetches.
    useEffect(() => {
        setExplainShown(false);
    }, [state.data]);

    // The card's one render source: the shell's state plus the pane's shown
    // flag and the handler set, through the model.
    const card = buildCardModel({...state, explainShown, handlers}, t);
    // The Explanation toggle and the robot exist only for popups with an
    // explain payload, and the ADR 0046 `explanation: false` key removes
    // both — the model's verdict (also the Models used popup's gate).
    const showExplain = card.explain.visible;
    // The five dictionary-block keys (translations, transcriptions,
    // definitions, examples, etymologies) are pass-through ADR 0046 wiring
    // — a key set to false drops that block — read off the same map.
    const sections = state.sections ?? {};

    // Toolbar buttons bind their handler by kind through one lookup — a
    // handler the shell does not pass stays undefined, and the model has
    // already read its absence into the descriptor's `disabled`.
    const clickForKind = {
        know: () => handlers.onProgressAction?.('known'),
        'remove-mark': () => handlers.onProgressAction?.('reset'),
        anki: handlers.onAnkiSend,
        explain: () => setExplainShown((shown) => !shown),
        'ask-ai': handlers.onAskAi,
        models: () => handlers.onModelsOpenChange?.(true),
    };

    return (
        <>
            {/* The info popup (word-popup-overhaul ticket 04): every
                transient message — the Anki send outcomes, the ask-AI
                error, the Explanation pane while its toggle is on — floats
                in ONE layer above the card, absolutely positioned inside
                the positioned wrapper (the card surface below is
                overflow-hidden, so the layer must be its sibling), so the
                card's content never shifts when anything appears or
                disappears. The wrapper is the layer's measuring context:
                parentElement in InfoPopup is this wrapper in both mounts
                (the portal and the profile preview). The pre-payload card
                has no info popup at all — the model returns no notices for
                it, and the bare card mounts no layer. The ask error's
                Retry control binds to the model's availability verdict
                (card.askAi.available — the same gate that enables the
                icon), so the missing card's admin + language + mid-resolve
                gating keeps the retry in lockstep with the sparkles icon. */}
            {card.notices && (
                <InfoPopup
                    ankiSend={card.notices.ankiSend}
                    onDismissAnki={handlers.onDismissAnki}
                    aiStarted={card.notices.aiStarted}
                    aiError={card.notices.aiError}
                    aiErrorStatus={card.notices.aiErrorStatus}
                    onRetryAskAi={card.askAi.available ? handlers.onAskAi : null}
                    onDismissAskAi={handlers.onDismissAskAi}
                    explainSlot={showExplain && card.explain.pressed && (
                        explainPane
                    )}
                />
            )}

            {/* The card surface — the overflow-hidden box every visible
                block lives in (word-popup-overhaul ticket 04 split the
                positioned wrapper from this surface so the info popup can
                hang above it unclipped). min-h-0 flex-1 lets the wrapper's
                maxHeight cap drive the dictionary's internal scroll; both
                are inert in the profile preview, whose wrapper is
                positioning-only and unconstrained. The pre-payload loading /
                error card is bare — no toolbar, no header, no dictionary —
                exactly the inline card it replaces. */}
            <div className={`word-popup-card ${popupContainerClass} min-h-0 flex-1`}>
                {card.status === 'loading' || card.status === 'error' ? (
                    <div className="p-3.5">
                        {card.bodyLine?.kind === 'loading' && (
                            <p className="word-popup-loading text-[0.857em] opacity-60">{card.bodyLine.text}</p>
                        )}
                        {card.bodyLine?.kind === 'error' && (
                            <p className="word-popup-error text-[0.857em] text-[var(--color-vermilion)] dark:text-[var(--color-vermilion-night)]">{card.bodyLine.text}</p>
                        )}
                    </div>
                ) : (
                    <>
                        {/* The action toolbar — the card's first row, above the word:
                            ICONS ONLY, rendered once for every card that has one
                            (the model emits only the descriptors that render, in
                            row order). Know / Remove mark on the left; pushed
                            right, in order: Save to Anki, the Explanation
                            toggle, the live Ask-AI icon, the robot (Models used
                            popup) rightmost. An inapplicable icon arrives
                            DISABLED from the model, never hidden — except Ask-AI,
                            which the model emits for approved admins only. Each
                            button binds its handler by kind through the one
                            lookup above. */}
                        <div className="word-popup-actions shrink-0 border-b border-[var(--color-hairline)] px-3.5 dark:border-[var(--color-hairline-night)]">
                            <div className="flex items-center gap-1 py-1.5">
                                {card.toolbar.map((b) => (
                                    <button
                                        key={b.kind}
                                        type="button"
                                        title={b.title}
                                        aria-label={b.label}
                                        aria-pressed={b.pressed}
                                        aria-haspopup={b.hasPopup}
                                        disabled={b.disabled}
                                        onClick={clickForKind[b.kind]}
                                        onMouseEnter={b.hoverProbe ? handlers.onAnkiProbe : undefined}
                                        className={`${HOOK_FOR_KIND[b.kind]} ${toolbarButtonClass(b.color, b.pressed)}${b.kind === 'anki' ? ' ml-auto' : ''}`}
                                    >
                                        {b.icon === 'robot'
                                            ? <RobotHelpIcon className="h-[1.25em] w-[1.25em]"/>
                                            : <Icon name={b.icon} className="h-[1.25em] w-[1.25em]"/>}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {/* The header block (word / familiarity / frequency /
                            form-of), closed by the hairline the POS sections
                            separate themselves with — the missing card's header
                            carries the raw clicked surface alone (the model
                            returns the line objects only when they render). */}
                        {card.header && (
                            <div className="word-popup-header shrink-0 border-b border-[var(--color-hairline)] px-3.5 pb-2.5 pt-3 dark:border-[var(--color-hairline-night)]">
                                {/* Reading font: RU headwords carry U+0301, which gaps when
                                    base and mark come from different fonts (see --font-reading). */}
                                <span className="word-popup-title font-reading text-[1.286em] leading-tight">{card.header.title}</span>

                                {card.header.familiarity && (
                                    <p className="word-popup-familiarity mt-0.5 text-[0.786em] tabular-nums opacity-60">
                                        {card.header.familiarity.text}
                                    </p>
                                )}

                                {card.header.frequency && (
                                    <p className="word-popup-frequency mt-0.5 text-[0.786em] tabular-nums opacity-60">
                                        {card.header.frequency.text}
                                    </p>
                                )}

                                {card.header.formOf && (
                                    <p className="word-popup-form-of mt-1 text-[0.857em] italic opacity-70">
                                        «{card.header.formOf.surface}» — {card.header.formOf.label}{' '}
                                        {card.header.formOf.headwords.map((headword, index) => (
                                            <React.Fragment key={`${headword}-${index}`}>
                                                {index > 0 && ', '}
                                                «{headword}»
                                            </React.Fragment>
                                        ))}
                                    </p>
                                )}
                            </div>
                        )}

                        {/* The dictionary view — always rendered on a card with
                            chrome (no tab strip), holding the word family's
                            sections; the missing card's body line rides inside
                            it (the model emits it as the bodyLine). The five
                            dictionary visibility keys are pass-through ADR 0046
                            wiring, read straight off the state's sections map. */}
                        <div className="word-popup-dictionary min-h-0 flex-1 overflow-y-auto px-3.5 pb-2.5">
                            {card.bodyLine?.kind === 'missing' && (card.bodyLine.resolving ? (
                                <p className="word-popup-loading mt-1 text-[0.929em] leading-snug opacity-70">{card.bodyLine.text}</p>
                            ) : (
                                <p className="word-popup-missing mt-1 text-[0.929em] leading-snug opacity-70">
                                    {card.bodyLine.text}
                                </p>
                            ))}

                            {card.entries.map(({entry, label, chips, dimmed, definitions}, index) => (
                                <section
                                    key={entry.id}
                                    className={`word-popup-section${index > 0
                                        ? ' mt-2.5 border-t border-[var(--color-hairline)] pt-2.5 dark:border-[var(--color-hairline-night)]'
                                        : ''}${dimmed
                                        ? ' word-popup-section-dimmed opacity-50 transition-opacity hover:opacity-100'
                                        : ''}`}
                                >
                                    {label && (
                                        // The word-level mark (popup-usage-labels
                                        // follow-up) dims the WHOLE <section> — the dim
                                        // branch in the section's className above carries
                                        // word-popup-section-dimmed at half opacity over
                                        // every block, hover restoring full opacity —
                                        // while the header keeps its own base 60% and
                                        // chips the mark after the text in the same tier
                                        // styling as a sense's.
                                        <h3 className="word-popup-section-label text-[0.786em] tracking-wider opacity-60">
                                            {label}
                                            {chips.map((chip, chipIndex) => (
                                                <span
                                                    key={chipIndex}
                                                    className={chip.dimming
                                                        ? 'word-popup-section-label-chip ml-1.5 inline-block rounded-sm border border-[var(--color-vermilion)]/40 px-1 text-[0.786em] leading-tight text-[var(--color-vermilion)] dark:border-[var(--color-vermilion-night)]/40 dark:text-[var(--color-vermilion-night)]'
                                                        : 'word-popup-section-label-chip ml-1.5 text-[0.786em] opacity-60'}
                                                >
                                                    {chip.text}
                                                </span>
                                            ))}
                                            {/* The provenance chips
                                                (popup-provenance ticket 04) — one
                                                small bordered label per source part
                                                trails every section label's very
                                                end ("DECIMATED — verb [wiktionary]
                                                [ai]"), where each reads as the
                                                form's data origin without crowding
                                                the headword. Hidden entirely when
                                                neither the importer nor a succeeded
                                                ask-AI is known — pre-provenance rows
                                                stay honest; the missing card has no
                                                sections, so no chip can render on
                                                it. */}
                                            {card.header.sourceBadge.map((part) => (
                                                <span
                                                    key={part}
                                                    className="word-popup-section-source ml-1.5 inline-block whitespace-nowrap rounded-sm border border-[var(--color-hairline)] px-1 text-[0.786em] leading-tight opacity-60 dark:border-[var(--color-hairline-night)]"
                                                >
                                                    {part}
                                                </span>
                                            ))}
                                        </h3>
                                    )}

                                    {sections.translations !== false && (
                                        // Empty is normal here: the background
                                        // auto-fetch (Yandex → Google) queues silently
                                        // and only lands on the next open, so the card
                                        // says so calmly instead of showing an error —
                                        // but only while the raw line has nothing to
                                        // show either (ticket 02): a word carrying
                                        // staged candidates never reads as empty.
                                        <React.Fragment>
                                            <CollapsibleLine
                                                items={entry.translations ?? []}
                                                previewCount={TRANSLATIONS_PREVIEW}
                                                separator=", "
                                                label="translations"
                                                renderItem={(translation) => translation.word}
                                                className="mt-2 text-[0.929em] leading-snug text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)]"
                                                empty={(entry.staged_translations?.length ?? 0) > 0 ? null : (
                                                    <p className="word-popup-translations-placeholder mt-2 text-[0.857em] opacity-60">
                                                        {t('word.no_translations')}
                                                    </p>
                                                )}
                                            />
                                            {(entry.staged_translations?.length ?? 0) > 0 && (
                                                // The staged raw candidates (the
                                                // words.translations JSON —
                                                // popup-provenance ticket 02, ADR 0085):
                                                // language-less as-is, amber, with the
                                                // muted raw: label and the line's own
                                                // "+N more…" expander. Popup-only — the
                                                // Anki fields keep the linked
                                                // translations.
                                                <CollapsibleLine
                                                    items={entry.staged_translations}
                                                    previewCount={TRANSLATIONS_PREVIEW}
                                                    separator=", "
                                                    label="raw-translations"
                                                    renderItem={(candidate) => candidate}
                                                    decorate={(text) => (
                                                        <>
                                                            <span className="opacity-60">{t('word.raw_translations')}</span>
                                                            {' '}
                                                            {text}
                                                        </>
                                                    )}
                                                    className="mt-1 text-[0.857em] leading-snug text-[var(--color-amber)] dark:text-[var(--color-amber-night)]"
                                                />
                                            )}
                                        </React.Fragment>
                                    )}

                                    {sections.transcriptions !== false && entry.transcriptions?.length > 0 && (
                                        <CollapsibleLine
                                            items={entry.transcriptions}
                                            previewCount={TRANSCRIPTIONS_PREVIEW}
                                            separator=" · "
                                            label="transcriptions"
                                            renderItem={(item) => item.value}
                                            decorate={(text) => `[${text}]`}
                                            className="mt-1 opacity-80"
                                        />
                                    )}

                                    {sections.definitions !== false && (definitions.length > 0 ? (
                                        <details className="word-popup-definitions mt-2 text-[0.857em]">
                                            <summary className="word-popup-summary cursor-pointer opacity-70">
                                                {t('word.definitions', {count: definitions.length})}
                                            </summary>
                                            {/* list-inside keeps the markers in the card's
                                                inline box — `ml-4` + outside markers push
                                                wide ("100.") numbers past the card edge. */}
                                            <ol className="word-popup-definitions-list mt-1.5 list-decimal list-inside space-y-1 text-[0.929em] leading-snug">
                                                {/* Usage-label chips (popup-usage-labels):
                                                    one per label before the sense text —
                                                    vermilion for the dimming tier, muted
                                                    plain text (the raw:-label precedent)
                                                    for the register one; a dimmed sense
                                                    fades whole, hover restoring the line.
                                                    The chips and the sense's dim are the
                                                    model's per-sense output (which never
                                                    stacks a sense dim inside an
                                                    already-dimmed section). Full literal
                                                    class strings — Tailwind's scanner
                                                    reads this file's text. */}
                                                {definitions.map((definition, definitionIndex) => (
                                                    <li
                                                        key={definitionIndex}
                                                        className={definition.dimmed
                                                            ? 'word-popup-definition-dimmed opacity-50 transition-opacity hover:opacity-100'
                                                            : undefined}
                                                    >
                                                        {definition.chips.map((chip, chipIndex) => (
                                                            <span
                                                                key={chipIndex}
                                                                className={chip.dimming
                                                                    ? 'word-popup-definition-label mr-1 inline-block rounded-sm border border-[var(--color-vermilion)]/40 px-1 text-[0.786em] leading-tight text-[var(--color-vermilion)] dark:border-[var(--color-vermilion-night)]/40 dark:text-[var(--color-vermilion-night)]'
                                                                    : 'word-popup-definition-label mr-1 text-[0.786em] opacity-60'}
                                                            >
                                                                {chip.text}
                                                            </span>
                                                        ))}
                                                        {definition.text}
                                                    </li>
                                                ))}
                                            </ol>
                                        </details>
                                    ) : (
                                        <p className="word-popup-no-definitions mt-1.5 text-[0.857em] opacity-60">{t('word.no_definitions')}</p>
                                    ))}

                                    {sections.examples !== false && entry.examples?.length > 0 && (
                                        <details className="word-popup-examples mt-2 text-[0.857em]">
                                            <summary className="word-popup-summary cursor-pointer opacity-70">
                                                {t('word.examples', {count: entry.examples.length})}
                                            </summary>
                                            <ul className="word-popup-examples-list ml-4 mt-1 list-disc space-y-0.5 italic opacity-80">
                                                {entry.examples.map((example, exampleIndex) => (
                                                    <li key={exampleIndex}>{example}</li>
                                                ))}
                                            </ul>
                                        </details>
                                    )}

                                    {sections.etymologies !== false && entry.etymologies?.length > 0 && (
                                        <details className="word-popup-etymologies mt-2 text-[0.857em]">
                                            <summary className="word-popup-summary cursor-pointer opacity-70">
                                                {t('word.etymology', {count: entry.etymologies.length})}
                                            </summary>
                                            {entry.etymologies.map((etymology, etymologyIndex) => (
                                                <p key={etymologyIndex} className="mt-1 text-[0.857em] italic leading-snug opacity-75">
                                                    {etymology}
                                                </p>
                                            ))}
                                        </details>
                                    )}
                                </section>
                            ))}
                        </div>

                        {/* The Models used popup — the robot's target, mounted behind
                            the same gate as the robot itself (the model's explain
                            verdict), fed the model's Models used props. While it is
                            open it owns Escape and outside-click closing; the word
                            popup underneath stays put. */}
                        {showExplain && (
                            <ModelsUsedPopup
                                open={modelsOpen}
                                onClose={() => handlers.onModelsOpenChange?.(false)}
                                enabled={card.modelsUsed.enabled}
                                answerModel={card.modelsUsed.answerModel}
                                explanationModel={card.modelsUsed.explanationModel}
                            />
                        )}
                    </>
                )}
            </div>
        </>
    );
}
