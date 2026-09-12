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

function PrimaryButton({children, disabled = false, className = ''}) {
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
                className,
            ].join(' ')}
        >
            {children}
        </button>
    );
}

export default function Create({lang, language, works = [], languages = []}) {
    const {t} = useI18n();
    const {data, setData, post, processing, errors} = useForm({
        name: '',
        label: '',
        description: '',
        file: null,
        work_mode: works.length > 0 ? 'existing' : 'new',
        work_id: works[0]?.id ?? null,
        new_work_title: '',
        new_work_author: '',
        new_work_original_language_id: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(`/entities/${lang}`, {
            preserveScroll: true,
            forceFormData: true,
        });
    };

    return (
        <div className="flex-1 min-h-0 overflow-y-auto bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)]">
            <div className="mx-auto flex max-w-2xl flex-col gap-5 px-4 py-6 sm:px-6 lg:px-8">
                <header className="flex flex-col gap-3 border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] pb-4">
                    <Link
                        href={`/entities/${lang}`}
                        className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] hover:text-[var(--wbench-ink)] dark:hover:text-[var(--wbench-ink-night)]"
                    >
                        ← {language.name} {t('entities.entities')}
                    </Link>
                    <div>
                        <p className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {t('entities.new_entity')}
                        </p>
                        <h1 className="mt-1 font-serif text-2xl tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            {t('entities.create_a_entity', {code: language.code})}
                        </h1>
                    </div>
                </header>

                <form onSubmit={submit} className="space-y-6">
                    <div>
                        <InputLabel htmlFor="work_mode">{t('entities.work')}</InputLabel>
                        <div className="mt-1 flex flex-wrap gap-4">
                            <label className="inline-flex items-center gap-2 text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                                <input
                                    type="radio"
                                    name="work_mode"
                                    value="existing"
                                    checked={data.work_mode === 'existing'}
                                    onChange={() => setData((current) => ({...current, work_mode: 'existing', new_work_title: ''}))}
                                    disabled={works.length === 0}
                                />
                                {t('entities.existing_work')}
                            </label>
                            <label className="inline-flex items-center gap-2 text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                                <input
                                    type="radio"
                                    name="work_mode"
                                    value="new"
                                    checked={data.work_mode === 'new'}
                                    onChange={() => setData((current) => ({...current, work_mode: 'new', work_id: null}))}
                                />
                                {t('entities.new_work')}
                            </label>
                        </div>

                        {data.work_mode === 'existing' && works.length > 0 ? (
                            <select
                                id="work_id"
                                name="work_id"
                                value={data.work_id}
                                onChange={(e) => setData('work_id', e.target.value)}
                                className="mt-2 block w-full rounded-sm border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] px-3 py-2 text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]"
                            >
                                {works.map((work) => (
                                    <option key={work.id} value={work.id}>{work.title}</option>
                                ))}
                            </select>
                        ) : (
                            <div className="mt-2 space-y-3">
                                <TextInput
                                    id="new_work_title"
                                    placeholder={t('entities.work_title_placeholder')}
                                    value={data.new_work_title}
                                    onChange={(e) => setData('new_work_title', e.target.value)}
                                    error={errors.new_work_title || errors.work_id}
                                />
                                <TextInput
                                    id="new_work_author"
                                    placeholder={t('entities.author_optional')}
                                    value={data.new_work_author}
                                    onChange={(e) => setData('new_work_author', e.target.value)}
                                    error={errors.new_work_author}
                                />
                                <select
                                    id="new_work_original_language_id"
                                    value={data.new_work_original_language_id}
                                    onChange={(e) => setData('new_work_original_language_id', e.target.value)}
                                    className="mt-1 block w-full rounded-sm border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)] px-3 py-2 text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]"
                                >
                                    <option value="">{t('entities.original_language_defaults', {name: language.name})}</option>
                                    {languages.map((item) => (
                                        <option key={item.id} value={item.id}>
                                            {item.name}{item.code === language.code ? t('entities.this_text') : ''}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        )}
                        <InputError messages={errors.work_id || errors.new_work_title ? [errors.work_id, errors.new_work_title].filter(Boolean) : []}/>
                    </div>

                    <div>
                        <InputLabel htmlFor="name">{t('entities.name')}</InputLabel>
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
                        <InputLabel htmlFor="label">{t('entities.translator_edition_note')}</InputLabel>
                        <TextInput
                            id="label"
                            value={data.label}
                            onChange={(e) => setData('label', e.target.value)}
                            placeholder={t('entities.label_placeholder')}
                            error={errors.label}
                        />
                        <InputError messages={errors.label ? [errors.label] : []}/>
                    </div>

                    <div>
                        <InputLabel htmlFor="description">{t('entities.description')}</InputLabel>
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
                        <InputLabel htmlFor="file">{t('entities.text_file')}</InputLabel>
                        <input
                            id="file"
                            type="file"
                            accept="text/plain"
                            onChange={(e) => setData('file', e.target.files?.[0] ?? null)}
                            className="mt-1 block w-full text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)] file:mr-3 file:rounded-sm file:border-0 file:bg-[var(--wbench-paper-deep)] dark:file:bg-[var(--wbench-paper-deep-night)] file:px-3 file:py-1 file:text-[var(--wbench-ink-soft)] dark:file:text-[var(--wbench-ink-soft-night)] file:cursor-pointer"
                        />
                        <p className="mt-1 text-xs text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {t('entities.text_file_hint')}
                        </p>
                        <InputError messages={errors.file ? [errors.file] : []}/>
                    </div>

                    <div className="flex items-center gap-4">
                        <PrimaryButton disabled={processing}>{t('entities.create_entity')}</PrimaryButton>
                        <Link
                            href={`/entities/${lang}`}
                            className="font-sans text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] hover:text-[var(--wbench-ink)] dark:hover:text-[var(--wbench-ink-night)]"
                        >
                            {t('entities.cancel')}
                        </Link>
                    </div>
                </form>
            </div>
        </div>
    );
}

Create.layout = (page) => <Main children={page}/>;
