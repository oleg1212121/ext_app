import {Link, useForm, usePage} from '@inertiajs/react';
import Main from '../../Layouts/Main.jsx';

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

function EntitySelect({id, label, value, onChange, items, error, emptyText}) {
    return (
        <div>
            <InputLabel htmlFor={id}>{label}</InputLabel>
            <select
                id={id}
                value={value}
                onChange={onChange}
                className={inputClass(error)}
            >
                {items.length === 0 ? (
                    <option value="">{emptyText}</option>
                ) : (
                    <>
                        <option value="" disabled>Select an entity…</option>
                        {items.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.text}
                            </option>
                        ))}
                    </>
                )}
            </select>
            <FieldError messages={error ? [error] : []}/>
        </div>
    );
}

function CreateEntityLink({href, children}) {
    return (
        <Link
            href={href}
            className="mt-2 inline-block text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] hover:text-[var(--wbench-ink)] dark:hover:text-[var(--wbench-ink-night)]"
        >
            {children}
        </Link>
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

export default function Create({enEntities, ruEntities}) {
    const {flash} = usePage().props;
    const {data, setData, post, processing, errors} = useForm({
        en_entity_id: '',
        ru_entity_id: '',
        is_original_en: true,
        chunk_size: 75,
        max_n: 6,
    });

    const submit = (e) => {
        e.preventDefault();
        post('/alignments', {preserveScroll: true});
    };

    const duplicateBlocked = Boolean(errors.ru_entity_id) && Boolean(flash?.existing_match_id);

    return (
        <div className="flex-1 min-h-0 overflow-y-auto bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)]">
            <div className="mx-auto flex max-w-2xl flex-col gap-5 px-4 py-6 sm:px-6 lg:px-8">
                <header className="flex flex-col gap-3 border-b border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] pb-4">
                    <Link
                        href="/alignments"
                        className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] hover:text-[var(--wbench-ink)] dark:hover:text-[var(--wbench-ink-night)]"
                    >
                        ← Alignments
                    </Link>
                    <div>
                        <p className="font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            New entity match
                        </p>
                        <h1 className="mt-1 font-serif text-2xl tracking-tight text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                            Align an EN / RU pair
                        </h1>
                    </div>
                </header>

                <form onSubmit={submit} className="space-y-6">
                    <fieldset className="space-y-5 border border-[var(--wbench-rule)] dark:border-[var(--wbench-rule-night)] p-5">
                        <legend className="px-1 font-mono text-[10px] uppercase tracking-[0.24em] text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                            Entities
                        </legend>

                        <div>
                            <EntitySelect
                                id="en_entity_id"
                                label="English entity"
                                value={data.en_entity_id}
                                onChange={(e) => setData('en_entity_id', e.target.value)}
                                items={enEntities}
                                error={errors.en_entity_id}
                                emptyText="No alignable EN entities yet"
                            />
                            <CreateEntityLink href="/entities/en/create">
                                + Create a new EN entity
                            </CreateEntityLink>
                        </div>

                        <div>
                            <EntitySelect
                                id="ru_entity_id"
                                label="Russian entity"
                                value={data.ru_entity_id}
                                onChange={(e) => setData('ru_entity_id', e.target.value)}
                                items={ruEntities}
                                error={duplicateBlocked ? null : errors.ru_entity_id}
                                emptyText="No alignable RU entities yet"
                            />
                            <CreateEntityLink href="/entities/ru/create">
                                + Create a new RU entity
                            </CreateEntityLink>
                        </div>

                        <div>
                            <span className="block text-sm font-medium text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                                Original text
                            </span>
                            <div className="mt-2 flex gap-6">
                                {[
                                    {value: true, label: 'EN is the original text'},
                                    {value: false, label: 'RU is the original text'},
                                ].map((option) => (
                                    <label
                                        key={String(option.value)}
                                        className="flex items-center gap-2 text-sm text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]"
                                    >
                                        <input
                                            type="radio"
                                            name="is_original_en"
                                            checked={data.is_original_en === option.value}
                                            onChange={() => setData('is_original_en', option.value)}
                                            className="h-4 w-4 accent-[var(--wbench-accent)]"
                                        />
                                        {option.label}
                                    </label>
                                ))}
                            </div>
                            <FieldError messages={errors.is_original_en ? [errors.is_original_en] : []}/>
                        </div>
                    </fieldset>

                    <div className="grid gap-5 sm:grid-cols-2">
                        <div>
                            <InputLabel htmlFor="chunk_size">Chunk size</InputLabel>
                            <NumberInput
                                id="chunk_size"
                                min={25}
                                max={100}
                                value={data.chunk_size}
                                onChange={(e) => setData('chunk_size', e.target.value)}
                                error={errors.chunk_size}
                            />
                            <p className="mt-1 text-xs text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                Sentences per chunk (25–100).
                            </p>
                            <FieldError messages={errors.chunk_size ? [errors.chunk_size] : []}/>
                        </div>

                        <div>
                            <InputLabel htmlFor="max_n">Max sentence span</InputLabel>
                            <NumberInput
                                id="max_n"
                                min={1}
                                max={8}
                                value={data.max_n}
                                onChange={(e) => setData('max_n', e.target.value)}
                                error={errors.max_n}
                            />
                            <p className="mt-1 text-xs text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)]">
                                Alignment window size (1–8).
                            </p>
                            <FieldError messages={errors.max_n ? [errors.max_n] : []}/>
                        </div>
                    </div>

                    {duplicateBlocked && (
                        <div className="border border-[var(--wbench-danger)]/40 bg-[var(--wbench-danger)]/5 px-4 py-3 text-sm">
                            <p className="text-[var(--wbench-ink)] dark:text-[var(--wbench-ink-night)]">
                                A match for this entity pair already exists.
                            </p>
                            <Link
                                href={`/alignments/${flash.existing_match_id}`}
                                className="mt-1 inline-block text-[var(--wbench-danger)] dark:text-[var(--wbench-danger-night)] underline"
                            >
                                Open existing match →
                            </Link>
                        </div>
                    )}

                    <div className="flex items-center gap-4">
                        <PrimaryButton disabled={processing}>Create match</PrimaryButton>
                        <Link
                            href="/alignments"
                            className="font-sans text-sm text-[var(--wbench-ink-soft)] dark:text-[var(--wbench-ink-soft-night)] hover:text-[var(--wbench-ink)] dark:hover:text-[var(--wbench-ink-night)]"
                        >
                            Cancel
                        </Link>
                    </div>
                </form>
            </div>
        </div>
    );
}

Create.layout = (page) => <Main children={page}/>;