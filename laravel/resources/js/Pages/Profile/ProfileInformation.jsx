import React from 'react'
import {useForm} from '@inertiajs/react'
import {useI18n} from '../../i18n'
import {InputError, InputLabel, PrimaryButton, SavedMessage, SectionHeader, TextInput} from './ui.jsx'

export default function ProfileInformation({user}) {
    const { t } = useI18n()
    const {data, setData, patch, processing, recentlySuccessful, errors} = useForm({
        name: user.name,
        email: user.email,
    })

    const submit = (e) => {
        e.preventDefault()
        patch('/profile')
    }

    return (
        <section>
            <SectionHeader
                eyebrow={t('profile.section_eyebrow')}
                title={t('profile.profile_information_title')}
                description={t('profile.profile_information_description')}
            />

            <form onSubmit={submit} className="mt-8 space-y-6">
                <div>
                    <InputLabel htmlFor="name">{t('profile.name')}</InputLabel>
                    <TextInput
                        id="name"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        required
                        autoFocus
                        autoComplete="name"
                        error={errors.name}
                    />
                    <InputError messages={errors.name ? [errors.name] : []}/>
                </div>

                <div>
                    <InputLabel htmlFor="email">{t('profile.email')}</InputLabel>
                    <TextInput
                        id="email"
                        type="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        required
                        autoComplete="username"
                        error={errors.email}
                    />
                    <InputError messages={errors.email ? [errors.email] : []}/>
                </div>

                <div className="flex items-center gap-4">
                    <PrimaryButton disabled={processing}>{t('profile.save_changes')}</PrimaryButton>
                    <SavedMessage show={recentlySuccessful}/>
                </div>
            </form>
        </section>
    )
}
