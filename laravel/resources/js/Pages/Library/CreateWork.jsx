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

export default function CreateWork({languages = []}) {
    const {t} = useI18n();
    const {data, setData, post, processing, errors} = useForm({
        title: '',
        author: '',
        description: '',
        original_language_id: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post('/library', {preserveScroll: true});
    };

    return (
        <div className="flex-1 min-h-0 overflow-y-auto bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)]">
            <div className="mx-auto flex max-w-2xl flex-col gap-5 px-4 py-6 sm:px-6 lg:px-8">
                <header className="flex flex-col gap-3 border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] pb-4">
                    <Link
                        href="/library"
                        className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] hover:text-[var(--wbench-ink)] dark:hover:text-[var(--wbench-ink-night)]"
                    >
                        ← {t('library.library')}
                    </Link>
                    <div>
                        <p className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {t('library.library')}
                        </p>
                        <h1 className="mt-1 font-serif text-2xl tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            {t('library.add_a_work')}
                        </h1>
                    </div>
                </header>

                <form onSubmit={submit} className="space-y-6">
                    <div>
                        <InputLabel htmlFor="title">{t('library.title')}</InputLabel>
                        <TextInput
                            id="title"
                            value={data.title}
                            onChange={(e) => setData('title', e.target.value)}
                            placeholder={t('library.work_title_placeholder')}
                            required
                            autoFocus
                            error={errors.title}
                        />
                        <InputError messages={errors.title ? [errors.title] : []}/>
                    </div>

                    <div>
                        <InputLabel htmlFor="author">{t('library.author')}</InputLabel>
                        <TextInput
                            id="author"
                            value={data.author}
                            onChange={(e) => setData('author', e.target.value)}
                            placeholder={t('library.optional')}
                            error={errors.author}
                        />
                        <InputError messages={errors.author ? [errors.author] : []}/>
                    </div>

                    <div>
                        <InputLabel htmlFor="original_language_id">{t('library.original_language')}</InputLabel>
                        <select
                            id="original_language_id"
                            value={data.original_language_id}
                            onChange={(e) => setData('original_language_id', e.target.value)}
                            required
                            className={[
                                'mt-1 block w-full rounded-sm border px-3 py-2 text-sm shadow-sm',
                                'bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)]',
                                'text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]',
                                errors.original_language_id
                                    ? 'border-[var(--wbench-danger)] dark:border-[var(--wbench-danger-night)]'
                                    : 'border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]',
                                'focus:outline-none focus:ring-2 focus:ring-[var(--wbench-accent)] dark:focus:ring-[var(--wbench-accent-night)] focus:border-transparent',
                            ].join(' ')}
                        >
                            <option value="">{t('library.pick_a_language')}</option>
                            {languages.map((language) => (
                                <option key={language.id} value={language.id}>
                                    {language.name}{language.native_name ? ` — ${language.native_name}` : ''}
                                </option>
                            ))}
                        </select>
                        <p className="mt-1 text-xs text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {t('library.original_language_hint')}
                        </p>
                        <InputError messages={errors.original_language_id ? [errors.original_language_id] : []}/>
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

                    <div className="flex items-center gap-4">
                        <PrimaryButton disabled={processing}>{t('library.create_work')}</PrimaryButton>
                        <Link
                            href="/library"
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

CreateWork.layout = (page) => <Main children={page}/>;
