import {useEffect, useState} from 'react';
import {usePage} from '@inertiajs/react';
import Main from '../../Layouts/Main.jsx';
import {useI18n} from '../../i18n';
import {PHONEME_CHART, PHONEME_LANGUAGES, DIAGRAM_CREDITS} from '../../data/phonemes/phonemes.js';
import ArticulationDiagram from '../../Components/Phonemes/ArticulationDiagram.jsx';

// IPA symbols render in Gentium Plus (self-hosted, OFL): the app's own
// families don't cover the full IPA vowel/sibilant set.
const ipaClass = "font-['Gentium_Plus']";

const tabClass = (isActive) => [
    'relative inline-flex items-center px-2 py-2 text-sm font-medium tracking-wide transition-colors duration-200 rounded-sm',
    'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)]',
    isActive
        ? 'text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]'
        : 'text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/60 hover:text-[var(--color-ink)] dark:hover:text-[var(--color-vellum-night)]',
].join(' ');

const cardButtonClass = [
    'group flex h-full w-full flex-col items-center gap-1 rounded-sm border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)]',
    'bg-[var(--color-vellum-deep)]/40 dark:bg-[var(--color-ink-night)]/60 px-2 py-3 text-center transition-colors',
    'hover:border-[var(--color-vermilion)] dark:hover:border-[var(--color-vermilion-night)]',
    'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)]',
].join(' ');

function MarkedWord({word, mark, markClass = 'font-medium'}) {
    const index = mark ? word.indexOf(mark) : -1;
    return index >= 0 ? (
        <>
            {word.slice(0, index)}
            <b className={markClass}>{word.slice(index, index + mark.length)}</b>
            {word.slice(index + mark.length)}
        </>
    ) : word;
}

function ExampleWord({word, mark}) {
    return (
        <li className="font-serif text-[15px] leading-snug">
            <MarkedWord word={word} mark={mark} markClass="font-medium text-[var(--color-vermilion)] dark:text-[var(--color-vermilion-night)]"/>
        </li>
    );
}

function SoundCard({sound, onSelect, pairLabel}) {
    const {t, locale} = useI18n();
    return (
        <button type="button" onClick={onSelect} className={cardButtonClass}>
            {pairLabel && <span className="text-[10px] uppercase tracking-[0.14em] opacity-50">{pairLabel}</span>}
            <span className={`${ipaClass} text-2xl leading-none`}>{sound.ipa}</span>
            <ArticulationDiagram art={sound.art} className="h-20 w-full"/>
            <span className="text-[11px] leading-tight opacity-60">{sound.spell}</span>
            <span className="font-serif text-[13px] leading-tight opacity-90">
                {sound.examples.slice(0, 2).map((ex, i) => (
                    <span key={i}>
                        {i > 0 && ', '}
                        <MarkedWord word={ex.w} mark={ex.m}/>
                    </span>
                ))}
            </span>
            <span className="sr-only">{sound.desc[locale] ?? sound.desc.en}</span>
            <span className="sr-only">{t('sounds.open_sound')}</span>
        </button>
    );
}

function PairUnit({pair, onSelect}) {
    const {t} = useI18n();
    return (
        <div className="flex h-full flex-col rounded-sm border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] bg-[var(--color-vellum-deep)]/30 dark:bg-[var(--color-ink-night)]/40 p-1.5">
            <div className="grid flex-1 grid-cols-2 gap-1.5">
                <SoundCard sound={pair.hard} onSelect={() => onSelect(pair.hard, pair)}/>
                <SoundCard sound={pair.soft} onSelect={() => onSelect(pair.soft, pair)}/>
            </div>
            <div className="mt-1 flex justify-center gap-4 text-[10px] uppercase tracking-[0.14em] opacity-50">
                <span>{t('sounds.pair_hard')}</span>
                <span>{t('sounds.pair_soft')}</span>
            </div>
        </div>
    );
}

