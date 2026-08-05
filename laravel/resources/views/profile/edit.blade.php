<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-1">
            <span class="font-serif italic text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)] text-xs tracking-[0.22em] uppercase">
                {{ __('Account') }}
            </span>
            <h2 class="font-serif text-2xl sm:text-3xl tracking-tight text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]">
                {{ __('Profile') }}
            </h2>
        </div>
    </x-slot>

    <div class="py-10 sm:py-14">
        <div class="px-4 sm:px-6 lg:px-10 max-w-3xl mx-auto space-y-8">
            <section class="border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] bg-[var(--color-vellum)] dark:bg-[var(--color-ink-night)] rounded-sm p-4 sm:p-8">
                <div class="max-w-xl">
                    @include('profile.partials.update-profile-information-form')
                </div>
            </section>

            <section class="border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] bg-[var(--color-vellum)] dark:bg-[var(--color-ink-night)] rounded-sm p-4 sm:p-8">
                <div class="max-w-xl">
                    @include('profile.partials.update-password-form')
                </div>
            </section>

            <section class="border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] bg-[var(--color-vellum)] dark:bg-[var(--color-ink-night)] rounded-sm p-4 sm:p-8">
                <div class="max-w-xl">
                    @include('profile.partials.delete-user-form')
                </div>
            </section>
        </div>
    </div>
</x-app-layout>