@props(['active'])

@php
$classes = ($active ?? false)
            ? 'group relative block w-full ps-3 pe-4 py-2.5 text-start text-base font-serif tracking-tight text-[var(--color-vermilion)] dark:text-[var(--color-vermilion-night)] focus:outline-none focus:text-[var(--color-vermilion)] dark:focus:text-[var(--color-vermilion-night)] transition-colors duration-200'
            : 'group relative block w-full ps-3 pe-4 py-2.5 text-start text-base font-serif tracking-tight text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]/80 hover:text-[var(--color-vermilion)] dark:hover:text-[var(--color-vermilion-night)] focus:outline-none focus:text-[var(--color-vermilion)] dark:focus:text-[var(--color-vermilion-night)] transition-colors duration-200';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    <span aria-hidden="true" class="absolute left-0 top-1/2 -translate-y-1/2 self-stretch w-[3px] bg-[var(--color-vermilion)] dark:bg-[var(--color-vermilion-night)] transition-all duration-200 {{ ($active ?? false) ? 'opacity-100 h-8' : 'opacity-0 h-5 group-hover:opacity-60 group-hover:translate-x-0' }}"></span>
    {{ $slot }}
</a>