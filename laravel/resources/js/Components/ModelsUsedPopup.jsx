import {useEffect} from 'react';
import {createPortal} from 'react-dom';
import {Link} from '@inertiajs/react';
import {useI18n} from '../i18n';

/**
 * The robot-with-question-mark icon that opens the Models used popup from
 * the AI Response panel header and the Word popup's Explanation tab.
 */
export function RobotHelpIcon({className = 'h-4 w-4'}) {
    return (
        <svg
            className={className}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.75"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            {/* robot head */}
            <rect x="2.5" y="9" width="10.5" height="9.5" rx="2.25"/>
            <path d="M7.75 9V6.7"/>
            <path d="M7.75 5h.01"/>
            <path d="M5.25 12.4h.01M10.25 12.4h.01"/>
            <path d="M5.75 15.3h4"/>
            <path d="M2.5 11.7H1.3M13 11.7h1.2"/>
            {/* question mark */}
            <path d="M15.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75"/>
            <path d="M18 17.25h.01"/>
        </svg>
    );
}

const rowHeadingClass = 'text-[10px] uppercase tracking-[0.18em] opacity-60';
const modelLinkClass = 'font-mono text-[13px] leading-snug text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)] hover:underline underline-offset-2';

function ModelRow({heading, model, followsHint}) {
    const {t} = useI18n();

    return (
        <section>
            <h3 className={rowHeadingClass}>{heading}</h3>
            {model ? (
                <>
                    <Link href="/profile?tab=ai" className={modelLinkClass} title={t('bilinguals.change_model')}>
                        {model.label}
                    </Link>
                    {followsHint && (
                        <p className="mt-0.5 text-xs opacity-60">{t('ai.models_follows')}</p>
                    )}
                </>
            ) : (
                <Link href="/profile?tab=ai" className={modelLinkClass}>
                    {t('bilinguals.choose_model_short')}
                </Link>
            )}
        </section>
    );
}

/**
 * The centered modal listing the AI models a surface currently uses, opened
 * by the RobotHelpIcon. `answerModel` ({label}|null) is the AI questions
 * model — omitted by surfaces without that feature (the reader), which then
 * list only the explanation model. `explanationModel`
 * ({label, followsAnswer}|null) carries the resolved explanation model and
 * whether it merely follows the answer model. Keyless users (enabled false)
 * get the add-an-API-key guidance instead of rows. Every label links to the
 * profile's AI tab, where the models are changed.
 */
export default function ModelsUsedPopup({open, onClose, enabled = false, answerModel = null, explanationModel = null}) {
    const {t} = useI18n();

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const onKey = (event) => {
            if (event.key === 'Escape') {
                onClose();
            }
        };
        document.addEventListener('keydown', onKey);

        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = previousOverflow;
        };
    }, [open, onClose]);

    if (!open) {
        return null;
    }

    return createPortal(
        <div className="fixed inset-0 z-[60] flex items-center justify-center p-4">
            <div className="fixed inset-0 bg-black/50" onClick={onClose} aria-hidden="true"/>
            <div
                role="dialog"
                aria-modal="true"
                aria-label={t('ai.models_title')}
                data-models-popup
                className="relative z-10 w-full max-w-sm rounded-sm border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] bg-[var(--color-vellum)] dark:bg-[var(--color-ink-night)] shadow-lg p-5 font-sans text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]"
            >
                <div className="mb-4 flex items-center justify-between gap-3">
                    <h2 className="font-serif text-base leading-tight">{t('ai.models_title')}</h2>
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label={t('ai.models_close')}
                        className="rounded-sm p-1 opacity-60 transition-opacity hover:opacity-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)]"
                    >
                        <svg className="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
                            <path d="M6 18 18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                {!enabled ? (
                    <p className="text-sm leading-relaxed">
                        <Link
                            href="/profile?tab=ai"
                            className="text-[var(--color-verdigris)] underline underline-offset-2 hover:opacity-80 dark:text-[var(--color-verdigris-night)]"
                        >
                            {t('bilinguals.add_api_key')}
                        </Link>
                    </p>
                ) : (
                    <div className="space-y-4">
                        {answerModel && (
                            <ModelRow heading={t('ai.models_questions')} model={answerModel}/>
                        )}
                        <ModelRow
                            heading={t('ai.models_explanations')}
                            model={explanationModel}
                            followsHint={explanationModel?.followsAnswer === true}
                        />
                    </div>
                )}
            </div>
        </div>,
        document.body,
    );
}
