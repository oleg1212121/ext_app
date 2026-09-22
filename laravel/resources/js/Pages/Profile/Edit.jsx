import React, {useState} from 'react'
import {Head} from '@inertiajs/react'
import Main from '../../Layouts/Main.jsx'
import {useI18n} from '../../i18n'
import ProfileInformation from './ProfileInformation.jsx'
import PreferencesForm from './PreferencesForm.jsx'
import UpdatePassword from './UpdatePassword.jsx'
import ApiKeys from './ApiKeys.jsx'
import AiModels from './AiModels.jsx'
import DeleteAccount from './DeleteAccount.jsx'
import {Card} from './ui.jsx'

const TABS = [
    {id: 'account', labelKey: 'profile.tab_account'},
    {id: 'preferences', labelKey: 'profile.tab_preferences'},
    {id: 'ai', labelKey: 'profile.tab_ai'},
    {id: 'danger', labelKey: 'profile.tab_danger'},
]

const VALID_TAB_IDS = TABS.map((tab) => tab.id)

function tabClass(isActive) {
    return [
        'relative px-1 py-2 font-serif text-sm transition-colors rounded-sm',
        isActive
            ? 'text-[var(--color-vermilion)] dark:text-[var(--color-vermilion-night)]'
            : 'text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/60 hover:text-[var(--color-ink)] dark:hover:text-[var(--color-vellum-night)]',
        'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)] dark:focus-visible:ring-[var(--color-vermilion-night)]',
    ].join(' ')
}

function Underline({isActive}) {
    return (
        <span
            aria-hidden="true"
            className={[
                'absolute inset-x-0 -bottom-px h-0.5 origin-left bg-[var(--color-vermilion)] dark:bg-[var(--color-vermilion-night)] transition-transform duration-200',
                isActive ? 'scale-x-100' : 'scale-x-0',
            ].join(' ')}
        />
    )
}

// Unmount-style tab panel (matches the Crossword right panel): only the
// active tab's forms stay mounted, exactly like a page reload today.
function TabPanel({active, children}) {
    if (!active) return null
    return <div className="mt-8 space-y-8">{children}</div>
}

// Deep links like /profile?tab=ai open the right tab; switching tabs
// replaces the query string so a reload keeps the position.
const initialTab = () => {
    const fromUrl = new URLSearchParams(window.location.search).get('tab')
    return VALID_TAB_IDS.includes(fromUrl) ? fromUrl : 'account'
}

export default function Edit({
    user,
    apiKeyProviders = [],
    nativeLanguageId = null,
    interfaceLanguageId = null,
    languages = [],
    aiModelChoices = {},
    aiModelId = null,
    explanationModelId = null,
}) {
    const { t } = useI18n()
    const [activeTab, setActiveTab] = useState(initialTab)

    const changeTab = (id) => {
        setActiveTab(id)
        window.history.replaceState(null, '', `/profile?tab=${id}`)
    }

    const hasAnyAiKey = apiKeyProviders.some((provider) => provider.has_key)

    return (
        <>
            <Head title={t('profile.profile_title')}/>

            <div className="py-10 sm:py-14 overflow-y-auto flex-1">
                <div className="px-4 sm:px-6 lg:px-10 max-w-3xl mx-auto">
                    <div className="flex flex-col gap-1 mb-6">
                        <span className="font-serif italic text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)] text-xs tracking-[0.22em] uppercase">
                            {t('profile.account_eyebrow')}
                        </span>
                        <h1 className="font-serif text-2xl sm:text-3xl tracking-tight text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]">
                            {t('profile.profile_title')}
                        </h1>
                    </div>

                    <div
                        role="tablist"
                        aria-label={t('profile.profile_title')}
                        className="flex gap-5 border-b border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)]"
                    >
                        {TABS.map((tab) => (
                            <button
                                key={tab.id}
                                type="button"
                                role="tab"
                                aria-selected={activeTab === tab.id}
                                aria-pressed={activeTab === tab.id}
                                onClick={() => changeTab(tab.id)}
                                className={tabClass(activeTab === tab.id)}
                            >
                                {t(tab.labelKey)}
                                <Underline isActive={activeTab === tab.id}/>
                            </button>
                        ))}
                    </div>

                    <TabPanel active={activeTab === 'account'}>
                        <Card>
                            <ProfileInformation user={user}/>
                        </Card>
                        <Card>
                            <UpdatePassword/>
                        </Card>
                    </TabPanel>

                    <TabPanel active={activeTab === 'preferences'}>
                        <Card>
                            <PreferencesForm nativeLanguageId={nativeLanguageId} interfaceLanguageId={interfaceLanguageId} languages={languages}/>
                        </Card>
                    </TabPanel>

                    <TabPanel active={activeTab === 'ai'}>
                        <Card>
                            <AiModels
                                aiModelChoices={aiModelChoices}
                                aiModelId={aiModelId}
                                explanationModelId={explanationModelId}
                                hasAnyAiKey={hasAnyAiKey}
                            />
                        </Card>
                        <Card>
                            <ApiKeys providers={apiKeyProviders}/>
                        </Card>
                    </TabPanel>

                    <TabPanel active={activeTab === 'danger'}>
                        <Card>
                            <DeleteAccount/>
                        </Card>
                    </TabPanel>
                </div>
            </div>
        </>
    )
}

Edit.layout = (page) => <Main children={page}/>
