import React from "react";
import Main from "../Layouts/Main.jsx";

const Section = ({ children }) => (
    <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">{children}</div>
);

const Card = ({ lang, children }) => (
    <div
        className="border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] bg-[var(--color-vellum)] dark:bg-[var(--color-ink)] p-6"
        lang={lang}
    >
        {children}
    </div>
);

const Welcome = ({ lang = "en" }) => {
    return (
        <div className="flex-1 min-h-0 overflow-y-auto bg-white dark:bg-[var(--color-ink)] text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]">
            <div className="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">
                {/* Eyebrow */}
                <p className="text-center font-sans text-[10px] font-medium uppercase tracking-[0.24em] text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/60">
                    About
                </p>

                {/* ── Section 1: Abibook ── */}
                <Section>
                    <Card lang="en">
                        <h2 className="font-serif text-2xl tracking-tight text-[var(--color-vermilion)] dark:text-[var(--color-vermilion-night)]">
                            Abibook
                        </h2>
                        <p className="mt-3 font-sans text-sm leading-relaxed">
                            <strong>Abibook</strong>{" "}
                            <span className="text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/60">
                                (Wordplay:
                            </span>{" "}
                            <em>абібок</em>{" "}
                            <span className="text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/60">
                                +
                            </span>{" "}
                            <em>book</em>{" "}
                            <span className="text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/60">
                                =
                            </span>{" "}
                            <em>abibook</em>
                            <span className="text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/60">
                                )
                            </span>{" "}
                            A resource for learning languages through bilingual
                            books and texts using AI tools.
                        </p>
                        <h3 className="mt-5 font-sans text-xs font-medium uppercase tracking-[0.18em] text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/60">
                            Origin of the word
                        </h3>
                        <ul className="mt-2 list-none space-y-1 font-sans text-sm leading-relaxed">
                            <li className="flex gap-2">
                                <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-[var(--color-verdigris)] dark:bg-[var(--color-verdigris-night)]" />
                                <span>
                                    It comes from combining the Belarusian word{" "}
                                    <em>абібок</em> and the English word{" "}
                                    <em>book</em>.
                                </span>
                            </li>
                        </ul>
                    </Card>

                    <Card lang="ru">
                        <h2 className="font-serif text-2xl tracking-tight text-[var(--color-vermilion)] dark:text-[var(--color-vermilion-night)]">
                            Абибук
                        </h2>
                        <p className="mt-3 font-sans text-sm leading-relaxed">
                            <strong>Абибук</strong>{" "}
                            <span className="text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/60">
                                (Игра слов:
                            </span>{" "}
                            <em>абібок</em>{" "}
                            <span className="text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/60">
                                +
                            </span>{" "}
                            <em>book</em>{" "}
                            <span className="text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/60">
                                =
                            </span>{" "}
                            <em>абибук</em>
                            <span className="text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/60">
                                )
                            </span>{" "}
                            Ресурс для изучения языков по двуязычным книгам и
                            текстам при помощи ИИ-инструментов.
                        </p>
                        <h3 className="mt-5 font-sans text-xs font-medium uppercase tracking-[0.18em] text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/60">
                            Происхождение слова
                        </h3>
                        <ul className="mt-2 list-none space-y-1 font-sans text-sm leading-relaxed">
                            <li className="flex gap-2">
                                <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-[var(--color-verdigris)] dark:bg-[var(--color-verdigris-night)]" />
                                <span>
                                    Происходит от объединения белорусского слова{" "}
                                    <em>абібок</em> и английского слова{" "}
                                    <em>book</em>.
                                </span>
                            </li>
                        </ul>
                    </Card>
                </Section>

                {/* ── Divider ── */}
                <div className="my-8 flex items-center gap-4">
                    <div className="h-px flex-1 bg-[var(--color-hairline)] dark:bg-[var(--color-hairline-night)]" />
                    <span className="h-1.5 w-1.5 rotate-45 bg-[var(--color-vermilion)] dark:bg-[var(--color-vermilion-night)]" />
                    <div className="h-px flex-1 bg-[var(--color-hairline)] dark:bg-[var(--color-hairline-night)]" />
                </div>

                {/* ── Section 2: Абибок ── */}
                <Section>
                    <Card lang="en">
                        <h2 className="font-serif text-2xl tracking-tight text-[var(--color-vermilion)] dark:text-[var(--color-vermilion-night)]">
                            Abibok
                        </h2>
                        <p className="mt-3 font-sans text-sm leading-relaxed">
                            <strong>Abibok</strong>{" "}
                            <span className="text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/60">
                                (Belarusian:
                            </span>{" "}
                            <em>абібок</em>
                            <span className="text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/60">
                                )
                            </span>{" "}
                            is a colloquial Belarusian word used to describe a
                            lazy person, slacker, idler, or couch potato.
                        </p>
                        <h3 className="mt-5 font-sans text-xs font-medium uppercase tracking-[0.18em] text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/60">
                            Origin of the word
                        </h3>
                        <ul className="mt-2 list-none space-y-1 font-sans text-sm leading-relaxed">
                            <li className="flex gap-2">
                                <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-[var(--color-verdigris)] dark:bg-[var(--color-verdigris-night)]" />
                                <span>
                                    It comes from the idiom{" "}
                                    <em>"abivac' baki"</em>{" "}
                                    <span className="text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/60">
                                        (абіваць бакі),
                                    </span>{" "}
                                    which literally means "to beat one's sides"
                                    — an idiom for lying around doing nothing.
                                </span>
                            </li>
                            <li className="flex gap-2">
                                <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-[var(--color-verdigris)] dark:bg-[var(--color-verdigris-night)]" />
                                <span>
                                    Closely related to the playful folk
                                    expression{" "}
                                    <em>"nie biej boka"</em>{" "}
                                    <span className="text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/60">
                                        (не бей бока),
                                    </span>{" "}
                                    meaning "don't be lazy" or "don't just lie
                                    around."
                                </span>
                            </li>
                        </ul>
                    </Card>

                    <Card lang="ru">
                        <h2 className="font-serif text-2xl tracking-tight text-[var(--color-vermilion)] dark:text-[var(--color-vermilion-night)]">
                            Абібок
                        </h2>
                        <p className="mt-3 font-sans text-sm leading-relaxed">
                            <strong>Абібок</strong>{" "}
                            <span className="text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/60">
                                (бел.
                            </span>{" "}
                            <em>абібок</em>
                            <span className="text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/60">
                                )
                            </span>{" "}
                            — это белорусское разговорное слово, означающее
                            лежебоку, лодыря, тунеядца или бездельника.
                        </p>
                        <h3 className="mt-5 font-sans text-xs font-medium uppercase tracking-[0.18em] text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/60">
                            Происхождение слова
                        </h3>
                        <ul className="mt-2 list-none space-y-1 font-sans text-sm leading-relaxed">
                            <li className="flex gap-2">
                                <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-[var(--color-verdigris)] dark:bg-[var(--color-verdigris-night)]" />
                                <span>
                                    Происходит от выражения «абіваць баки»
                                    (обивать бока, лежать без дела).
                                </span>
                            </li>
                            <li className="flex gap-2">
                                <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-[var(--color-verdigris)] dark:bg-[var(--color-verdigris-night)]" />
                                <span>
                                    Отсюда же народная шутливая фраза: «не бей
                                    бока» (не ленись, не залеживайся).
                                </span>
                            </li>
                        </ul>
                    </Card>
                </Section>
            </div>
        </div>
    );
};

Welcome.layout = (page) => <Main children={page} />;
export default Welcome;
