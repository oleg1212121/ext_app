import React, {useEffect, useState} from 'react'
import {useForm, Head, Link} from '@inertiajs/react'
import Main from '../../Layouts/Main.jsx'

function SectionHeader({eyebrow, title, description, danger = false}) {
    return (
        <header className="flex flex-col gap-1">
            <span
                className={`font-serif italic text-[10px] tracking-[0.22em] uppercase ${
                    danger
                        ? 'text-[var(--color-vermilion)] dark:text-[var(--color-vermilion-night)]'
                        : 'text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)]'
                }`}
            >
                {eyebrow}
            </span>
            <h2 className="font-serif text-xl sm:text-2xl tracking-tight text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]">
                {title}
            </h2>
            {description && (
                <p className="mt-2 max-w-md font-serif italic text-sm text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70">
                    {description}
                </p>
            )}
        </header>
    )
}

function InputLabel({htmlFor, children}) {
    return (
        <label
            htmlFor={htmlFor}
            className="block text-sm font-medium text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]"
        >
            {children}
        </label>
    )
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
                'bg-[var(--color-vellum)] dark:bg-[var(--color-ink-night)]',
                'text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]',
                'placeholder:text-[var(--color-ink-soft)]/50 dark:placeholder:text-[var(--color-vellum-night)]/40',
                error
                    ? 'border-[var(--color-vermilion)] dark:border-[var(--color-vermilion-night)]'
                    : 'border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)]',
                'focus:outline-none focus:ring-2 focus:ring-[var(--color-vermilion)] dark:focus:ring-[var(--color-vermilion-night)] focus:border-transparent',
            ].join(' ')}
            {...props}
        />
    )
}

function InputError({messages = []}) {
    if (!messages.length) return null
    return (
        <p className="mt-2 text-sm text-[var(--color-vermilion)] dark:text-[var(--color-vermilion-night)]">
            {messages.join(' ')}
        </p>
    )
}

function PrimaryButton({children, disabled = false, className = ''}) {
    return (
        <button
            type="submit"
            disabled={disabled}
            className={[
                'inline-flex items-center px-4 py-2 rounded-sm text-sm font-medium transition-colors',
                'bg-[var(--color-vermilion)] dark:bg-[var(--color-vermilion-night)]',
                'text-white dark:text-[var(--color-ink-night)]',
                'hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)] dark:focus-visible:ring-[var(--color-vermilion-night)]',
                'disabled:opacity-50',
                className,
            ].join(' ')}
        >
            {children}
        </button>
    )
}

function DangerButton({children, onClick, type = 'button', className = ''}) {
    return (
        <button
            type={type}
            onClick={onClick}
            className={[
                'inline-flex items-center px-4 py-2 rounded-sm text-sm font-medium transition-colors',
                'bg-[var(--color-vermilion)] dark:bg-[var(--color-vermilion-night)]',
                'text-white dark:text-[var(--color-ink-night)]',
                'hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)] dark:focus-visible:ring-[var(--color-vermilion-night)]',
                className,
            ].join(' ')}
        >
            {children}
        </button>
    )
}

function SecondaryButton({children, onClick, type = 'button'}) {
    return (
        <button
            type={type}
            onClick={onClick}
            className={[
                'inline-flex items-center px-4 py-2 rounded-sm text-sm font-medium transition-colors',
                'bg-[var(--color-vellum-deep)] dark:bg-[var(--color-hairline-night)]',
                'text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]',
                'hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)] dark:focus-visible:ring-[var(--color-vermilion-night)]',
            ].join(' ')}
        >
            {children}
        </button>
    )
}

function SavedMessage({show}) {
    const [visible, setVisible] = useState(show)

    useEffect(() => {
        if (show) {
            setVisible(true)
            const timer = setTimeout(() => setVisible(false), 2000)
            return () => clearTimeout(timer)
        }
    }, [show])

    if (!visible) return null

    return (
        <p className="font-serif italic text-sm text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)]">
            Saved.
        </p>
    )
}

function Modal({show, onClose, children}) {
    useEffect(() => {
        if (show) {
            document.body.style.overflow = 'hidden'
            return () => { document.body.style.overflow = '' }
        }
    }, [show])

    useEffect(() => {
        const onKey = (e) => {
            if (e.key === 'Escape') onClose()
        }
        if (show) document.addEventListener('keydown', onKey)
        return () => document.removeEventListener('keydown', onKey)
    }, [show, onClose])

    if (!show) return null

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center">
            <div className="fixed inset-0 bg-black/50" onClick={onClose}/>
            <div className="relative z-10 w-full max-w-lg mx-4 bg-[var(--color-vellum)] dark:bg-[var(--color-ink-night)] border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] shadow-lg rounded-sm p-6">
                {children}
            </div>
        </div>
    )
}

