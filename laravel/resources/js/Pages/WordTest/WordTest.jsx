import {router} from '@inertiajs/react'
import {useEffect, useState} from 'react'
import Button from '../../Components/Forms/Button.jsx'
import CheckboxInput from '../../Components/Forms/CheckboxInput.jsx'
import {useI18n} from '../../i18n'
import Main from '../../Layouts/Main.jsx'
import {submitWordTest} from './api'

const selectClass = [
    'px-2.5 py-1.5 text-sm font-serif tracking-tight rounded-sm transition cursor-pointer',
    'bg-[var(--color-vellum-deep)] dark:bg-[var(--color-hairline-night)]/40',
    'text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]',
    'border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)]',
    'hover:border-[var(--color-vermilion)] dark:hover:border-[var(--color-vermilion-night)]',
    'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)]',
].join(' ')

export default function WordTest({languages = [], language = null, sample = null}) {
    const {t} = useI18n()
    const [knownIds, setKnownIds] = useState(() => new Set())
    const [submitting, setSubmitting] = useState(false)
    const [error, setError] = useState(null)
    const [result, setResult] = useState(null)

    // A fresh sample (page load or language switch) resets the answers —
    // Inertia reuses this component across visits, so props alone don't.
    useEffect(() => {
        setKnownIds(new Set())
        setResult(null)
        setError(null)
    }, [sample?.token])

    const toggle = (id) => {
        setKnownIds((prev) => {
            const next = new Set(prev)
            if (next.has(id)) {
                next.delete(id)
            } else {
                next.add(id)
            }
            return next
        })
    }

    const changeLanguage = (code) => {
        if (!code || code === language) return
        router.visit(`/word-test?lang=${code}`)
    }

    const retake = () => {
        router.reload()
    }

    const submit = async () => {
        if (submitting || !sample) return
        setSubmitting(true)
        setError(null)
        try {
            setResult(await submitWordTest(sample.token, [...knownIds]))
        } catch (e) {
            setError(e.message)
        } finally {
            setSubmitting(false)
        }
    }

    return (
        <div className="flex-1 min-h-0 overflow-y-auto bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)]">
            <div className="mx-auto flex max-w-2xl flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8">
                <header className="flex flex-col gap-4 border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] pb-4">
                    <p className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                        {t('wordtest.title')}
                    </p>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h1 className="font-serif text-2xl tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            {t('wordtest.title')}
                        </h1>
                        <select
                            className={selectClass}
                            value={language ?? ''}
                            onChange={(e) => changeLanguage(e.target.value)}
                            aria-label={t('wordtest.language')}
                        >
                            {languages.map((item) => (
                                <option key={item.code} value={item.code}>{item.name}</option>
                            ))}
                        </select>
                    </div>
                    <p className="font-serif text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                        {t('wordtest.intro')}
                    </p>
                </header>

                {language === null && (
                    <p className="font-serif text-base text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                        {t('wordtest.no_languages')}
                    </p>
                )}

                {language !== null && !sample && (
                    <p className="font-serif text-base text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                        {t('wordtest.no_data', {language: languages.find((item) => item.code === language)?.name ?? language})}
                    </p>
                )}

                {sample && result && (
                    <section className="flex flex-col items-center gap-3 rounded-sm border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper-deep)] dark:bg-[var(--wbench-paper-deep-night)] px-6 py-8">
                        <p className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {t('wordtest.score_label')}
                        </p>
                        <p className="font-serif text-4xl tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            {result.score.toLocaleString()} / {(20000).toLocaleString()}
                        </p>
                        <p className="font-serif text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {t('wordtest.marked', {count: result.marked})}
                        </p>
                        <Button color="green" size="sm" onClick={retake}>
                            {t('wordtest.new_sample')}
                        </Button>
                    </section>
                )}

                {sample && !result && (
                    <>
                        <div className="flex items-center justify-between">
                            <p className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                {t('wordtest.checklist', {checked: knownIds.size, total: sample.words.length})}
                            </p>
                            <Button color="green" size="sm" disabled={submitting} onClick={submit}>
                                {submitting ? t('wordtest.submitting') : t('wordtest.submit')}
                            </Button>
                        </div>

                        {error && (
                            <p className="font-serif text-sm text-[var(--color-vermilion)] dark:text-[var(--color-vermilion-night)]">
                                {t('wordtest.error')}
                            </p>
                        )}

                        <ul className="divide-y divide-[var(--wbench-rule)] dark:divide-[var(--wbench-rule-night)] border-y border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]">
                            {sample.words.map((entry) => (
                                <li key={entry.id}>
                                    <label
                                        htmlFor={`wordtest-word-${entry.id}`}
                                        className="flex cursor-pointer items-center gap-3 py-2.5"
                                    >
                                        <CheckboxInput
                                            id={`wordtest-word-${entry.id}`}
                                            checked={knownIds.has(entry.id)}
                                            onChange={() => toggle(entry.id)}
                                            aria-label={`${t('wordtest.known')}: ${entry.word}`}
                                        />
                                        <span className="font-serif text-base text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                                            {entry.word}
                                        </span>
                                    </label>
                                </li>
                            ))}
                        </ul>

                        <div className="flex justify-end">
                            <Button color="green" size="md" disabled={submitting} onClick={submit}>
                                {submitting ? t('wordtest.submitting') : t('wordtest.submit')}
                            </Button>
                        </div>
                    </>
                )}
            </div>
        </div>
    )
}

WordTest.layout = (page) => <Main>{page}</Main>;
