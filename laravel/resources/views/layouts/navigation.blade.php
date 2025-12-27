<nav class="border-b dark:bg-gray-800 dark:border-gray-700" x-data="{ darkMode: localStorage.getItem('darkMode') === 'true' }" x-init="$watch('darkMode', val => { localStorage.setItem('darkMode', val); if(val) { document.documentElement.classList.add('dark') } else { document.documentElement.classList.remove('dark') } }); if(darkMode) { document.documentElement.classList.add('dark') }">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-16">
            <div class="flex items-center">
                <!-- Logo -->
                <div class="shrink-0">
                    <a href="{{ Auth::check() ? route('dashboard') : url('/') }}">
                        <x-application-logo class="block h-8 w-auto fill-current text-gray-900 dark:text-gray-100" />
                    </a>
                </div>

                <!-- Navigation Links -->
                @auth
                    <div class="flex space-x-6 ml-8">
                        <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                            {{ __('Dashboard') }}
                        </x-nav-link>
                        <x-nav-link :href="route('crossword')" :active="request()->routeIs('crossword')">
                            {{ __('Crossword') }}
                        </x-nav-link>
                        <x-nav-link :href="route('reader')" :active="request()->routeIs('reader')">
                            {{ __('Reader') }}
                        </x-nav-link>
                        <x-nav-link :href="route('bilinguals.simulator')" :active="request()->routeIs('bilinguals.simulator')">
                            {{ __('Bilinguals') }}
                        </x-nav-link>
                    </div>
                @endauth
            </div>

            <!-- Settings Dropdown -->
            <div class="flex items-center gap-4">
                <!-- Theme Toggle -->
                <button @click="darkMode = !darkMode" class="p-2 rounded-lg bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 transition hover:cursor-pointer" title="Toggle dark mode">
                    <!-- Sun Icon (shown in dark mode) -->
                    <svg x-show="darkMode" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-yellow-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                    <!-- Moon Icon (shown in light mode) -->
                    <svg x-show="!darkMode" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                    </svg>
                </button>

                @auth
                    <x-dropdown align="right" width="48">
                        <x-slot name="trigger">
                            <button class="flex items-center text-sm text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-gray-100 hover:cursor-pointer">
                                <span>{{ Auth::user()->name }}</span>
                                <svg class="ml-1 h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </button>
                        </x-slot>

                        <x-slot name="content">
                            <x-dropdown-link :href="route('profile.edit')">
                                {{ __('Profile') }}
                            </x-dropdown-link>

                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <x-dropdown-link :href="route('logout')"
                                        onclick="event.preventDefault();
                                                    this.closest('form').submit();">
                                    {{ __('Log Out') }}
                                </x-dropdown-link>
                            </form>
                        </x-slot>
                    </x-dropdown>
                @else
                    <div class="flex items-center space-x-4">
                        <a href="{{ route('login') }}" class="text-sm text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-gray-100">
                            {{ __('Log in') }}
                        </a>
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="text-sm text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-gray-100">
                                {{ __('Register') }}
                            </a>
                        @endif
                    </div>
                @endauth
            </div>
        </div>
    </div>
</nav>
