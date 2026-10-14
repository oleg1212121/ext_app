import React, {useRef, useState} from 'react'
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
import {ANKI_CARD_NAME, ANKI_FIELDS, buildNoteFields, generateCardTemplate, renderCardHtml} from '../../lib/ankiTemplate'
import {AnkiNoteTypeMismatchError, ensureAnkiTarget, sendAnkiNote} from '../../lib/ankiSync'
import {SAMPLE_DATA, SAMPLE_FAMILIARITY, SAMPLE_TAGS} from './sampleWordData'
import {CheckboxRow, InputLabel, SecondaryButton, SectionHeader, TextInput} from './ui.jsx'

// The profile's Anki tab: connection settings (AnkiConnect URL + port, target
// deck) autosaved into ui_settings.anki with a browser-side "Test connection"
// probe — AnkiConnect lives on the user's desktop, so the server can never
// reach it — plus the card-content side: twelve field toggles and a live
// Front/Back preview of the real generated template (lib/ankiTemplate.js),
// and the write path into Anki: template sync and a duplicate-checked sample
// card (lib/ankiSync.js). Everything except the Anki actions works offline;
// no Anki call is ever made from PHP (spec).

// Display grouping only — each checkbox is independent.
const FIELD_GROUPS = [
    {titleKey: 'profile.anki_field_group_word', keys: ['word', 'transcription', 'part_of_speech', 'frequency', 'language']},
    {titleKey: 'profile.anki_field_group_dictionary', keys: ['definitions', 'translations', 'examples', 'etymology', 'word_family']},
    {titleKey: 'profile.anki_field_group_tracking', keys: ['tags', 'familiarity']},
]

const initialFields = (saved) => {
    const resolved = {}
    for (const {key} of ANKI_FIELDS) {
        // Absent key = on, the Popups tab's convention.
        resolved[key] = saved?.fields?.[key] ?? true
    }
    return resolved
}

// The connection-hint failure panel, shared by both write surfaces (Test
// connection and template sync / sample card): the ordered fix list with the
// optional inline "Request permission" button — `onPermission` is each
// surface's own grant-and-retry handler.
function ConnectionHint({settings, testId, onPermission = null}) {
    const {t} = useI18n()

    return (
        <div
            data-testid={testId}
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
                    {onPermission && (
                        <button
                            type="button"
                            onClick={onPermission}
                            className="font-medium text-[var(--color-vermilion)] underline underline-offset-2 dark:text-[var(--color-vermilion-night)]"
                        >
                            {t('profile.anki_request_permission')}
                        </button>
                    )}
                </li>
                <li>
                    {t('profile.anki_fix_cors', {origin: window.location.origin})}
                </li>
            </ol>
        </div>
    )
}

// AnkiConnect's requestPermission shows the origin-approval popup inside
// Anki; anything short of "granted" (a dismissed popup included) is a
// failure carrying the permission answer. Shared by the test probe and the
// sync failure's grant-and-retry.
const grantPermission = async (config) => {
    const result = await requestPermission(config)
    if (result?.permission !== 'granted') {
        throw new AnkiConnectError('anki', String(result?.permission ?? 'permission denied'))
    }
    return result
}

// One state object so every autosave PATCH carries the WHOLE anki section —
// the endpoint replaces the section wholesale, so a fields-only save would
// wipe the connection settings (and vice versa).
const initialState = (saved) => ({
    url: saved?.url ?? ANKI_DEFAULT_URL,
    port: saved?.port ?? ANKI_DEFAULT_PORT,
    deck: saved?.deck ?? ANKI_DEFAULT_DECK,
    fields: initialFields(saved),
})

