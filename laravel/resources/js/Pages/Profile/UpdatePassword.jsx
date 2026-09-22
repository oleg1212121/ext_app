import React from 'react'
import {useForm} from '@inertiajs/react'
import {useI18n} from '../../i18n'
import {InputError, InputLabel, PrimaryButton, SavedMessage, SectionHeader, TextInput} from './ui.jsx'

export default function UpdatePassword() {
    const { t } = useI18n()
    const {data, setData, put, processing, recentlySuccessful, errors, reset} = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    })

    const submit = (e) => {
        e.preventDefault()
        put('/password', {
            onFinish: () => reset('current_password', 'password', 'password_confirmation'),
        })
    }

    return (
        <section>
            <SectionHeader
                eyebrow={t('profile.section_eyebrow')}
                title={t('profile.password_title')}
                description={t('profile.password_description')}
            />

            <form onSubmit={submit} className="mt-8 space-y-6">
                <div>
                    <InputLabel htmlFor="update_password_current_password">{t('profile.current_password')}</InputLabel>
                    <TextInput
                        id="update_password_current_password"
                        type="password"
                        value={data.current_password}
                        onChange={(e) => setData('current_password', e.target.value)}
                        autoComplete="current-password"
                        error={errors.current_password}
                    />
                    <InputError messages={errors.current_password ? [errors.current_password] : []}/>
                </div>

                <div>
                    <InputLabel htmlFor="update_password_password">{t('profile.new_password')}</InputLabel>
                    <TextInput
                        id="update_password_password"
                        type="password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        autoComplete="new-password"
                        error={errors.password}
                    />
                    <InputError messages={errors.password ? [errors.password] : []}/>
                </div>

                <div>
                    <InputLabel htmlFor="update_password_password_confirmation">{t('profile.confirm_password')}</InputLabel>
                    <TextInput
                        id="update_password_password_confirmation"
                        type="password"
                        value={data.password_confirmation}
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                        autoComplete="new-password"
                        error={errors.password_confirmation}
                    />
                    <InputError messages={errors.password_confirmation ? [errors.password_confirmation] : []}/>
                </div>

                <div className="flex items-center gap-4">
                    <PrimaryButton disabled={processing}>{t('profile.save_changes')}</PrimaryButton>
                    <SavedMessage show={recentlySuccessful}/>
                </div>
            </form>
        </section>
    )
}
