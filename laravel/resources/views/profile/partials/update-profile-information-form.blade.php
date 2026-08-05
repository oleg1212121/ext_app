<section>
    <header class="flex flex-col gap-1">
        <span class="font-serif italic text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)] text-[10px] tracking-[0.22em] uppercase">
            {{ __('Section') }}
        </span>
        <h2 class="font-serif text-xl sm:text-2xl tracking-tight text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]">
            {{ __('Profile Information') }}
        </h2>

        <p class="mt-2 max-w-md font-serif italic text-sm text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70">
            {{ __("Update your account's profile information and email address.") }}
        </p>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" class="mt-8 space-y-6">
        @csrf
        @method('patch')

        <div>
            <x-input-label for="name" :value="__('Name')" />
            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $user->name)" required autofocus autocomplete="name" />
            <x-input-error class="mt-2" :messages="$errors->get('name')" />
        </div>

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $user->email)" required autocomplete="username" />
            <x-input-error class="mt-2" :messages="$errors->get('email')" />

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                <div>
                    <p class="text-sm mt-2 font-serif italic text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70">
                        {{ __('Your email address is unverified.') }}

                        <button form="send-verification" class="underline decoration-[var(--color-vermilion)] dark:decoration-[var(--color-vermilion-night)] underline-offset-4 text-sm text-[var(--color-vermilion)] dark:text-[var(--color-vermilion-night)] dark:hover:text-[var(--color-vellum-night)] hover:text-[var(--color-ink)] rounded-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)]">
                            {{ __('Click here to re-send the verification email.') }}
                        </button>
                    </p>

                    @if (session('status') === 'verification-link-sent')
                        <p class="mt-2 font-serif italic text-sm text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)]">
                            {{ __('A new verification link has been sent to your email address.') }}
                        </p>
                    @endif
                </div>
            @endif
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Save changes') }}</x-primary-button>

            @if (session('status') === 'profile-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="font-serif italic text-sm text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)]"
                >{{ __('Saved.') }}</p>
            @endif
        </div>
    </form>
</section>