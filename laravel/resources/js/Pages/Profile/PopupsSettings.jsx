import React, {useState} from 'react'
import {useI18n} from '../../i18n'
import {useUiSettingsAutosave} from '../../hooks/useUiSettingsAutosave'
import {PopupContent, popupContainerClass, DEFAULT_POPUP_FONT_SIZE} from '../../Components/WordPopup.jsx'
import {CheckboxRow, SectionHeader} from './ui.jsx'

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

// A fixture-style word family exercising every state the popup can render:
// a form of "melt" whose base-word section ("MELT — verb") carries two
// transcriptions ("+1 more…" toggle), 10 translations (8 shown + "+2 more…")
// and counted definitions/examples/etymology collapses — plus the popup's
// own adjective section ("MELTED — adjective") with zero translations, which
// shows the no-translations placeholder. A rank and a marked familiarity top
// it off.
const SAMPLE_DATA = {
    id: 1,
    word: 'melted',
    language_code: 'en',
    word_class: 'Verb',
    is_form: true,
    form_of: ['melt'],
    frequency: 2143,
    entries: [
        {
            id: 1,
            word: 'melt',
            word_class: 'Verb',
            transcriptions: [
                {value: 'melt', type: 'IPA'},
                {value: 'MELT', type: 'English Pronunciation'},
            ],
            definitions: [
                'To change from a solid to a liquid state under heat.',
                'To dissolve, blend, or merge — the crowd melted away.',
            ],
            translations: [
                {id: 1, word: 'таять', language_code: 'ru'},
                {id: 2, word: 'плавить', language_code: 'ru'},
                {id: 3, word: 'растворяться', language_code: 'ru'},
                {id: 4, word: 'плавиться', language_code: 'ru'},
                {id: 5, word: 'топить', language_code: 'ru'},
                {id: 6, word: 'растапливать', language_code: 'ru'},
                {id: 7, word: 'расплавлять', language_code: 'ru'},
                {id: 8, word: 'сливаться', language_code: 'ru'},
                {id: 9, word: 'смягчаться', language_code: 'ru'},
                {id: 10, word: 'растворить', language_code: 'ru'},
            ],
            examples: [
                'The snow melted in the sun.',
                'Her heart melted at the sight.',
                'The chocolate melted into the warm batter.',
            ],
            etymologies: [
                'From Old English meltan, "to dissolve into liquid".',
                'Cognate with Old Norse melt ("to digest").',
            ],
        },
        {
            id: 2,
            word: 'melted',
            word_class: 'Adjective',
            transcriptions: [],
            definitions: ['Changed into liquid form; no longer solid.'],
            translations: [],
            examples: [],
            etymologies: [],
        },
    ],
}

const SAMPLE_FAMILIARITY = 40
const SAMPLE_EXPLAIN = {rowKind: 'preview', rowId: 1, modelKey: 'preview', enabled: true, modelLabel: 'Sample model', followsAnswer: false}

export default function PopupsSettings({popupVisibility = null}) {
    const {t} = useI18n()
    const [sections, setSections] = useState(() => {
        const resolved = {}
        for (const key of POPUP_SECTIONS) {
            resolved[key] = popupVisibility?.[key] ?? true
        }
        return resolved
    })
    const [modelsOpen, setModelsOpen] = useState(false)

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
                    {/* Same structural class as the real portal wrapper
                        (WordPopup.jsx) so user styles/tests treat the preview
                        like the live popup — minus the portal's absolute/z-50
                        positioning, which stays portal-only. */}
                    <div
                        className={`word-popup ${popupContainerClass} mt-3`}
                        style={{width: 'min(560px, 100%)', fontSize: `${DEFAULT_POPUP_FONT_SIZE}px`}}
                        aria-label={t('profile.popups_preview_title')}
                    >
                        <PopupContent
                            surface="melted"
                            familiarity={SAMPLE_FAMILIARITY}
                            data={SAMPLE_DATA}
                            explainProps={{...SAMPLE_EXPLAIN, wordId: SAMPLE_DATA.id, surface: 'melted'}}
                            explainKey="preview"
                            sections={sections}
                            modelsOpen={modelsOpen}
                            onModelsOpenChange={setModelsOpen}
                            preview
                        />
                    </div>
                </div>
            </div>
        </section>
    )
}
