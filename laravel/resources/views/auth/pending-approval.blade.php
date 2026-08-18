<x-app-layout>
    <div class="flex-1 flex items-center justify-center py-12 sm:py-20">
        <div class="mx-auto max-w-md px-4 sm:px-6 lg:px-8 text-center">
            <div class="border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] bg-[var(--color-vellum)] dark:bg-[var(--color-ink-night)] rounded-sm p-8 sm:p-12">
                <div class="flex justify-center mb-6">
                    <div class="h-12 w-12 rounded-full bg-[var(--color-vermilion)]/10 dark:bg-[var(--color-vermilion-night)]/10 flex items-center justify-center">
                        <svg class="h-6 w-6 text-[var(--color-vermilion)] dark:text-[var(--color-vermilion-night)]" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                        </svg>
                    </div>
                </div>

                <h1 class="font-serif text-2xl sm:text-3xl tracking-tight text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]">
                    Account Pending Approval
                </h1>

                <p class="mt-4 font-sans text-sm leading-relaxed text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70">
                    Your account is being reviewed by an administrator. You will have full access to the platform once your account is approved.
                </p>

                <div class="mt-8">
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button
                            type="submit"
                            class="inline-flex items-center px-4 py-2 border border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] rounded-sm font-sans text-sm text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/70 hover:text-[var(--color-vermilion)] dark:hover:text-[var(--color-vermilion-night)] hover:border-[var(--color-vermilion)] dark:hover:border-[var(--color-vermilion-night)] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)]"
                        >
                            Log out
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
