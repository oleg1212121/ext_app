import "../../../../css/bilingual-table.css";
import CheckboxInput from "../../../Components/Forms/CheckboxInput.jsx";
import Button from "../../../Components/Forms/Button.jsx";
import WordText from "../../../Components/WordText.jsx";
import {useI18n} from '../../../i18n';
import React, {useMemo} from "react";

/**
 * The alignment table. Columns are display-oriented, not side letters: the
 * left column is the learning target (hidden until revealed, read-credited),
 * the right column the base the learner translates from (Open/Ask actions).
 * The caller maps the match's actual sides onto these columns — targetSide/
 * baseSide keep the real side letters for the word-explain payloads.
 */
export default function TextContent(props) {
    const {t} = useI18n();
    const rowOffset = props.rowOffset ?? 0;
    const hasRows = (props.rows?.length ?? 0) > 0;

    // Passed through whenever the side is language-eligible — enabled or
    // not — so keyless users still get the word popup's tab strip and its
    // Models used popup; `enabled` rides along for the guidance states.
    const targetExplain = useMemo(
        () => (props.targetExplainable ? props.explain : undefined),
        [props.explain, props.targetExplainable],
    );
    const baseExplain = useMemo(
        () => (props.baseExplainable ? props.explain : undefined),
        [props.explain, props.baseExplainable],
    );

    if (!hasRows) {
        return (
            <div className="flex-1 overflow-y-auto bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] flex items-center justify-center px-6 py-16">
                <div className="max-w-md text-center">
                    {props.loadError ? (
                        <>
                            <p className="font-[var(--wbench-mono)] text-[10px] tracking-[0.24em] uppercase text-[var(--wbench-danger)] dark:text-[var(--wbench-danger-night)] mb-3">{t('bilinguals.couldnt_load')}</p>
                            <p className="font-[var(--wbench-serif)] text-lg text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] leading-snug">{props.loadError}</p>
                            <p className="mt-3 font-[var(--wbench-sans)] text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">{t('bilinguals.load_error_hint')}</p>
                        </>
                    ) : !props.hasText ? (
                        <>
                            <p className="font-[var(--wbench-mono)] text-[10px] tracking-[0.24em] uppercase text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] mb-3">{t('bilinguals.no_text_selected')}</p>
                            <p className="font-[var(--wbench-serif)] text-lg text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] leading-snug">{t('bilinguals.pick_text_hint')}</p>
                        </>
                    ) : !props.hasLoaded ? (
                        <>
                            <p className="font-[var(--wbench-mono)] text-[10px] tracking-[0.24em] uppercase text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] mb-3">{t('bilinguals.no_text_loaded')}</p>
                            <p className="font-[var(--wbench-serif)] text-lg text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] leading-snug">{t('bilinguals.press')} <span className="font-[var(--wbench-sans)] font-medium">{t('bilinguals.load')}</span> {t('bilinguals.load_hint_tail')}</p>
                        </>
                    ) : (
                        <>
                            <p className="font-[var(--wbench-mono)] text-[10px] tracking-[0.24em] uppercase text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] mb-3">{t('bilinguals.no_text_loaded')}</p>
                            <p className="font-[var(--wbench-serif)] text-lg text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] leading-snug">{t('bilinguals.no_aligned_rows')}</p>
                        </>
                    )}
                </div>
            </div>
        );
    }

    const targetLabel = props.targetLanguage?.name ?? props.targetLanguage?.code ?? '';
    const baseLabel = props.baseLanguage?.name ?? props.baseLanguage?.code ?? '';
    const targetGlyph = (props.targetLanguage?.code ?? '').toUpperCase();
    const baseGlyph = (props.baseLanguage?.code ?? '').toUpperCase();

    return (
        <div className="flex-1 overflow-y-auto bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] pb-5">
            <table className="bilingual-table table w-full">
                <colgroup>
                    <col className="bilingual-lang-col"/>
                    <col className="bilingual-control-col"/>
                    <col className="bilingual-control-col"/>
                    <col className="bilingual-control-col"/>
                    <col className="bilingual-lang-col"/>
                </colgroup>
                <thead
                    className="sticky top-0 z-10 bg-[var(--wbench-paper-deep)] dark:bg-[var(--wbench-paper-deep-night)] border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]">
                <tr className="font-[var(--wbench-mono)] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] text-[10px] tracking-[0.2em] uppercase">
                    <th className="px-4 py-2 text-left">
                        <div className="flex items-center gap-2">
                            <span className="font-[var(--wbench-serif)] italic normal-case tracking-normal text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">{targetLabel}</span>
                            <CheckboxInput id='all_target' checked={!!props.allTarget} onChange={(event) => props.onToggleAllTarget?.(event.target.checked)}/>
                        </div>
                    </th>
                    <th className="px-2 py-2 text-center text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)]">{targetGlyph}</th>
                    <th className="px-2 py-2 text-center opacity-50">#</th>
                    <th className="px-2 py-2 text-center text-[var(--wbench-accent)] dark:text-[var(--wbench-accent-night)]">{baseGlyph}</th>
                    <th className="px-4 py-2 text-left">
                        <div className="flex items-center gap-2">
                            <span className="font-[var(--wbench-serif)] italic normal-case tracking-normal text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">{baseLabel}</span>
                            <CheckboxInput id='all_base'/>
                        </div>
                    </th>
                </tr>
                </thead>
                <tbody className="divide-y divide-[var(--wbench-rule)] dark:divide-[var(--wbench-rule-night)]">
                {props.rows.map((row, i) => {
                    const n = rowOffset + i + 1;
                    const nStr = n < 10 ? `0${n}` : String(n);
                    return (
                    <tr id={`simulator-row-${n}`}
                        key={rowOffset + i}
                        className="simulator-row group relative transition-colors duration-150 hover:bg-[var(--wbench-paper-deep)]/60 dark:hover:bg-[var(--wbench-paper-deep-night)]/50 cursor-pointer">
                        <td className="px-4 py-2 align-top hide_target relative">
                            <span className="ribbon-mark absolute left-0 top-0 bottom-0" aria-hidden="true"/>
                            <span className="target content resizeable_element block w-full break-words text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] font-[var(--wbench-serif)]">
                                <WordText
                                    text={row[0]}
                                    wordMap={props.targetWordMap}
                                    highlight={props.highlightWords && props.targetHighlightable}
                                    rowKey={props.rowKeys?.[i]}
                                    onWordProgress={props.onWordProgress}
                                    popupFontSize={props.popupFontSize}
                                    side={props.targetSide}
                                    explain={targetExplain}
                                />
                            </span>
                        </td>
                        <td className="px-2 py-2 bilingual-control-cell">
                            <div className="bilingual-control-inner bilingual-control-resizeable">
                                <CheckboxInput className="check_target cursor-pointer" checked={!!(props.checkedRows?.[n]?.target)} onChange={() => props.onToggleRow(n, 'target')}/>
                            </div>
                        </td>
                        <td className="px-2 py-2 bilingual-control-cell">
                            <div className="bilingual-control-inner bilingual-control-resizeable font-[var(--wbench-mono)] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] tabular-nums">
                                {nStr}
                            </div>
                        </td>
                        <td className="px-2 py-2 bilingual-control-cell">
                            <div className="bilingual-control-inner bilingual-control-resizeable">
                                <CheckboxInput className="check_base cursor-pointer" checked={!!(props.checkedRows?.[n]?.base)} onChange={() => props.onToggleRow(n, 'base')}/>
                            </div>
                        </td>
                        <td className="px-4 py-2 align-top hide_base">
                            <div className="flex w-full flex-col gap-1.5">
                                <span className="base content resizeable_element block w-full break-words text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] font-[var(--wbench-serif)]">
                                    <WordText
                                        text={row[1]}
                                        wordMap={props.baseWordMap}
                                        highlight={props.highlightWords && props.baseHighlightable}
                                        rowKey={props.rowKeys?.[i]}
                                        onWordProgress={props.onWordProgress}
                                        popupFontSize={props.popupFontSize}
                                        side={props.baseSide}
                                        explain={baseExplain}
                                    />
                                </span>
                                <div className="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity duration-150">
                                    <Button onClick={() => props.focusOnWorkplace()} color='dark' size="xs" outline>{t('bilinguals.open')}</Button>
                                    {props.canUseAi && (
                                        <Button onClick={() => props.ask(row)} color='green' size="xs">{t('bilinguals.ask')}</Button>
                                    )}
                                </div>
                            </div>
                        </td>
                    </tr>);
                })}
                </tbody>
            </table>
        </div>
    )
}
