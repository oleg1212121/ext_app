@props(['active'])

@php
$classes = ($active ?? false)
            ? 'relative inline-flex items-center px-2 py-2 text-sm font-medium tracking-wide text-[var(--color-ink)] dark:text-[var(--color-vellum-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)] rounded-sm transition-colors duration-200'
            : 'relative inline-flex items-center px-2 py-2 text-sm font-medium tracking-wide text-[var(--color-ink-soft)] dark:text-[var(--color-vellum-night)]/60 hover:text-[var(--color-ink)] dark:hover:text-[var(--color-vellum-night)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-vermilion)] rounded-sm transition-colors duration-200';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
    <span aria-hidden="true" class="absolute left-1 right-1 -bottom-px h-px bg-[var(--color-vermilion)] dark:bg-[var(--color-vermilion-night)] transition-transform duration-300 origin-left {{ ($active ?? false) ? 'scale-x-100' : 'scale-x-0' }}" style="transform-origin: left center;"></span>
</a>