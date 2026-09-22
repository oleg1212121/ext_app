import React from 'react'
import {useForm} from '@inertiajs/react'
import {useI18n} from '../../i18n'
import {InputError, InputLabel, PrimaryButton, SavedMessage, SectionHeader, SelectInput} from './ui.jsx'

/**
 * Per-user AI model preferences. Both selects list the models available to
 * this user (provider enabled + user's API key + model enabled), grouped by
 * provider, valued by ai_models.id — the id the server resolves back to a
 * model at request time. An unset explanation model follows the answer
 * model. Without any API key the selects stay disabled and point at the
 * API keys section below in the same tab.
 */
export default function AiModels({aiModelChoices = {}, aiModelId = null, explanationModelId = null, hasAnyAiKey = false}) {
    const { t } = useI18n()
    const {data, setData, patch, processing, recentlySuccessful, errors} = useForm({
        ai_model_id: aiModelId ?? '',
        explanation_model_id: explanationModelId ?? '',
    })

    const submit = (e) => {
        e.preventDefault()
        patch('/profile/ai-models')
    }

    const toId = (value) => (value === '' ? null : Number(value))

    return (
        <section>
            <SectionHeader
                eyebrow={t('profile.section_eyebrow')}
                title={t('profile.ai_models_title')}
                description={t('profile.ai_models_description')}
            />

            <form onSubmit={submit} className="mt-8 space-y-6">
                <div>
                    <InputLabel htmlFor="ai_model_id">{t('profile.answer_model')}</InputLabel>
                    <SelectInput
                        id="ai_model_id"
                        value={data.ai_model_id}
                        onChange={(e) => setData('ai_model_id', toId(e.target.value))}
                        error={errors.ai_model_id}
                        disabled={!hasAnyAiKey}
                    >
                        <option value="">{t('profile.choose_answer_model')}</option>
                        {Object.entries(aiModelChoices).map(([providerName, choices]) => (
                            <optgroup key={providerName} label={providerName}>
                                {choices.map((choice) => (
                                    <option key={choice.id} value={choice.id}>
                                        {choice.label}
                                    </option>
                                ))}
                            </optgroup>
                        ))}
                    </SelectInput>
                    <InputError messages={errors.ai_model_id ? [errors.ai_model_id] : []}/>
                </div>

                <div>
                    <InputLabel htmlFor="explanation_model_id">{t('profile.explanation_model')}</InputLabel>
                    <SelectInput
                        id="explanation_model_id"
                        value={data.explanation_model_id}
                        onChange={(e) => setData('explanation_model_id', toId(e.target.value))}
                        error={errors.explanation_model_id}
                        disabled={!hasAnyAiKey}
                    >
                        <option value="">{t('profile.follows_answer_model')}</option>
                        {Object.entries(aiModelChoices).map(([providerName, choices]) => (
                            <optgroup key={providerName} label={providerName}>
                                {choices.map((choice) => (
                                    <option key={choice.id} value={choice.id}>
                                        {choice.label}
                                    </option>
                                ))}
                            </optgroup>
                        ))}
                    </SelectInput>
                    <InputError messages={errors.explanation_model_id ? [errors.explanation_model_id] : []}/>
                </div>

                {!hasAnyAiKey && (
                    <p className="font-serif italic text-sm text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70">
                        {t('profile.ai_no_key_hint')}
                    </p>
                )}

                <div className="flex items-center gap-4">
                    <PrimaryButton disabled={processing || !hasAnyAiKey}>{t('profile.save_changes')}</PrimaryButton>
                    <SavedMessage show={recentlySuccessful}/>
                </div>
            </form>
        </section>
    )
}
