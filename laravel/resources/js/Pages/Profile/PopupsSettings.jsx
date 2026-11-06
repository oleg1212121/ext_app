import React, {useState} from 'react'
import {useI18n} from '../../i18n'
import {usePage} from '@inertiajs/react'
import {useUiSettingsAutosave} from '../../hooks/useUiSettingsAutosave'
import {isApprovedAdmin} from '../../lib/authUser'
import {WordCard} from '../../Components/WordCard.jsx'
import {ExplainPane} from '../../Components/ExplainPane.jsx'
import {DEFAULT_POPUP_FONT_SIZE} from '../../lib/popupStyle'
import {CheckboxRow, SectionHeader} from './ui.jsx'
import {SAMPLE_DATA, SAMPLE_EXPLAIN, SAMPLE_FAMILIARITY} from './sampleWordData'

// Every toggleable popup block; absent keys mean visible, both here and in
// the saved ui_settings.popup map (App\Support\PopupVisibility owns the
// server-side copy of this list).
const POPUP_SECTIONS = [
    'familiarity',
    'progress_actions',
    'form_of',
    'word_family',
    'frequency',
    'transcriptions',
    'definitions',
    'translations',
    'examples',
    'etymologies',
    'explanation',
]

// Display grouping only — each checkbox is independent.
const POPUP_GROUPS = [
    {titleKey: 'profile.popup_group_knowledge', keys: ['familiarity', 'progress_actions']},
    {titleKey: 'profile.popup_group_family', keys: ['form_of', 'word_family']},
    {titleKey: 'profile.popup_group_dictionary', keys: ['transcriptions', 'definitions', 'translations', 'examples', 'etymologies']},
    {titleKey: 'profile.popup_group_tabs', keys: ['explanation', 'frequency']},
]

export default function PopupsSettings({popupVisibility = null}) {
    const {t} = useI18n()
    const {props} = usePage()
    // Ask-AI renders for approved admins only (word-popup-overhaul ticket
    // 06) — the preview mirrors exactly what the viewer would see live:
    // the inert sparkles icon for an admin, none for anyone else. The gate
    // is the popup's own shared lib helper.
    const canAskAi = isApprovedAdmin(props.auth?.user)
    const [sections, setSections] = useState(() => {
        const resolved = {}
        for (const key of POPUP_SECTIONS) {
            resolved[key] = popupVisibility?.[key] ?? true
        }
        return resolved
    })
    const [modelsOpen, setModelsOpen] = useState(false)
    // The canned explain payload: the sample carries a sentence id, so the
    // toggle is live and its pane opens on the canned answer.
    const previewExplain = {...SAMPLE_EXPLAIN, wordId: SAMPLE_DATA.id, surface: 'melted'}

    useUiSettingsAutosave('popup', sections)

    const toggle = (key) => setSections((current) => ({...current, [key]: !current[key]}))

    return (
        <section>
            <SectionHeader
                eyebrow={t('profile.section_eyebrow')}
                title={t('profile.popups_title')}
                description={t('profile.popups_description')}
            />

            {/* Two columns at lg+: checkbox groups ~1/3 left, live preview
                ~2/3 right; below lg it stacks checkboxes-above-preview, the
                pre-2026-10 order. The preview card keeps its realistic 560px
                inline width inside the wider column. */}
            <div className="mt-8 lg:grid lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)] lg:gap-10">
                <div className="space-y-6">
                    {POPUP_GROUPS.map((group) => (
                        <div key={group.titleKey}>
                            <h3 className="text-[10px] font-medium uppercase tracking-[0.22em] text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/60">
                                {t(group.titleKey)}
                            </h3>
                            <div className="mt-1">
                                {group.keys.map((key) => (
                                    <CheckboxRow
                                        key={key}
                                        id={`popup-section-${key}`}
                                        checked={sections[key]}
                                        onChange={() => toggle(key)}
                                        label={t(`profile.popup_section_${key}`)}
                                    />
                                ))}
                            </div>
                        </div>
                    ))}
                </div>

                <div className="mt-10 lg:mt-0">
                    <h3 className="text-[10px] font-medium uppercase tracking-[0.22em] text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/60">
                        {t('profile.popups_preview_title')}
                    </h3>
                    {/* The POSITIONED WRAPPER of the preview (the real
                        portal's class, WordPopup.jsx, minus its absolute/
                        z-50): `relative` is what anchors the info popup
                        layer — WordCard renders it as the wrapper's
                        absolutely positioned child, hanging above the card
                        surface it renders below — so the preview's canned
                        explanation floats above the preview card exactly
                        like the live explanation above the real one. The
                        card surface itself (popupContainerClass) comes from
                        WordCard — the same one card the live popup mounts
                        (word-card ticket 02), fed canned state: the only
                        handler is the Models used popup's open change, so
                        the model reads every other capability as absent
                        (inert toolbar, grey unprobed Anki icon), and the
                        explain pane is the canned-answer preview (no
                        network). */}
                    <div
                        className="word-popup relative mt-3"
                        style={{width: 'min(560px, 100%)', fontSize: `${DEFAULT_POPUP_FONT_SIZE}px`}}
                        aria-label={t('profile.popups_preview_title')}
                    >
                        <WordCard
                            state={{
                                status: 'loaded',
                                surface: 'melted',
                                familiarity: SAMPLE_FAMILIARITY,
                                data: SAMPLE_DATA,
                                explainProps: previewExplain,
                                sections,
                                canAskAi,
                            }}
                            handlers={{onModelsOpenChange: setModelsOpen}}
                            modelsOpen={modelsOpen}
                            explainPane={<ExplainPane explain={previewExplain} explainKey="preview" preview/>}
                        />
                    </div>
                </div>
            </div>
        </section>
    )
}