function ProfileInformation({user}) {
    const {data, setData, patch, processing, recentlySuccessful, errors} = useForm({
        name: user.name,
        email: user.email,
    })

    const submit = (e) => {
        e.preventDefault()
        patch('/profile')
    }

    return (
        <section>
            <SectionHeader
                eyebrow="Section"
                title="Profile Information"
                description="Update your account's profile information and email address."
            />

            <form onSubmit={submit} className="mt-8 space-y-6">
                <div>
                    <InputLabel htmlFor="name">Name</InputLabel>
                    <TextInput
                        id="name"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        required
                        autoFocus
                        autoComplete="name"
                        error={errors.name}
                    />
                    <InputError messages={errors.name ? [errors.name] : []}/>
                </div>

                <div>
                    <InputLabel htmlFor="email">Email</InputLabel>
                    <TextInput
                        id="email"
                        type="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        required
                        autoComplete="username"
                        error={errors.email}
                    />
                    <InputError messages={errors.email ? [errors.email] : []}/>
                </div>

                <div className="flex items-center gap-4">
                    <PrimaryButton disabled={processing}>Save changes</PrimaryButton>
                    <SavedMessage show={recentlySuccessful}/>
                </div>
            </form>
        </section>
    )
}

function UpdatePassword() {
    const {data, setData, put, processing, recentlySuccessful, errors, reset} = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    })

    const submit = (e) => {
        e.preventDefault()
        put('/password', {
            onFinish: () => reset('current_password', 'password', 'password_confirmation'),
        })
    }

    return (
        <section>
            <SectionHeader
                eyebrow="Section"
                title="Password"
                description="Ensure your account is using a long, random password to stay secure."
            />

            <form onSubmit={submit} className="mt-8 space-y-6">
                <div>
                    <InputLabel htmlFor="update_password_current_password">Current Password</InputLabel>
                    <TextInput
                        id="update_password_current_password"
                        type="password"
                        value={data.current_password}
                        onChange={(e) => setData('current_password', e.target.value)}
                        autoComplete="current-password"
                        error={errors.current_password}
                    />
                    <InputError messages={errors.current_password ? [errors.current_password] : []}/>
                </div>

                <div>
                    <InputLabel htmlFor="update_password_password">New Password</InputLabel>
                    <TextInput
                        id="update_password_password"
                        type="password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        autoComplete="new-password"
                        error={errors.password}
                    />
                    <InputError messages={errors.password ? [errors.password] : []}/>
                </div>

                <div>
                    <InputLabel htmlFor="update_password_password_confirmation">Confirm Password</InputLabel>
                    <TextInput
                        id="update_password_password_confirmation"
                        type="password"
                        value={data.password_confirmation}
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                        autoComplete="new-password"
                        error={errors.password_confirmation}
                    />
                    <InputError messages={errors.password_confirmation ? [errors.password_confirmation] : []}/>
                </div>

                <div className="flex items-center gap-4">
                    <PrimaryButton disabled={processing}>Save changes</PrimaryButton>
                    <SavedMessage show={recentlySuccessful}/>
                </div>
            </form>
        </section>
    )
}

function DeleteAccount() {
    const [showModal, setShowModal] = useState(false)
    const {data, setData, delete: destroy, processing, errors, reset} = useForm({
        password: '',
    })

    const submit = (e) => {
        e.preventDefault()
        destroy('/profile', {
            onFinish: () => {
                reset('password')
                setShowModal(false)
            },
        })
    }

    return (
        <section className="space-y-6">
            <SectionHeader
                eyebrow="Danger zone"
                title="Delete Account"
                description="Once your account is deleted, all of its resources and data will be permanently deleted. Before deleting your account, please download any data or information that you wish to retain."
                danger
            />

            <DangerButton onClick={() => setShowModal(true)}>
                Delete Account
            </DangerButton>

            <Modal show={showModal} onClose={() => setShowModal(false)}>
                <form onSubmit={submit}>
                    <h2 className="font-serif text-xl sm:text-2xl tracking-tight text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]">
                        Are you sure you want to delete your account?
                    </h2>

                    <p className="mt-2 max-w-md font-serif italic text-sm text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70">
                        Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.
                    </p>

                    <div className="mt-6">
                        <InputLabel htmlFor="password">Password</InputLabel>
                        <TextInput
                            id="password"
                            type="password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            placeholder="Password"
                            className="w-3/4"
                            error={errors.password}
                        />
                        <InputError messages={errors.password ? [errors.password] : []}/>
                    </div>

                    <div className="mt-6 flex justify-end gap-3">
                        <SecondaryButton onClick={() => setShowModal(false)}>
                            Cancel
                        </SecondaryButton>
                        <DangerButton type="submit" disabled={processing}>
                            Delete Account
                        </DangerButton>
                    </div>
                </form>
            </Modal>
        </section>
    )
}

function SaveIcon() {
    return (
        <svg className="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
        </svg>
    )
}

function TrashIcon() {
    return (
        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75" aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/>
        </svg>
    )
}