// `nativeLanguage` is the user's native language CODE (the shared auth prop,
// not the settings id) — buildNoteFields compares it against the payload
// translations' language_code to narrow the Translations field, falling back
// to all when none match.
export default function AnkiSettings({ankiSettings = null, nativeLanguage = null}) {
    const {t} = useI18n()
    const [settings, setSettings] = useState(() => initialState(ankiSettings))
    const [test, setTest] = useState({status: 'idle'})
    const [sync, setSync] = useState({status: 'idle'})
    const [face, setFace] = useState('front')
    const [copied, setCopied] = useState(false)
    const lastAction = useRef(null)

    useUiSettingsAutosave('anki', settings)

    const setUrl = (value) => setSettings((current) => ({...current, url: value.trim()}))
    const setDeck = (value) => setSettings((current) => ({...current, deck: value.trim()}))
    const setPort = (value) => {
        const parsed = Number.parseInt(value, 10)
        // Empty/invalid input parks the port at null (passes the server's
        // nullable rule); a reload resolves it back to the default.
        setSettings((current) => ({...current, port: Number.isNaN(parsed) ? null : parsed}))
    }
    const toggleField = (key) => setSettings((current) => ({
        ...current,
        fields: {...current.fields, [key]: !current.fields[key]},
    }))

    // The sample note built from the shared fixture (the same fixture the
    // Popups tab previews with): one construction feeding both the live
    // template preview and the "Send sample card" action.
    const sampleNoteFields = buildNoteFields(SAMPLE_DATA, {
        familiarity: SAMPLE_FAMILIARITY,
        tags: SAMPLE_TAGS,
        nativeLanguage,
    })

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
    const runPermission = () => probe(async () => (await grantPermission(settings)).version)

    // The tab's write path into Anki. `settings` doubles as the sync config
    // (url/port/deck/fields) that lib/ankiSync.js acts on. The sync button
    // always forces a fresh ensure pass — that is what re-applies the
    // toggled-off hide classes to all existing cards; the sample card goes
    // through the memoized ensure + duplicate-checked add.
    const runAction = (action) => {
        lastAction.current = action
        setSync({status: 'running'})
        action().then(
            (status) => setSync({status}),
            (error) => setSync({status: 'failed', error}),
        )
    }

    const runSync = () => runAction(async () => {
        await ensureAnkiTarget(settings, {force: true})
        return 'synced'
    })

    const runSample = () => runAction(async () => {
        const result = await sendAnkiNote(settings, sampleNoteFields, {tags: SAMPLE_TAGS, word: SAMPLE_DATA.word})
        return result.added ? 'sent' : 'duplicate'
    })

    // The sync failure panel's permission step: request the origin approval,
    // then replay the blocked action, so granting CORS permission carries the
    // user from failure to done in one click.
    const retryWithPermission = () => {
        const blocked = lastAction.current
        runAction(async () => {
            await grantPermission(settings)
            return blocked()
        })
    }

    // The generated template + the sample note built above: the preview
    // renders what Anki renders at review time, and the copy buttons hand
    // out the exact artifacts the template sync pushes.
    const template = generateCardTemplate(settings.fields)
    const previewDoc = (side) => [
        '<!doctype html><html><head><meta charset="utf-8">',
        `<style>${template.css}</style></head>`,
        `<body class="card">${renderCardHtml(template[side], sampleNoteFields)}</body></html>`,
    ].join('')

    const copy = async (text) => {
        try {
            await navigator.clipboard.writeText(text)
            setCopied(true)
            setTimeout(() => setCopied(false), 2000)
        } catch (error) {
            console.warn('clipboard write failed', error)
        }
    }

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
                    <ConnectionHint settings={settings} testId="anki-test-failed" onPermission={runPermission}/>
                )}
            </div>

            {/* Card content: two columns at lg+ mirroring the Popups tab —
                field toggles ~1/3 left, live template preview ~2/3 right. */}
            <div className="mt-12 lg:grid lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)] lg:gap-10">
                <div>
                    <h2 className="font-serif text-lg sm:text-xl tracking-tight text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]">
                        {t('profile.anki_card_title')}
                    </h2>
                    <p className="mt-2 max-w-md font-serif italic text-sm text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70">
                        {t('profile.anki_card_description')}
                    </p>

                    <div className="mt-6 space-y-6">
                        {FIELD_GROUPS.map((group) => (
                            <div key={group.titleKey}>
                                <h3 className="text-[10px] font-medium uppercase tracking-[0.22em] text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/60">
                                    {t(group.titleKey)}
                                </h3>
                                <div className="mt-1">
                                    {group.keys.map((key) => (
                                        <CheckboxRow
                                            key={key}
                                            id={`anki-field-${key}`}
                                            checked={settings.fields[key]}
                                            onChange={() => toggleField(key)}
                                            label={t(`profile.anki_field_${key}`)}
                                        />
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                <div className="mt-10 lg:mt-0">
                    <h3 className="text-[10px] font-medium uppercase tracking-[0.22em] text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/60">
                        {t('profile.anki_template_preview_title')}
                    </h3>

                    <div className="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2">
                        <div role="tablist" aria-label={t('profile.anki_template_preview_title')} className="flex gap-4">
                            {['front', 'back'].map((side) => (
                                <button
                                    key={side}
                                    type="button"
                                    role="tab"
                                    aria-selected={face === side}
                                    onClick={() => setFace(side)}
                                    className={[
                                        'px-1 py-0.5 font-serif text-sm rounded-sm transition-colors',
                                        face === side
                                            ? 'text-[var(--color-vermilion)] dark:text-[var(--color-vermilion-night)] underline underline-offset-4'
                                            : 'text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/60 hover:text-[var(--color-ink)] dark:hover:text-[var(--color-vellum-night)]',
                                    ].join(' ')}
                                >
                                    {t(side === 'front' ? 'profile.anki_template_front' : 'profile.anki_template_back')}
                                </button>
                            ))}
                        </div>

                        <SecondaryButton onClick={() => copy(template[face])} type="button">
                            {t('profile.anki_copy_html')}
                        </SecondaryButton>
                        <SecondaryButton onClick={() => copy(template.css)} type="button">
                            {t('profile.anki_copy_css')}
                        </SecondaryButton>
                        {copied && (
                            <span className="font-serif italic text-sm text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)]">
                                {t('profile.anki_copied')}
                            </span>
                        )}
                    </div>

                    {/* The exact generated artifact: generated CSS + HTML with
                        the sample values substituted for {{Field}}, in a
                        sandboxed frame so the template's styling can't leak
                        into (or inherit from) the app. */}
                    <iframe
                        title={t('profile.anki_template_preview_title')}
                        sandbox=""
                        className="mt-3 h-96 w-full rounded-sm border border-[var(--color-hairline)] bg-white dark:border-[var(--color-hairline-night)]"
                        srcDoc={previewDoc(face)}
                    />
                </div>
            </div>

            {/* Template sync + sample card: the tab's write path into Anki.
                Both actions run entirely from the browser (spec) and reuse the
                generated artifacts shown above; failures render by the error's
                kind — note-type mismatch, Anki's own error verbatim, and the
                connection hint for everything unreachable. */}
            <div className="mt-12 max-w-xl">
                <h2 className="font-serif text-lg sm:text-xl tracking-tight text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]">
                    {t('profile.anki_sync_title')}
                </h2>
                <p className="mt-2 font-serif italic text-sm text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70">
                    {t('profile.anki_sync_description')}
                </p>

                <div className="mt-6 flex flex-wrap items-center gap-4">
                    <SecondaryButton onClick={runSync} type="button" disabled={sync.status === 'running'}>
                        {sync.status === 'running' ? t('profile.anki_sync_running') : t('profile.anki_sync_template')}
                    </SecondaryButton>
                    <SecondaryButton onClick={runSample} type="button" disabled={sync.status === 'running'}>
                        {t('profile.anki_sync_sample')}
                    </SecondaryButton>
                </div>

                {sync.status === 'synced' && (
                    <p
                        data-testid="anki-sync-ok"
                        className="mt-4 font-serif italic text-sm text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)]"
                    >
                        {t('profile.anki_sync_ok', {deck: settings.deck})}
                    </p>
                )}
                {sync.status === 'sent' && (
                    <p
                        data-testid="anki-sample-ok"
                        className="mt-4 font-serif italic text-sm text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)]"
                    >
                        {t('profile.anki_sample_ok', {deck: settings.deck})}
                    </p>
                )}
                {sync.status === 'duplicate' && (
                    <p
                        data-testid="anki-sample-duplicate"
                        className="mt-4 font-serif italic text-sm text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70"
                    >
                        {t('profile.anki_sample_duplicate')}
                    </p>
                )}

                {sync.status === 'failed' && (
                    sync.error instanceof AnkiNoteTypeMismatchError ? (
                        <div
                            data-testid="anki-sync-mismatch"
                            className="mt-4 rounded-sm border border-[var(--color-vermilion)] dark:border-[var(--color-vermilion-night)] p-4"
                        >
                            <p className="text-sm text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]">
                                {sync.error.kind === 'card'
                                    ? t('profile.anki_mismatch_card', {
                                        card: ANKI_CARD_NAME,
                                        found: sync.error.found.join(', '),
                                    })
                                    : t('profile.anki_mismatch_fields', {
                                        expected: sync.error.expected.join(', '),
                                        found: sync.error.found.join(', '),
                                    })}
                            </p>
                        </div>
                    ) : sync.error instanceof AnkiConnectError && sync.error.kind === 'anki' ? (
                        // AnkiConnect answered with an error of its own (e.g. an
                        // addNote duplicate-race): the endpoint was reached, so
                        // the connection steps would mislead — show the message.
                        <div
                            data-testid="anki-sync-anki-error"
                            className="mt-4 rounded-sm border border-[var(--color-vermilion)] dark:border-[var(--color-vermilion-night)] p-4"
                        >
                            <p className="text-sm text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]">
                                {t('profile.anki_anki_error', {message: sync.error.message})}
                            </p>
                        </div>
                    ) : (
                        <div className="mt-4">
                            <ConnectionHint
                                settings={settings}
                                testId="anki-sync-failed"
                                onPermission={retryWithPermission}
                            />
                        </div>
                    )
                )}
            </div>
        </section>
    )
}
