@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'font-serif italic text-sm text-[var(--color-verdigris)] dark:text-[var(--color-verdigris-night)]']) }}>
        {{ $status }}
    </div>
@endif