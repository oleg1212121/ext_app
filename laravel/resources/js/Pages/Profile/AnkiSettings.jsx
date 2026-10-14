import React, {useState} from 'react'
import {useI18n} from '../../i18n'
import {useUiSettingsAutosave} from '../../hooks/useUiSettingsAutosave'
import {
    ANKI_DEFAULT_DECK,
    ANKI_DEFAULT_PORT,
    ANKI_DEFAULT_URL,
    AnkiConnectError,
    ankiConnectEndpoint,
    ankiConnectVersion,
    requestPermission,
} from '../../lib/ankiConnect'
import {InputLabel, SecondaryButton, SectionHeader, TextInput} from './ui.jsx'

// The profile's Anki tab: connection settings (AnkiConnect URL + port, target
// deck) autosaved into ui_settings.anki, plus a "Test connection" probe that
// runs in the browser — AnkiConnect lives on the user's desktop, so the
// server can never reach it (spec: no PHP-side Anki calls, ever). Card
// fields/template and the sample card arrive with the later Anki tickets and
// share this wide layout.

const initialState = (saved) => ({
    url: saved?.url ?? ANKI_DEFAULT_URL,
    port: saved?.port ?? ANKI_DEFAULT_PORT,
    deck: saved?.deck ?? ANKI_DEFAULT_DECK,
})

export default function AnkiSettings({ankiSettings = null}) {
    const {t} = useI18n()
    const [settings, setSettings] = useState(() => initialState(ankiSettings))
    const [test, setTest] = useState({status: 'idle'})

    useUiSettingsAutosave('anki', settings)

    const setUrl = (value) => setSettings((current) => ({...current, url: value.trim()}))
    const setDeck = (value) => setSettings((current) => ({...current, deck: value.trim()}))
    const setPort = (value) => {
        const parsed = Number.parseInt(value, 10)
        // Empty/invalid input parks the port at null (passes the server's
        // nullable rule); a reload resolves it back to the default.
        setSettings((current) => ({...current, port: Number.isNaN(parsed) ? null : parsed}))
    }

    const probe = async (call) => {
        setTest({status: 'testing'})
        try {
            setTest({status: 'ok', version: await call()})
        } catch (error) {
            setTest({status: 'failed', error})
        }
    }

    const runTest = () => probe(() => ankiConnectVersion(settings))

    // AnkiConnect's requestPermission triggers the approval popup inside
    // Anki for origins not yet on webCorsOriginList; approval turns the
    // failure panel into a success message without touching the config.
    const runPermission = () => probe(async () => {
        const result = await requestPermission(settings)
        if (result?.permission !== 'granted') {
            throw new AnkiConnectError('anki', String(result?.permission ?? 'permission denied'))
        }
        return result.version
    })

    return (
        <section>
            <SectionHeader
                eyebrow={t('profile.section_eyebrow')}
                title={t('profile.anki_title')}
                description={t('profile.anki_description')}
            />

            <div className="mt-8 max-w-xl space-y-4">
                <div>
                    <InputLabel htmlFor="anki-url">{t('profile.anki_url')}</InputLabel>
                    <TextInput
                        id="anki-url"
                        type="text"
                        value={settings.url ?? ''}
                        onChange={(event) => setUrl(event.target.value)}
                        placeholder={ANKI_DEFAULT_URL}
                        autoComplete="off"
                        spellCheck={false}
                    />
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <InputLabel htmlFor="anki-port">{t('profile.anki_port')}</InputLabel>
                        <TextInput
                            id="anki-port"
                            type="number"
                            min="1"
                            max="65535"
                            value={settings.port ?? ''}
                            onChange={(event) => setPort(event.target.value)}
                            placeholder={String(ANKI_DEFAULT_PORT)}
                            autoComplete="off"
                        />
                    </div>
                    <div>
                        <InputLabel htmlFor="anki-deck">{t('profile.anki_deck')}</InputLabel>
                        <TextInput
                            id="anki-deck"
                            type="text"
                            value={settings.deck ?? ''}
                            onChange={(event) => setDeck(event.target.value)}
                            placeholder={ANKI_DEFAULT_DECK}
                            autoComplete="off"
                            spellCheck={false}
                        />
                    </div>
                </div>

                <div className="flex items-center gap-4 pt-2">
                    <SecondaryButton onClick={runTest} type="button">
                        {test.status === 'testing' ? t('profile.anki_testing') : t('profile.anki_test_connection')}
                    </SecondaryButton>
                    {test.status === 'ok' && (
                        <p
                            data-testid="anki-test-ok"
                            className="font-serif italic text-sm text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)]"
                        >
                            {t('profile.anki_test_ok', {version: test.version})}
                        </p>
                    )}
                </div>

                {test.status === 'failed' && (
                    <div
                        data-testid="anki-test-failed"
                        className="rounded-sm border border-[var(--color-vermilion)] dark:border-[var(--color-vermilion-night)] p-4"
                    >
                        <p className="text-sm text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]">
                            {t('profile.anki_test_failed', {endpoint: ankiConnectEndpoint(settings)})}
                        </p>
                        <p className="mt-2 text-sm text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70">
                            {t('profile.anki_fix_intro')}
                        </p>
                        <ol className="mt-2 list-decimal space-y-2 pl-5 text-sm text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]">
                            <li>{t('profile.anki_fix_running')}</li>
                            <li>
                                {t('profile.anki_fix_permission')}{' '}
                                <button
                                    type="button"
                                    onClick={runPermission}
                                    className="font-medium text-[var(--color-vermilion)] underline underline-offset-2 dark:text-[var(--color-vermilion-night)]"
                                >
                                    {t('profile.anki_request_permission')}
                                </button>
                            </li>
                            <li>
                                {t('profile.anki_fix_cors', {origin: window.location.origin})}
                            </li>
                        </ol>
                    </div>
                )}
            </div>
        </section>
    )
}
