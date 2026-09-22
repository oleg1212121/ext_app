import React, {useState} from 'react'
import {useForm} from '@inertiajs/react'
import {useI18n} from '../../i18n'
import {DangerButton, InputError, InputLabel, Modal, SecondaryButton, SectionHeader, TextInput} from './ui.jsx'

export default function DeleteAccount() {
    const { t } = useI18n()
    const [showModal, setShowModal] = useState(false)
    const {data, setData, delete: destroy, processing, errors, reset} = useForm({
        password: '',
    })

    const submit = (e) => {
        e.preventDefault()
        destroy('/profile', {
            onFinish: () => {
                reset('password')
                setShowModal(false)
            },
        })
    }

    return (
        <section className="space-y-6">
            <SectionHeader
                eyebrow={t('profile.danger_eyebrow')}
                title={t('profile.delete_account_title')}
                description={t('profile.delete_account_description')}
                danger
            />

            <DangerButton onClick={() => setShowModal(true)}>
                {t('profile.delete_account')}
            </DangerButton>

            <Modal show={showModal} onClose={() => setShowModal(false)}>
                <form onSubmit={submit}>
                    <h2 className="font-serif text-xl sm:text-2xl tracking-tight text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]">
                        {t('profile.delete_account_confirm_title')}
                    </h2>

                    <p className="mt-2 max-w-md font-serif italic text-sm text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70">
                        {t('profile.delete_account_confirm_description')}
                    </p>

                    <div className="mt-6">
                        <InputLabel htmlFor="password">{t('profile.password')}</InputLabel>
                        <TextInput
                            id="password"
                            type="password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            placeholder={t('profile.password')}
                            className="w-3/4"
                            error={errors.password}
                        />
                        <InputError messages={errors.password ? [errors.password] : []}/>
                    </div>

                    <div className="mt-6 flex justify-end gap-3">
                        <SecondaryButton onClick={() => setShowModal(false)}>
                            {t('profile.cancel')}
                        </SecondaryButton>
                        <DangerButton type="submit" disabled={processing}>
                            {t('profile.delete_account')}
                        </DangerButton>
                    </div>
                </form>
            </Modal>
        </section>
    )
}
