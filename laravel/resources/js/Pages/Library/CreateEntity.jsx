import {useForm, Link} from '@inertiajs/react';
import Main from '../../Layouts/Main.jsx';
import {useI18n} from '../../i18n';

function InputLabel({htmlFor, children}) {
    return (
        <label
            htmlFor={htmlFor}
            className="block text-sm font-medium text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]"
        >
            {children}
        </label>
    );
}

function TextInput({id, type = 'text', value, onChange, error, ...props}) {
    return (
        <input
            id={id}
            type={type}
            value={value}
            onChange={onChange}
            className={[
                'mt-1 block w-full rounded-sm border px-3 py-2 text-sm shadow-sm',
                'bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)]',
                'text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]',
                'placeholder:text-[var(--wbench-ink-soft)]/50 dark:placeholder:text-[var(--wbench-ink-soft-night)]/40',
                error
                    ? 'border-[var(--wbench-danger)] dark:border-[var(--wbench-danger-night)]'
                    : 'border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]',
                'focus:outline-none focus:ring-2 focus:ring-[var(--wbench-accent)] dark:focus:ring-[var(--wbench-accent-night)] focus:border-transparent',
            ].join(' ')}
            {...props}
        />
    );
}

function TextArea({id, value, onChange, error, ...props}) {
    return (
        <textarea
            id={id}
            value={value}
            onChange={onChange}
            className={[
                'mt-1 block w-full rounded-sm border px-3 py-2 text-sm shadow-sm',
                'bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)]',
                'text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]',
                'placeholder:text-[var(--wbench-ink-soft)]/50 dark:placeholder:text-[var(--wbench-ink-soft-night)]/40',
                error
                    ? 'border-[var(--wbench-danger)] dark:border-[var(--wbench-danger-night)]'
                    : 'border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]',
                'focus:outline-none focus:ring-2 focus:ring-[var(--wbench-accent)] dark:focus:ring-[var(--wbench-accent-night)] focus:border-transparent',
            ].join(' ')}
            {...props}
        />
    );
}

function InputError({messages = []}) {
    if (!messages.length) return null;
    return (
        <p className="mt-2 text-sm text-[var(--wbench-danger)] dark:text-[var(--wbench-danger-night)]">
            {messages.join(' ')}
        </p>
    );
}

function PrimaryButton({children, disabled = false}) {
    return (
        <button
            type="submit"
            disabled={disabled}
            className={[
                'inline-flex items-center px-4 py-2 rounded-sm text-sm font-medium transition-colors',
                'bg-[var(--wbench-accent)] dark:bg-[var(--wbench-accent-night)]',
                'text-white dark:text-[var(--wbench-ink-night)]',
                'hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wbench-accent)] dark:focus-visible:ring-[var(--wbench-accent-night)]',
                'disabled:opacity-50',
            ].join(' ')}
        >
            {children}
        </button>
    );
}

