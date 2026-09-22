import React from 'react'
import {useForm} from '@inertiajs/react'
import {useI18n} from '../../i18n'
import {InputError, InputLabel, PrimaryButton, SavedMessage, SectionHeader, SelectInput} from './ui.jsx'

export default function PreferencesForm({nativeLanguageId, interfaceLanguageId, languages = []}) {
    const { t } = useI18n()
    const {data, setData, patch, processing, recentlySuccessful, errors} = useForm({
        native_language_id: nativeLanguageId ?? '',
        interface_language_id: interfaceLanguageId ?? '',
    })

    const submit = (e) => {
        e.preventDefault()
        patch('/profile/settings')
    }

    const interfaceLanguages = languages.filter((language) => language.is_interface_enabled)

    const languageLabel = (language) => language.native_name ?? language.name

    return (
        <section>
            <SectionHeader
                eyebrow={t('profile.section_eyebrow')}
                title={t('profile.settings_title')}
                description={t('profile.settings_description')}
            />

            <form onSubmit={submit} className="mt-8 space-y-6">
                <div>
                    <InputLabel htmlFor="native_language_id">{t('profile.native_language')}</InputLabel>
                    <SelectInput
                        id="native_language_id"
                        value={data.native_language_id}
                        onChange={(e) => setData('native_language_id', Number(e.target.value))}
                        error={errors.native_language_id}
                    >
                        {languages.map((language) => (
                            <option key={language.id} value={language.id}>
                                {languageLabel(language)}
                            </option>
                        ))}
                    </SelectInput>
                    <InputError messages={errors.native_language_id ? [errors.native_language_id] : []}/>
                </div>

                <div>
                    <InputLabel htmlFor="interface_language_id">{t('profile.interface_language')}</InputLabel>
                    <SelectInput
                        id="interface_language_id"
                        value={data.interface_language_id}
                        onChange={(e) => setData('interface_language_id', e.target.value === '' ? null : Number(e.target.value))}
                        error={errors.interface_language_id}
                    >
                        <option value="">{t('profile.follows_native_language')}</option>
                        {interfaceLanguages.map((language) => (
                            <option key={language.id} value={language.id}>
                                {languageLabel(language)}
                            </option>
                        ))}
                    </SelectInput>
                    <InputError messages={errors.interface_language_id ? [errors.interface_language_id] : []}/>
                </div>

                <div className="flex items-center gap-4">
                    <PrimaryButton disabled={processing}>{t('profile.save_changes')}</PrimaryButton>
                    <SavedMessage show={recentlySuccessful}/>
                </div>
            </form>
        </section>
    )
}
