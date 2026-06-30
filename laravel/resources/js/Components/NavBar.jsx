import {Link, router, usePage} from '@inertiajs/react'
import {useEffect, useMemo, useState} from 'react'
import {DarkThemeToggle} from "flowbite-react";

export default function NavBar() {
    const {url, props} = usePage()

    const auth = props?.auth ?? {}
    const user = auth?.user ?? null
    const isAuthenticated = !!user
    const canRegister = auth?.canRegister ?? true

    const [darkMode, setDarkMode] = useState(() => {
        if (typeof window === 'undefined') return false
        return localStorage.getItem('darkMode') === 'true'
    })

    useEffect(() => {
        if (typeof window === 'undefined') return
        localStorage.setItem('darkMode', String(darkMode))
        document.documentElement.classList.toggle('dark', darkMode)
    }, [darkMode])

    const [userMenuOpen, setUserMenuOpen] = useState(false)

    useEffect(() => {
        const onDocClick = (e) => {
            if (!e.target.closest?.('[data-user-menu]')) {
                setUserMenuOpen(false)
            }
        }
        document.addEventListener('click', onDocClick)
        return () => document.removeEventListener('click', onDocClick)
    }, [])

    const navLinks = useMemo(() => {
        if (!isAuthenticated) return []
        return [
            // {href: '/dashboard', label: 'Dashboard'},
            // {href: '/crossword', label: 'Crossword'},
            // {href: '/reader', label: 'Reader'},
            // {href: '/alignments', label: 'Alignments'},
            {href: '/bilinguals/en/ru/simulator', label: 'Bilinguals'},
            {href: '/admin/sentence-alignments', label: 'Alignments'},
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

    return (
        <nav className="border-b bg-orange-100 dark:bg-gray-800 dark:border-gray-700">
            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="flex justify-between items-center h-16">
                    <div className="flex items-center">
                        <div className="shrink-0">
                            <Link href={logoHref} className="flex items-center gap-2">
                <span className="font-semibold text-gray-900 dark:text-gray-100">
                  {props?.appName ?? 'Laravel'}
                </span>
                            </Link>
                        </div>

                        {isAuthenticated && (
                            <div className="flex space-x-6 ml-8">
                                {navLinks.map((l) => (
                                    <Link
                                        key={l.href}
                                        href={l.href}
                                        className={[
                                            'inline-flex items-center border-b-2 px-1 pt-1 text-sm font-medium transition',
                                            isActive(l.href)
                                                ? 'border-indigo-400 text-gray-900 dark:text-gray-100'
                                                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200',
                                        ].join(' ')}
                                    >
                                        {l.label}
                                    </Link>
                                ))}
                            </div>
                        )}
                    </div>

                    <div className="flex items-center gap-4">
                        {/*<button*/}
                        {/*    type="button"*/}
                        {/*    onClick={() => setDarkMode((v) => !v)}*/}
                        {/*    className="p-2 rounded-lg bg-orange-200 border-2 border-dashed dark:bg-gray-700 dark:hover:bg-gray-600 transition hover:cursor-pointer"*/}
                        {/*    title="Toggle dark mode"*/}
                        {/*>*/}
                        {/*{darkMode ? (*/}
                        {/*    <svg*/}
                        {/*        xmlns="http://www.w3.org/2000/svg"*/}
                        {/*        className="h-5 w-5 text-yellow-400"*/}
                        {/*        fill="none"*/}
                        {/*        viewBox="0 0 24 24"*/}
                        {/*        stroke="currentColor"*/}
                        {/*    >*/}
                        {/*        <path*/}
                        {/*            strokeLinecap="round"*/}
                        {/*            strokeLinejoin="round"*/}
                        {/*            strokeWidth="2"*/}
                        {/*            d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"*/}
                        {/*        />*/}
                        {/*    </svg>*/}
                        {/*) : (*/}
                        {/*    <svg*/}
                        {/*        xmlns="http://www.w3.org/2000/svg"*/}
                        {/*        className="h-5 w-5 text-gray-700"*/}
                        {/*        fill="none"*/}
                        {/*        viewBox="0 0 24 24"*/}
                        {/*        stroke="currentColor"*/}
                        {/*    >*/}
                        {/*        <path*/}
                        {/*            strokeLinecap="round"*/}
                        {/*            strokeLinejoin="round"*/}
                        {/*            strokeWidth="2"*/}
                        {/*            d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"*/}
                        {/*        />*/}
                        {/*    </svg>*/}
                        {/*)}*/}
                        <DarkThemeToggle/>
                        {/*</button>*/}

                        {isAuthenticated ? (
                            <div className="relative" data-user-menu>
                                <button
                                    type="button"
                                    onClick={() => setUserMenuOpen((v) => !v)}
                                    className="flex items-center text-sm text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-gray-100 hover:cursor-pointer"
                                >
                                    <span>{user?.name ?? 'User'}</span>
                                    <svg className="ml-1 h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                                        <path
                                            fillRule="evenodd"
                                            d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                            clipRule="evenodd"
                                        />
                                    </svg>
                                </button>

                                {userMenuOpen && (
                                    <div
                                        className="absolute right-0 mt-2 w-48 rounded-md bg-white dark:bg-gray-800 shadow ring-1 ring-black/5 overflow-hidden">
                                        <Link
                                            href="/profile"
                                            className="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700"
                                        >
                                            Profile
                                        </Link>
                                        <button
                                            type="button"
                                            onClick={() => router.post('/logout')}
                                            className="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700"
                                        >
                                            Log Out
                                        </button>
                                    </div>
                                )}
                            </div>
                        ) : (
                            <div className="flex items-center space-x-4">
                                <Link
                                    href="/login"
                                    className="text-sm text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-gray-100"
                                >
                                    Log in
                                </Link>

                                {canRegister && (
                                    <Link
                                        href="/register"
                                        className="text-sm text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-gray-100"
                                    >
                                        Register
                                    </Link>
                                )}
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </nav>
    )
}