export default function CreateEntity({work, languages = []}) {
    const {t} = useI18n();
    const {data, setData, post, processing, errors} = useForm({
        language_id: '',
        name: '',
        label: '',
        description: '',
        file: null,
    });

    const submit = (e) => {
        e.preventDefault();
        post(`/works/${work.id}/entities`, {
            preserveScroll: true,
            forceFormData: true,
        });
    };

    return (
        <div className="flex-1 min-h-0 overflow-y-auto bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)]">
            <div className="mx-auto flex max-w-2xl flex-col gap-5 px-4 py-6 sm:px-6 lg:px-8">
                <header className="flex flex-col gap-3 border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] pb-4">
                    <Link
                        href={`/works/${work.id}/entities`}
                        className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] hover:text-[var(--wbench-ink)] dark:hover:text-[var(--wbench-ink-night)]"
                    >
                        ← {work.title}
                    </Link>
                    <div>
                        <p className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {t('library.library')} · {work.title}
                        </p>
                        <h1 className="mt-1 font-serif text-2xl tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            {t('library.add_an_entity')}
                        </h1>
                    </div>
                </header>

                <div className="border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper-deep)] dark:bg-[var(--wbench-paper-deep-night)] px-4 py-3">
                    <p className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                        {t('library.work')}
                    </p>
                    <p className="mt-1 font-serif text-lg text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                        {work.title}
                    </p>
                    {work.author && (
                        <p className="text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {work.author}
                        </p>
                    )}
                </div>

                <form onSubmit={submit} className="space-y-6">
                    <div>
                        <InputLabel htmlFor="language_id">{t('library.language')}</InputLabel>
                        <select
                            id="language_id"
                            value={data.language_id}
                            onChange={(e) => setData('language_id', e.target.value)}
                            required
                            className={[
                                'mt-1 block w-full rounded-sm border px-3 py-2 text-sm shadow-sm',
                                'bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)]',
                                'text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]',
                                errors.language_id
                                    ? 'border-[var(--wbench-danger)] dark:border-[var(--wbench-danger-night)]'
                                    : 'border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]',
                                'focus:outline-none focus:ring-2 focus:ring-[var(--wbench-accent)] dark:focus:ring-[var(--wbench-accent-night)] focus:border-transparent',
                            ].join(' ')}
                        >
                            <option value="">{t('library.pick_language_of_text')}</option>
                            {languages.map((language) => (
                                <option key={language.id} value={language.id}>
                                    {language.name}{language.native_name ? ` — ${language.native_name}` : ''}
                                </option>
                            ))}
                        </select>
                        <InputError messages={errors.language_id ? [errors.language_id] : []}/>
                    </div>

                    <div>
                        <InputLabel htmlFor="name">{t('library.name')}</InputLabel>
                        <TextInput
                            id="name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            required
                            autoFocus
                            error={errors.name}
                        />
                        <InputError messages={errors.name ? [errors.name] : []}/>
                    </div>

                    <div>
                        <InputLabel htmlFor="label">{t('library.translator_edition_note')}</InputLabel>
                        <TextInput
                            id="label"
                            value={data.label}
                            onChange={(e) => setData('label', e.target.value)}
                            placeholder={t('library.label_placeholder')}
                            error={errors.label}
                        />
                        <InputError messages={errors.label ? [errors.label] : []}/>
                    </div>

                    <div>
                        <InputLabel htmlFor="description">{t('library.description')}</InputLabel>
                        <TextArea
                            id="description"
                            rows={4}
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            error={errors.description}
                        />
                        <InputError messages={errors.description ? [errors.description] : []}/>
                    </div>

                    <div>
                        <InputLabel htmlFor="file">{t('library.text_file')}</InputLabel>
                        <input
                            id="file"
                            type="file"
                            accept="text/plain"
                            onChange={(e) => setData('file', e.target.files?.[0] ?? null)}
                            className="mt-1 block w-full text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] file:mr-3 file:rounded-sm file:border-0 file:bg-[var(--wbench-paper-deep)] dark:file:bg-[var(--wbench-paper-deep-night)] file:px-3 file:py-1 file:text-[var(--wbench-ink-soft)] dark:file:text-[var(--wbench-ink-soft-night)] file:cursor-pointer"
                        />
                        <p className="mt-1 text-xs text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {t('library.text_file_hint')}
                        </p>
                        <InputError messages={errors.file ? [errors.file] : []}/>
                    </div>

                    <div className="flex items-center gap-4">
                        <PrimaryButton disabled={processing}>{t('library.create_entity')}</PrimaryButton>
                        <Link
                            href={`/works/${work.id}/entities`}
                            className="font-sans text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] hover:text-[var(--wbench-ink)] dark:hover:text-[var(--wbench-ink-night)]"
                        >
                            {t('library.cancel')}
                        </Link>
                    </div>
                </form>
            </div>
        </div>
    );
}

CreateEntity.layout = (page) => <Main children={page}/>;