function SoundDetail({sound, pair, onBack}) {
    const {t, locale} = useI18n();
    const desc = sound.desc[locale] ?? sound.desc.en;
    const hint = sound.hint?.[locale];
    const rp = sound.rp?.[locale];

    return (
        <div className="flex flex-col gap-4">
            <button
                type="button"
                onClick={onBack}
                className="inline-flex items-center gap-1.5 self-start text-sm text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70 hover:text-[var(--color-vermilion)] dark:hover:text-[var(--color-vermilion-night)] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)] rounded-sm"
            >
                <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7"/>
                </svg>
                {t('sounds.back')}
            </button>

            <div className="flex flex-col items-center gap-2 sm:flex-row sm:items-start sm:gap-6">
                <ArticulationDiagram
                    art={sound.art}
                    phaseStartLabel={sound.art.length === 2 ? t('sounds.phase_start') : undefined}
                    phaseEndLabel={sound.art.length === 2 ? t('sounds.phase_end') : undefined}
                    className="h-52 w-full shrink-0 sm:w-72"
                />
                <div className="w-full min-w-0">
                    <div className="flex items-baseline gap-3">
                        <span className={`${ipaClass} text-5xl leading-none`}>{sound.ipa}</span>
                        {pair && (
                            <span className="text-xs uppercase tracking-[0.14em] opacity-50">
                                {pair.hard === sound ? t('sounds.pair_hard') : t('sounds.pair_soft')}
                            </span>
                        )}
                    </div>
                    <p className="mt-1 text-sm opacity-60">{sound.spell}</p>
                    <p className="mt-3 text-sm leading-relaxed">{desc}</p>

                    {hint && (
                        <p className="mt-3 border-s-2 border-[var(--color-verdigris)] dark:border-[var(--color-verdigris-night)] ps-3 text-sm leading-relaxed opacity-90">
                            <span className="me-1 text-[10px] uppercase tracking-[0.14em] opacity-60">{t('sounds.hint_label')}</span>
                            {hint}
                        </p>
                    )}
                    {rp && (
                        <p className="mt-2 border-s-2 border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] ps-3 text-sm leading-relaxed opacity-75">
                            <span className="me-1 text-[10px] uppercase tracking-[0.14em] opacity-60">{t('sounds.rp_label')}</span>
                            {rp}
                        </p>
                    )}
                </div>
            </div>

            <div>
                <h4 className="text-[10px] uppercase tracking-[0.18em] opacity-60">{t('sounds.examples_label')}</h4>
                <ul className="mt-1.5 flex flex-wrap gap-x-5 gap-y-1">
                    {sound.examples.map((ex, i) => <ExampleWord key={i} word={ex.w} mark={ex.m}/>)}
                </ul>
            </div>
        </div>
    );
}

/**
 * The pronunciation reference page: per-language tabs over grouped sound
 * cards, reached from the navbar's Resources menu. The default tab is the
 * user's learning target — the language they are NOT a native speaker of
 * (native English speakers start on Russian, everyone else on English). The
 * selected card's enlarged view swaps in place — no routing, no URL state.
 */
export default function PronunciationGuide() {
    const {props} = usePage();
    const {t, locale} = useI18n();
    const nativeLanguage = props?.auth?.user?.native_language ?? null;
    // learning target: a native English speaker starts on Russian, everyone
    // else on English
    const [lang, setLang] = useState(nativeLanguage === 'en' ? 'ru' : 'en');
    const [selected, setSelected] = useState(null); // {sound, pair}

    // default tab tracks the shared native-language code so a login or
    // logout without a full reload still lands on the learning target
    useEffect(() => {
        setLang(nativeLanguage === 'en' ? 'ru' : 'en');
    }, [nativeLanguage]);

    const chart = PHONEME_CHART[lang];

    return (
        <div className="flex-1 min-h-0 flex flex-col bg-[var(--color-vellum)] dark:bg-[var(--color-ink-night)] text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]">
            <div className="flex-1 min-h-0 overflow-y-auto">
                <div className="mx-auto w-full max-w-4xl px-4 py-6 sm:px-6">
                    <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-2 border-b border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] pb-3">
                        <h1 className="font-serif text-xl tracking-tight">{t('sounds.title')}</h1>
                        <div className="flex" role="tablist" aria-label={t('sounds.title')}>
                            {PHONEME_LANGUAGES.map((code) => (
                                <button
                                    key={code}
                                    type="button"
                                    role="tab"
                                    aria-selected={lang === code}
                                    onClick={() => {
                                        setLang(code);
                                        setSelected(null);
                                    }}
                                    className={tabClass(lang === code)}
                                >
                                    {PHONEME_CHART[code].label[locale] ?? PHONEME_CHART[code].label.en}
                                    <span
                                        aria-hidden="true"
                                        className={[
                                            'absolute left-1 right-1 -bottom-px h-px bg-[var(--color-vermilion)] dark:bg-[var(--color-vermilion-night)] transition-transform duration-300',
                                            lang === code ? 'scale-x-100' : 'scale-x-0',
                                        ].join(' ')}
                                        style={{transformOrigin: 'left center'}}
                                    />
                                </button>
                            ))}
                        </div>
                    </div>

                    <div className="mt-6">
                        {selected ? (
                            <SoundDetail sound={selected.sound} pair={selected.pair} onBack={() => setSelected(null)}/>
                        ) : (
                            <div className="flex flex-col gap-6">
                                {chart.groups.map((group) => (
                                    <section key={group.id}>
                                        <h3 className="text-[10px] uppercase tracking-[0.18em] opacity-60">{t(`sounds.group_${group.id}`)}</h3>
                                        {group.id === 'pairs' ? (
                                            <div className="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                                {group.items.map((pair, i) => (
                                                    <PairUnit key={i} pair={pair} onSelect={(sound, pair_) => setSelected({sound, pair: pair_})}/>
                                                ))}
                                            </div>
                                        ) : (
                                            <div className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                                                {group.items.map((sound, i) => (
                                                    <SoundCard key={i} sound={sound} onSelect={() => setSelected({sound, pair: null})}/>
                                                ))}
                                            </div>
                                        )}
                                    </section>
                                ))}
                                <p className="text-[11px] leading-relaxed opacity-40">{DIAGRAM_CREDITS}</p>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

PronunciationGuide.layout = (page) => <Main>{page}</Main>;
