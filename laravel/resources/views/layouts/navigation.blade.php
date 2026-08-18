<nav class="sticky top-0 z-40 bg-[var(--color-vellum)] dark:bg-[var(--color-ink-night)] border-b border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)]"
     x-data="{ darkMode: localStorage.getItem('darkMode') === 'true', open: false }"
     x-init="$watch('darkMode', val => { localStorage.setItem('darkMode', val); if(val) { document.documentElement.classList.add('dark') } else { document.documentElement.classList.remove('dark') } }); if(darkMode) { document.documentElement.classList.add('dark') }">
    <div class="px-4 sm:px-6 lg:px-10">
        <div class="flex items-center justify-between h-14 sm:h-16">

            <div class="flex items-center gap-8">
                <a href="{{ url('/') }}" class="group leading-none">
                    <span class="font-serif text-lg sm:text-xl tracking-tight text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]">
                        Abibook
                    </span>
                </a>

                @auth
                    @if(Auth::user()->is_approved)
                    <div class="hidden md:flex items-end gap-1 border-b border-transparent">
                        <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                            {{ __('Dashboard') }}
                        </x-nav-link>
                        <div x-data="{ open: false }" @mouseleave="open = false" class="relative">
                            <button
                                @click="open = !open"
                                @mouseenter="open = true"
                                class="relative inline-flex items-center px-2 py-2 text-sm font-medium tracking-wide transition-colors duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)] rounded-sm {{ request()->routeIs('crossword') ? 'text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]' : 'text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/60 hover:text-[var(--color-ink)] dark:hover:text-[var(--color-vellum-night)]' }}"
                                aria-haspopup="menu"
                                :aria-expanded="open.toString()">
                                {{ __('Puzzles') }}
                                <svg class="ml-0.5 h-3 w-3 opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                </svg>
                                <span aria-hidden="true" class="absolute left-1 right-1 -bottom-px h-px bg-[var(--color-vermilion)] dark:bg-[var(--color-vermilion-night)] transition-transform duration-300 origin-left {{ request()->routeIs('crossword') ? 'scale-x-100' : 'scale-x-0' }}" style="transform-origin: left center;"></span>
                            </button>
                            <div
                                x-show="open"
                                x-transition:enter="transition ease-out duration-150"
                                x-transition:enter-start="opacity-0 -translate-y-1"
                                x-transition:enter-end="opacity-100 translate-y-0"
                                x-transition:leave="transition ease-in duration-100"
                                x-transition:leave-start="opacity-100 translate-y-0"
                                x-transition:leave-end="opacity-0 -translate-y-1"
                                @click="open = false"
                                class="absolute left-0 mt-2 w-48 bg-[var(--color-vellum)] dark:bg-[var(--color-ink-night)] border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] shadow-lg overflow-hidden"
                                style="display: none;"
                                role="menu">
                                <x-nav-link :href="route('crossword')" :active="request()->routeIs('crossword')" class="block px-4 py-2.5 text-sm rounded-none">
                                    {{ __('Crossword') }}
                                </x-nav-link>
                            </div>
                        </div>
                        <x-nav-link :href="route('reader')" :active="request()->routeIs('reader')">
                            {{ __('Reader') }}
                        </x-nav-link>
                        <x-nav-link :href="route('bilinguals.simulator')" :active="request()->routeIs('bilinguals.simulator')">
                            {{ __('Bilinguals') }}
                        </x-nav-link>
                        <x-nav-link :href="route('alignments.index')" :active="request()->routeIs('alignments.*')">
                            {{ __('Alignments') }}
                        </x-nav-link>
                        @if(Auth::user()->isAdmin())
                        <x-nav-link href="/admin">
                            {{ __('Admin') }}
                        </x-nav-link>
                        @endif
                    </div>
                    @endif
                @endauth
            </div>

            <div class="flex items-center gap-3 sm:gap-4">
                <button @click="darkMode = !darkMode"
                        class="p-2 rounded-sm text-[var(--color-ink)] dark:text-[var(--color-vellum-night)] hover:text-[var(--color-vermilion)] dark:hover:text-[var(--color-vermilion-night)] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)] cursor-pointer"
                        title="Toggle dark mode"
                        aria-label="Toggle dark mode">
                    <svg x-show="darkMode" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                    <svg x-show="!darkMode" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                    </svg>
                </button>

                @auth
                    <x-dropdown align="right" width="48">
                        <x-slot name="trigger">
                            <button class="flex items-center gap-2 text-sm text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70 hover:text-[var(--color-ink)] dark:hover:text-[var(--color-vellum-night)] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)] rounded-sm px-1 cursor-pointer"
                                    aria-haspopup="menu">
                                <span class="hidden sm:inline font-serif italic">{{ Auth::user()->name }}</span>
                                <span class="sm:hidden font-serif italic">{{ mb_substr(Auth::user()->name, 0, 1) }}</span>
                                <svg class="h-3.5 w-3.5 opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>
                        </x-slot>

                        <x-slot name="content">
                            <x-dropdown-link :href="route('profile.edit')">
                                {{ __('Profile') }}
                            </x-dropdown-link>

                            <div class="block h-px bg-[var(--color-hairline)] dark:bg-[var(--color-hairline-night)]"></div>

                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <x-dropdown-link :href="route('logout')"
                                        onclick="event.preventDefault();
                                                    this.closest('form').submit();">
                                    {{ __('Log out') }}
                                </x-dropdown-link>
                            </form>
                        </x-slot>
                    </x-dropdown>
                @else
                    <div class="hidden sm:flex items-center gap-4">
                        <a href="{{ route('login') }}" class="text-sm text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70 hover:text-[var(--color-ink)] dark:hover:text-[var(--color-vellum-night)] transition-colors">
                            {{ __('Log in') }}
                        </a>
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="text-sm font-medium text-[var(--color-vermilion)] dark:text-[var(--color-vermilion-night)] hover:text-[var(--color-ink)] dark:hover:text-[var(--color-vellum-night)] transition-colors">
                                {{ __('Register') }}
                            </a>
                        @endif
                    </div>
                @endauth

                @auth
                    <button @click="open = ! open"
                            class="md:hidden inline-flex items-center justify-center h-9 w-9 text-[var(--color-ink)] dark:text-[var(--color-vellum-night)] hover:text-[var(--color-vermilion)] dark:hover:text-[var(--color-vermilion-night)] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)] rounded-sm"
                            aria-label="Toggle menu"
                            :aria-expanded="open.toString()">
                        <svg x-show="!open" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                        <svg x-show="open" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                @endauth
            </div>
        </div>

        @auth
            @if(Auth::user()->is_approved)
            <div x-show="open"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 -translate-y-2"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100 translate-y-0"
                 x-transition:leave-end="opacity-0 -translate-y-2"
                 class="md:hidden border-t border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] bg-[var(--color-vellum)] dark:bg-[var(--color-ink-night)]"
                 style="display: none;">
                <ul role="list" class="px-4 sm:px-6 py-2 divide-y divide-[var(--color-hairline)] dark:divide-[var(--color-hairline-night)]">
                    <li>
                        <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                            {{ __('Dashboard') }}
                        </x-responsive-nav-link>
                    </li>
                    <li x-data="{ expanded: {{ request()->routeIs('crossword') ? 'true' : 'false' }} }">
                        <button
                            @click="expanded = !expanded"
                            class="w-full flex items-center justify-between py-3 ps-3 pe-4 font-serif text-lg tracking-tight transition-colors {{ request()->routeIs('crossword') ? 'text-[var(--color-vermilion)] dark:text-[var(--color-vermilion-night)]' : 'text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]/80 hover:text-[var(--color-vermilion)] dark:hover:text-[var(--color-vermilion-night)]' }}">
                            {{ __('Puzzles') }}
                            <svg
                                class="h-4 w-4 opacity-60 transition-transform duration-200"
                                :class="expanded ? 'rotate-180' : ''"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <ul x-show="expanded" x-collapse class="pl-4 pb-1 divide-y divide-[var(--color-hairline)]/50 dark:divide-[var(--color-hairline-night)]/50">
                            <li>
                                <x-responsive-nav-link :href="route('crossword')" :active="request()->routeIs('crossword')" class="ps-2">
                                    {{ __('Crossword') }}
                                </x-responsive-nav-link>
                            </li>
                        </ul>
                    </li>
                    <li>
                        <x-responsive-nav-link :href="route('reader')" :active="request()->routeIs('reader')">
                            {{ __('Reader') }}
                        </x-responsive-nav-link>
                    </li>
                    <li>
                        <x-responsive-nav-link :href="route('bilinguals.simulator')" :active="request()->routeIs('bilinguals.simulator')">
                            {{ __('Bilinguals') }}
                        </x-responsive-nav-link>
                    </li>
                    <li>
                        <x-responsive-nav-link :href="route('alignments.index')" :active="request()->routeIs('alignments.*')">
                            {{ __('Alignments') }}
                        </x-responsive-nav-link>
                    </li>
                    @if(Auth::user()->isAdmin())
                    <li>
                        <x-responsive-nav-link href="/admin">
                            {{ __('Admin') }}
                        </x-responsive-nav-link>
                    </li>
                    @endif
                </ul>
            </div>
            @endif
        @endauth
    </div>
</nav>