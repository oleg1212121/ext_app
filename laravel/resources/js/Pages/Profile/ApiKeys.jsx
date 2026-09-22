import React from 'react'
import {useForm} from '@inertiajs/react'
import {useI18n} from '../../i18n'
import {InputError, InputLabel, PrimaryButton, SaveIcon, SectionHeader, TextInput, TrashIcon} from './ui.jsx'

function RemoveKeyButton({onClick, disabled}) {
    const { t } = useI18n()
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            aria-label={t('profile.remove_key')}
            title={t('profile.remove_key')}
            className={[
                'inline-flex items-center justify-center rounded-sm p-2 transition-colors',
                'text-[var(--color-ink-soft)]/70 dark:text-[var(--color-vellum-night)]/60',
                'hover:text-[var(--color-vermilion)] hover:bg-[var(--color-vermilion)]/10',
                'dark:hover:text-[var(--color-vermilion-night)] dark:hover:bg-[var(--color-vermilion-night)]/10',
                'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)] dark:focus-visible:ring-[var(--color-vermilion-night)]',
                'disabled:opacity-50 disabled:pointer-events-none',
            ].join(' ')}
        >
            <TrashIcon/>
        </button>
    )
}

function ProviderKeyRow({provider}) {
    const { t } = useI18n()
    const {data, setData, post, processing, errors, reset, delete: destroy} = useForm({
        provider: provider.key,
        api_key: '',
    })

    const submit = (e) => {
        e.preventDefault()
        post('/profile/api-keys', {
            preserveScroll: true,
            onSuccess: () => reset('api_key'),
        })
    }

    const remove = () => {
        destroy('/profile/api-keys/' + provider.key, {preserveScroll: true})
    }

    if (provider.has_key) {
        return (
            <div>
                <InputLabel htmlFor={`stored-key-${provider.key}`}>{provider.name}</InputLabel>
                <div className="mt-1 flex items-center justify-between gap-4">
                    <p className="inline-flex flex-wrap items-center gap-x-2 rounded-sm bg-[var(--color-verdigris)]/10 px-2.5 py-1 font-serif italic text-sm text-[var(--color-verdigris)] dark:bg-[var(--color-verdigris-night)]/10 dark:text-[var(--color-verdigris-night)]">
                        <SaveIcon/>
                        <span>
                            {t('profile.current_key')} <code className="not-italic">{provider.masked_key}</code>
                        </span>
                    </p>
                    <RemoveKeyButton onClick={remove} disabled={processing}/>
                </div>
            </div>
        )
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-2 sm:flex-row sm:items-end sm:gap-4">
            <div className="flex-1">
                <InputLabel htmlFor={`api-key-${provider.key}`}>{provider.name}</InputLabel>
                <TextInput
                    id={`api-key-${provider.key}`}
                    type="password"
                    value={data.api_key}
                    onChange={(e) => setData('api_key', e.target.value)}
                    placeholder={t('profile.paste_api_key')}
                    autoComplete="off"
                    error={errors.api_key}
                />
                <InputError messages={errors.api_key ? [errors.api_key] : []}/>
            </div>
            <div className="flex items-center gap-3">
                <PrimaryButton disabled={processing || data.api_key === ''}>
                    <span className="flex items-center gap-2">
                        <SaveIcon/>
                        {t('profile.save')}
                    </span>
                </PrimaryButton>
            </div>
        </form>
    )
}

export default function ApiKeys({providers = []}) {
    const { t } = useI18n()
    return (
        <section>
            <SectionHeader
                eyebrow={t('profile.section_eyebrow')}
                title={t('profile.api_keys_title')}
                description={t('profile.api_keys_description')}
            />

            <div className="mt-8 space-y-6">
                {providers.map((provider) => (
                    <ProviderKeyRow key={provider.key} provider={provider}/>
                ))}
            </div>
        </section>
    )
}
