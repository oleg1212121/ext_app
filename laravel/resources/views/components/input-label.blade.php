@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-medium text-sm font-serif tracking-tight text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]']) }}>
    {{ $value ?? $slot }}
</label>