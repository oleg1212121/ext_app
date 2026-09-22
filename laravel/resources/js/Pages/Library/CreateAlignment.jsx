import {Link, useForm, usePage} from '@inertiajs/react';
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

function FieldError({messages = []}) {
    if (!messages.length) return null;
    return (
        <p className="mt-2 text-sm text-[var(--wbench-danger)] dark:text-[var(--wbench-danger-night)]">
            {messages.join(' ')}
        </p>
    );
}

const inputClass = (error) => [
    'mt-1 block w-full rounded-sm border px-3 py-2 text-sm shadow-sm',
    'bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)]',
    'text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]',
    'placeholder:text-[var(--wbench-ink-soft)]/50 dark:placeholder:text-[var(--wbench-ink-soft-night)]/40',
    error
        ? 'border-[var(--wbench-danger)] dark:border-[var(--wbench-danger-night)]'
        : 'border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)]',
    'focus:outline-none focus:ring-2 focus:ring-[var(--wbench-accent)] dark:focus:ring-[var(--wbench-accent-night)] focus:border-transparent',
].join(' ');

function NumberInput({id, value, onChange, error, ...props}) {
    return (
        <input
            id={id}
            type="number"
            value={value}
            onChange={onChange}
            className={inputClass(error)}
            {...props}
        />
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

export default function CreateAlignment({work, entities = {}}) {
    const {t} = useI18n();
    const {flash} = usePage().props;
    const {data, setData, post, processing, errors} = useForm({
        first_entity_id: '',
        second_entity_id: '',
        chunk_size: 75,
        max_n: 6,
    });

    const languageLabel = (code) => code.toUpperCase();

    const entityOptions = (excludeEntityId) => Object.entries(entities)
        .flatMap(([code, group]) => group.map((entity) => ({
            ...entity,
            languageCode: code,
            text: `[${languageLabel(code)}] ${entity.text}`,
        })))
        .filter((entity) => String(entity.id) !== String(excludeEntityId));

    const alignableCount = entityOptions().length;
    const firstEntity = entityOptions().find((entity) => String(entity.id) === String(data.first_entity_id));
    const firstOptions = entityOptions();
    const secondOptions = entityOptions(data.first_entity_id);

    const submit = (e) => {
        e.preventDefault();
        post(`/library/${work.id}/alignments`, {preserveScroll: true});
    };

    const duplicateBlocked = Boolean(errors.second_entity_id) && Boolean(flash?.existing_match_id);

    return (
        <div className="flex-1 min-h-0 overflow-y-auto bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)]">
            <div className="mx-auto flex max-w-2xl flex-col gap-5 px-4 py-6 sm:px-6 lg:px-8">
                <header className="flex flex-col gap-3 border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] pb-4">
                    <Link
                        href={`/library/${work.id}?tab=alignments`}
                        className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] hover:text-[var(--wbench-ink)] dark:hover:text-[var(--wbench-ink-night)]"
                    >
                        ← {work.title}
                    </Link>
                    <div>
                        <p className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {t('alignments.new_entity_match')}
                        </p>
                        <h1 className="mt-1 font-serif text-2xl tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            {t('alignments.create_heading')}
                        </h1>
                    </div>
                </header>

                {alignableCount < 2 ? (
                    <div className="border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] px-4 py-12 text-center">
                        <p className="font-serif text-lg text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            {t('library.no_alignable_entities')}
                        </p>
                        <p className="mt-1 text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            {t('library.no_alignable_entities_hint')}
                        </p>
                    </div>
                ) : (
                    <form onSubmit={submit} className="space-y-6">
                        <fieldset className="space-y-5 border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] p-5">
                            <legend className="px-1 font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                {t('alignments.entities')}
                            </legend>

                            <div>
                                <InputLabel htmlFor="first_entity_id">{t('alignments.first_entity')}</InputLabel>
                                <select
                                    id="first_entity_id"
                                    value={data.first_entity_id}
                                    onChange={(e) => setData((current) => ({...current, first_entity_id: e.target.value, second_entity_id: ''}))}
                                    className={inputClass(errors.first_entity_id)}
                                >
                                    <option value="" disabled>{t('alignments.select_entity')}</option>
                                    {firstOptions.map((entity) => (
                                        <option key={entity.id} value={entity.id}>{entity.text}</option>
                                    ))}
                                </select>
                                <FieldError messages={errors.first_entity_id ? [errors.first_entity_id] : []}/>
                            </div>

                            <div>
                                <InputLabel htmlFor="second_entity_id">
                                    {t('alignments.second_entity')}{firstEntity ? t('alignments.second_entity_suffix', {lang: languageLabel(firstEntity.languageCode), entity: firstEntity.text}) : ''}
                                </InputLabel>
                                <select
                                    id="second_entity_id"
                                    value={data.second_entity_id}
                                    onChange={(e) => setData('second_entity_id', e.target.value)}
                                    className={inputClass(errors.second_entity_id)}
                                >
                                    <option value="" disabled>{t('alignments.select_entity')}</option>
                                    {secondOptions.map((entity) => (
                                        <option key={entity.id} value={entity.id}>{entity.text}</option>
                                    ))}
                                </select>
                                <FieldError messages={duplicateBlocked ? null : (errors.second_entity_id ? [errors.second_entity_id] : [])}/>
                            </div>
                        </fieldset>

                        <div className="grid gap-5 sm:grid-cols-2">
                            <div>
                                <InputLabel htmlFor="chunk_size">{t('alignments.chunk_size')}</InputLabel>
                                <NumberInput
                                    id="chunk_size"
                                    min={25}
                                    max={100}
                                    value={data.chunk_size}
                                    onChange={(e) => setData('chunk_size', e.target.value)}
                                    error={errors.chunk_size}
                                />
                                <p className="mt-1 text-xs text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                    {t('alignments.chunk_size_hint')}
                                </p>
                                <FieldError messages={errors.chunk_size ? [errors.chunk_size] : []}/>
                            </div>

                            <div>
                                <InputLabel htmlFor="max_n">{t('alignments.max_n')}</InputLabel>
                                <NumberInput
                                    id="max_n"
                                    min={1}
                                    max={8}
                                    value={data.max_n}
                                    onChange={(e) => setData('max_n', e.target.value)}
                                    error={errors.max_n}
                                />
                                <p className="mt-1 text-xs text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                    {t('alignments.max_n_hint')}
                                </p>
                                <FieldError messages={errors.max_n ? [errors.max_n] : []}/>
                            </div>
                        </div>

                        {duplicateBlocked && (
                            <div className="border border-[var(--wbench-danger)]/40 bg-[var(--wbench-danger)]/5 px-4 py-3 text-sm">
                                <p className="text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                                    {t('alignments.duplicate_match')}
                                </p>
                                <Link
                                    href={`/alignments/${flash.existing_match_id}`}
                                    className="mt-1 inline-block text-[var(--wbench-danger)] dark:text-[var(--wbench-danger-night)] underline"
                                >
                                    {t('alignments.open_existing_match')}
                                </Link>
                            </div>
                        )}

                        <div className="flex items-center gap-4">
                            <PrimaryButton disabled={processing}>{t('alignments.create_match')}</PrimaryButton>
                            <Link
                                href={`/library/${work.id}?tab=alignments`}
                                className="font-sans text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] hover:text-[var(--wbench-ink)] dark:hover:text-[var(--wbench-ink-night)]"
                            >
                                {t('library.cancel')}
                            </Link>
                        </div>
                    </form>
                )}
            </div>
        </div>
    );
}

CreateAlignment.layout = (page) => <Main children={page}/>;
