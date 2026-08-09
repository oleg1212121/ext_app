import {Link, router, usePage} from '@inertiajs/react'
import {useEffect, useMemo, useState} from 'react'
import {DarkThemeToggle} from "flowbite-react";

const tabClass = (isActive) => [
    'relative inline-flex items-center px-2 py-2 text-sm font-medium tracking-wide transition-colors duration-200',
    'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)] rounded-sm',
    isActive
        ? 'text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]'
        : 'text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/60 hover:text-[var(--color-ink)] dark:hover:text-[var(--color-vellum-night)]',
].join(' ');

const Underline = ({isActive}) => (
    <span
        aria-hidden="true"
        className={[
            'absolute left-1 right-1 -bottom-px h-px bg-[var(--color-vermilion)]',
            'dark:bg-[var(--color-vermilion-night)] transition-transform duration-300 origin-left',
            isActive ? 'scale-x-100' : 'scale-x-0',
        ].join(' ')}
        style={{transformOrigin: 'left center'}}
    />
);

export default function NavBar() {
    const {url, props} = usePage()

    const auth = props?.auth ?? {}
    const user = auth?.user ?? null
    const isAuthenticated = !!user
    const canRegister = auth?.canRegister ?? true

    const [userMenuOpen, setUserMenuOpen] = useState(false)
    const [mobileOpen, setMobileOpen] = useState(false)

    useEffect(() => {
        const onDocClick = (e) => {
            if (!e.target.closest?.('[data-user-menu]')) {
                setUserMenuOpen(false)
            }
            if (!e.target.closest?.('[data-mobile-menu]')) {
                setMobileOpen(false)
            }
        }
        document.addEventListener('click', onDocClick)
        return () => document.removeEventListener('click', onDocClick)
    }, [])

    useEffect(() => {
        setMobileOpen(false)
        setUserMenuOpen(false)
    }, [url])

    const navLinks = useMemo(() => {
        if (!isAuthenticated) return []
        return [
            {href: '/bilinguals/en/ru/simulator', label: 'Bilinguals'},
            {href: '/alignments', label: 'Alignments'},
            {href: '/crossword-react/en', label: 'Crossword'},
            {href: '/reader-react', label: 'Reader'},
        ]
    }, [isAuthenticated])

    const isActive = (href) => {
        if (!url) return false
        if (href === '/dashboard') return url === '/dashboard'
        return url === href || url.startsWith(`${href}/`)
    }

    const logoHref = isAuthenticated ? '/dashboard' : '/'
    const brand = props?.appName ?? 'Bilingual'
    const eyebrow = 'Antiphonal'

    return (
        <nav className="sticky top-0 z-40 bg-[var(--color-vellum)] dark:bg-[var(--color-ink-night)] border-b border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)]">
            <div className="px-4 sm:px-6 lg:px-10">
                <div className="flex items-center justify-between h-14 sm:h-16">
                    <div className="flex items-center gap-8">
                        <Link href={logoHref} className="group flex flex-col leading-none">
                            <span className="font-serif italic text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)] text-[10px] tracking-[0.22em] uppercase">
                                {eyebrow}
                            </span>
                            <span className="font-serif text-lg sm:text-xl tracking-tight text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]">
                                {brand}
                            </span>
                        </Link>

                        {isAuthenticated && navLinks.length > 0 && (
                            <div className="hidden md:flex items-end gap-1 border-b border-transparent">
                                {navLinks.map((l) => (
                                    <Link
                                        key={l.href}
                                        href={l.href}
                                        className={tabClass(isActive(l.href))}
                                        aria-current={isActive(l.href) ? 'page' : undefined}
                                    >
                                        {l.label}
                                        <Underline isActive={isActive(l.href)}/>
                                    </Link>
                                ))}
                            </div>
                        )}
                    </div>

                    <div className="flex items-center gap-3 sm:gap-4">
                        <DarkThemeToggle/>

                        {isAuthenticated ? (
                            <div className="relative" data-user-menu>
                                <button
                                    type="button"
                                    onClick={() => setUserMenuOpen((v) => !v)}
                                    className="flex items-center gap-2 text-sm text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70 hover:text-[var(--color-ink)] dark:hover:text-[var(--color-vellum-night)] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)] rounded-sm px-1"
                                    aria-haspopup="menu"
                                    aria-expanded={userMenuOpen}
                                >
                                    <span className="hidden sm:inline font-serif italic">{user?.name ?? 'User'}</span>
                                    <span className="sm:hidden font-serif italic">{(user?.name ?? 'U').charAt(0)}</span>
                                    <svg className="h-3.5 w-3.5 opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7"/>
                                    </svg>
                                </button>

                                {userMenuOpen && (
                                    <div
                                        role="menu"
                                        className="absolute right-0 mt-2 w-48 bg-[var(--color-vellum)] dark:bg-[var(--color-ink-night)] border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] shadow-lg overflow-hidden">
                                        <Link
                                            href="/profile"
                                            role="menuitem"
                                            className="block px-4 py-2.5 text-sm text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70 hover:bg-[var(--color-vellum-deep)] dark:hover:bg-[var(--color-hairline-night)]/40 hover:text-[var(--color-ink)] dark:hover:text-[var(--color-vellum-night)] transition-colors"
                                        >
                                            Profile
                                        </Link>
                                        <span className="block h-px bg-[var(--color-hairline)] dark:bg-[var(--color-hairline-night)]"/>
                                        <button
                                            type="button"
                                            role="menuitem"
                                            onClick={() => router.post('/logout')}
                                            className="w-full text-left px-4 py-2.5 text-sm text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70 hover:bg-[var(--color-vellum-deep)] dark:hover:bg-[var(--color-hairline-night)]/40 hover:text-[var(--color-vermilion)] dark:hover:text-[var(--color-vermilion-night)] transition-colors"
                                        >
                                            Log out
                                        </button>
                                    </div>
                                )}
                            </div>
                        ) : (
                            <div className="hidden sm:flex items-center gap-4">
                                <Link
                                    href="/login"
                                    className="text-sm text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70 hover:text-[var(--color-ink)] dark:hover:text-[var(--color-vellum-night)] transition-colors"
                                >
                                    Log in
                                </Link>
                                {canRegister && (
                                    <Link
                                        href="/register"
                                        className="text-sm font-medium text-[var(--color-vermilion)] dark:text-[var(--color-vermilion-night)] hover:text-[var(--color-ink)] dark:hover:text-[var(--color-vellum-night)] transition-colors"
                                    >
                                        Register
                                    </Link>
                                )}
                            </div>
                        )}

                        {isAuthenticated && navLinks.length > 0 && (
                            <button
                                type="button"
                                data-mobile-menu
                                onClick={() => setMobileOpen((v) => !v)}
                                className="md:hidden inline-flex items-center justify-center h-9 w-9 text-[var(--color-ink)] dark:text-[var(--color-vellum-night)] hover:text-[var(--color-vermilion)] dark:hover:text-[var(--color-vermilion-night)] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)] rounded-sm"
                                aria-label="Toggle menu"
                                aria-expanded={mobileOpen}
                            >
                                {mobileOpen ? (
                                    <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                ) : (
                                    <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
                                    </svg>
                                )}
                            </button>
                        )}
                    </div>
                </div>
            </div>

            {isAuthenticated && navLinks.length > 0 && mobileOpen && (
                <div
                    data-mobile-menu
                    className="md:hidden border-t border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] bg-[var(--color-vellum)] dark:bg-[var(--color-ink-night)]"
                >
                    <ul role="list" className="px-4 sm:px-6 py-2 divide-y divide-[var(--color-hairline)] dark:divide-[var(--color-hairline-night)]">
                        {navLinks.map((l) => {
                            const active = isActive(l.href)
                            return (
                                <li key={l.href}>
                                    <Link
                                        href={l.href}
                                        aria-current={active ? 'page' : undefined}
                                        className={[
                                            'flex items-center justify-between py-3 font-serif text-lg tracking-tight transition-colors',
                                            active
                                                ? 'text-[var(--color-vermilion)] dark:text-[var(--color-vermilion-night)]'
                                                : 'text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]/80 hover:text-[var(--color-vermilion)] dark:hover:text-[var(--color-vermilion-night)]',
                                        ].join(' ')}
                                    >
                                        {l.label}
                                        <span aria-hidden="true" className="font-serif italic text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)] text-base">
                                            {active ? '·' : '→'}
                                        </span>
                                    </Link>
                                </li>
                            )
                        })}
                    </ul>
                </div>
            )}
        </nav>
    )
}
