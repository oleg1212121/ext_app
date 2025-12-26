<nav class="border-b">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-16">
            <div class="flex items-center">
                <!-- Logo -->
                <div class="shrink-0">
                    <a href="{{ Auth::check() ? route('dashboard') : url('/') }}">
                        <x-application-logo class="block h-8 w-auto fill-current text-gray-900" />
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
            <div class="flex items-center">
                @auth
                    <x-dropdown align="right" width="48">
                        <x-slot name="trigger">
                            <button class="flex items-center text-sm text-gray-600 hover:text-gray-900">
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
                        <a href="{{ route('login') }}" class="text-sm text-gray-600 hover:text-gray-900">
                            {{ __('Log in') }}
                        </a>
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="text-sm text-gray-600 hover:text-gray-900">
                                {{ __('Register') }}
                            </a>
                        @endif
                    </div>
                @endauth
            </div>
        </div>
    </div>
</nav>