function RemoveKeyButton({onClick, disabled}) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            aria-label="Remove key"
            title="Remove key"
            className={[
                'inline-flex items-center justify-center rounded-sm p-2 transition-colors',
                'text-[var(--color-ink-soft)]/70 dark:text-[var(--color-vellum-night)]/60',
                'hover:text-[var(--color-vermilion)] hover:bg-[var(--color-vermilion)]/10',
                'dark:hover:text-[var(--color-vermilion-night)] dark:hover:bg-[var(--color-vermilion-night)]/10',
                'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)] dark:focus-visible:ring-[var(--color-vermilion-night)]',
                'disabled:opacity-50 disabled:pointer-events-none',
            ].join(' ')}
        >
            <TrashIcon/>
        </button>
    )
}

function ProviderKeyRow({provider}) {
    const {data, setData, post, processing, errors, reset, delete: destroy} = useForm({
        provider: provider.key,
        api_key: '',
    })

    const submit = (e) => {
        e.preventDefault()
        post('/profile/api-keys', {
            preserveScroll: true,
            onSuccess: () => reset('api_key'),
        })
    }

    const remove = () => {
        destroy('/profile/api-keys/' + provider.key, {preserveScroll: true})
    }

    if (provider.has_key) {
        return (
            <div>
                <InputLabel htmlFor={`stored-key-${provider.key}`}>{provider.name}</InputLabel>
                <div className="mt-1 flex items-center justify-between gap-4">
                    <p className="inline-flex flex-wrap items-center gap-x-2 rounded-sm bg-[var(--color-verdigris)]/10 px-2.5 py-1 font-serif italic text-sm text-[var(--color-verdigris)] dark:bg-[var(--color-verdigris-night)]/10 dark:text-[var(--color-verdigris-night)]">
                        <SaveIcon/>
                        <span>
                            Current key: <code className="not-italic">{provider.masked_key}</code>
                        </span>
                    </p>
                    <RemoveKeyButton onClick={remove} disabled={processing}/>
                </div>
            </div>
        )
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-2 sm:flex-row sm:items-end sm:gap-4">
            <div className="flex-1">
                <InputLabel htmlFor={`api-key-${provider.key}`}>{provider.name}</InputLabel>
                <TextInput
                    id={`api-key-${provider.key}`}
                    type="password"
                    value={data.api_key}
                    onChange={(e) => setData('api_key', e.target.value)}
                    placeholder="Paste your API key"
                    autoComplete="off"
                    error={errors.api_key}
                />
                <InputError messages={errors.api_key ? [errors.api_key] : []}/>
            </div>
            <div className="flex items-center gap-3">
                <PrimaryButton disabled={processing || data.api_key === ''}>
                    <span className="flex items-center gap-2">
                        <SaveIcon/>
                        Save
                    </span>
                </PrimaryButton>
            </div>
        </form>
    )
}

function ApiKeys({providers = []}) {
    return (
        <section>
            <SectionHeader
                eyebrow="Section"
                title="AI Provider API Keys"
                description="Add your own API keys to use AI providers in the simulator. Keys are encrypted; only the first and last four characters are shown back to you for identification. Requests are billed to your own provider account."
            />

            <div className="mt-8 space-y-6">
                {providers.map((provider) => (
                    <ProviderKeyRow key={provider.key} provider={provider}/>
                ))}
            </div>
        </section>
    )
}

function Edit({user, apiKeyProviders = []}) {
    return (
        <>
            <Head title="Profile"/>

            <div className="py-10 sm:py-14 overflow-y-auto flex-1">
                <div className="px-4 sm:px-6 lg:px-10 max-w-3xl mx-auto">
                    <div className="flex flex-col gap-1 mb-8">
                        <span className="font-serif italic text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)] text-xs tracking-[0.22em] uppercase">
                            Account
                        </span>
                        <h1 className="font-serif text-2xl sm:text-3xl tracking-tight text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]">
                            Profile
                        </h1>
                    </div>

                    <div className="space-y-8">
                        <div className="border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] bg-[var(--color-vellum)] dark:bg-[var(--color-ink-night)] rounded-sm p-4 sm:p-8">
                            <div className="max-w-xl">
                                <ProfileInformation user={user}/>
                            </div>
                        </div>

                        <div className="border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] bg-[var(--color-vellum)] dark:bg-[var(--color-ink-night)] rounded-sm p-4 sm:p-8">
                            <div className="max-w-xl">
                                <UpdatePassword/>
                            </div>
                        </div>

                        <div className="border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] bg-[var(--color-vellum)] dark:bg-[var(--color-ink-night)] rounded-sm p-4 sm:p-8">
                            <div className="max-w-xl">
                                <ApiKeys providers={apiKeyProviders}/>
                            </div>
                        </div>

                        <div className="border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] bg-[var(--color-vellum)] dark:bg-[var(--color-ink-night)] rounded-sm p-4 sm:p-8">
                            <div className="max-w-xl">
                                <DeleteAccount/>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </>
    )
}

Edit.layout = (page) => <Main children={page}/>
export default Edit
